<?php
/**
 * KondoManager Auto-Update Engine - v13.2 Bridge (Proxy Aware)
 * QUESTO FILE È GESTITO DA GIT - NON CONTIENE HASH/URL HARDCODED
 * Funziona SOLO in modalità aggiornamento automatico via Laravel bridge
 * Posizione: resources/installer/index.php
 * Attivazione: Copiato in root da UpdateService quando necessario
 * @version 13.2.0
 * @author KondoManager Team
 * @date 11 Febbraio 2026
 */

// ============================================================================
// 1. ENVIRONMENT OPTIMIZATION
// ============================================================================
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { @ob_end_clean(); }
ob_implicit_flush(1);

$nonce = bin2hex(random_bytes(16));

header('Content-Encoding: none');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");

error_reporting(E_ALL);
ini_set('display_errors', 0);
@set_time_limit(900);
ini_set('memory_limit', '512M');

// ============================================================================
// 2. BRIDGE VALIDATION (CRITICAL)
// ============================================================================

function logTech(string $msg) {
    @file_put_contents(__DIR__ . '/install.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

// CRITICAL: This file REQUIRES a bridge to operate
$bridgeFile = __DIR__ . '/update_bridge.json';

if (!file_exists($bridgeFile)) {
    logTech("FATAL: Bridge file missing - cannot proceed");
    http_response_code(503);
    die("
    <!DOCTYPE html>
    <html lang=\"it\">
    <head>
        <meta charset=\"UTF-8\">
        <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
        <title>KondoManager - Bridge required</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .container { background: white; border-radius: 16px; padding: 40px; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); text-align: center; }
            h1 { color: #1f2937; margin: 0 0 16px; font-size: 24px; }
            p { color: #6b7280; line-height: 1.6; margin: 0 0 12px; }
            .icon { font-size: 64px; margin-bottom: 20px; }
            a { color: #3b82f6; text-decoration: none; font-weight: 600; }
            .code { background: #f3f4f6; padding: 8px 12px; border-radius: 6px; font-family: monospace; font-size: 14px; color: #1f2937; }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <div class=\"icon\">⚙️</div>
            <h1>Auto-Update engine</h1>
            <p><strong>Questo file funziona solo in modalità aggiornamento automatico.</strong></p>
            <p class=\"code\">update_bridge.json</p>
            <p>File mancante. Avvia l'aggiornamento dalla <a href=\"/system/upgrade\">dashboard di Kondomanager</a></p>
            <hr style=\"border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;\">
            <p style=\"font-size: 14px; color: #9ca3af;\">Per installazioni nuove, usa il file standalone di setup che puoi scaricare dal sito ufficiale di Kondomanager.</p>
        </div>
    </body>
    </html>
    ");
}

$bridge = json_decode(file_get_contents($bridgeFile), true);

if (empty($bridge['package']['version'])) {
    logTech("FATAL: Invalid bridge structure");
    die("Bridge file corrupted. Regenerate from Laravel.");
}

// ============================================================================
// 3. CONFIGURATION (From Bridge)
// ============================================================================

define('PACKAGE_URL',     $bridge['package']['url']);
define('PACKAGE_HASH',    $bridge['package']['hash']);
define('APP_VERSION',     $bridge['package']['version']);
define('MIN_PHP_VERSION', $bridge['requirements']['php'] ?? '8.4.0');

// Exclude items (preserved on server - NOT touched)
$excludeItems = $bridge['package']['exclude'] ?? [
    '.env',
    'storage',
    'public/uploads',
    'public/storage',
    'install.log',
    'update_bridge.json',
    'bootstrap/cache',
    '_km_safe_zone'
];

define('LOG_FILE', __DIR__ . '/install.log');
define('ZIP_FILE', __DIR__ . '/update_temp.zip');
define('TEMP_DIR', __DIR__ . '/_update_temp_' . time());
define('BACKUP_DIR', __DIR__ . '/_km_safe_zone');

$bridgeToken = $bridge['security']['token'] ?? null;

logTech("=== AUTO-UPDATE ENGINE v13.2 ===");
logTech("Target version: " . APP_VERSION);
logTech("Package URL: " . PACKAGE_URL);
logTech("Package hash: " . substr(PACKAGE_HASH, 0, 16) . '...');
logTech("Excluded items: " . count($excludeItems));

// ============================================================================
// 4. LOCALIZATION
// ============================================================================

$langs = [
    'it' => [
        'title' => 'Aggiornamento sistema',
        'welcome' => 'Aggiornamento in corso',
        'tagline' => 'Versione target: v%s',
        'btn_text' => 'Avvia aggiornamento',
        'step_start' => 'Inizializzazione...',
        'step_down' => 'Download pacchetto v%s...',
        'step_down_prog' => 'Download: %s MB / %s MB',
        'step_hash' => 'Verifica integrità (SHA256)...',
        'step_backup' => 'Backup configurazione (.env)...',
        'step_unzip' => 'Estrazione archivio...',
        'step_install' => 'Applicazione aggiornamento...',
        'step_opcache' => 'Pulizia cache PHP (OPcache)...',
        'step_health' => 'Verifica integrità sistema...',
        'step_clean' => 'Pulizia file temporanei...',
        'step_done' => 'Aggiornamento completato!',
        'step_rollback' => 'ERRORE! Ripristino backup...',
        'err_generic' => 'Errore: %s',
        'err_csrf' => 'Token di sicurezza non valido.',
        'err_php' => 'Richiesto PHP %s o superiore (rilevato %s). Aggiorna PHP dal pannello hosting.',
        'msg_rollback_ok' => 'Backup ripristinato correttamente.',
        'msg_rollback_ko' => 'ATTENZIONE: Rollback fallito. Verifica manualmente.',
    ],
    'en' => [
        'title' => 'System update',
        'welcome' => 'Update in progress',
        'tagline' => 'Target version: v%s',
        'btn_text' => 'Start update',
        'step_start' => 'Initializing...',
        'step_down' => 'Downloading package v%s...',
        'step_down_prog' => 'Download: %s MB / %s MB',
        'step_hash' => 'Verifying integrity (SHA256)...',
        'step_backup' => 'Backing up configuration (.env)...',
        'step_unzip' => 'Extracting archive...',
        'step_install' => 'Applying update...',
        'step_opcache' => 'Clearing PHP cache (OPcache)...',
        'step_health' => 'System integrity check...',
        'step_clean' => 'Cleaning temporary files...',
        'step_done' => 'Update completed!',
        'step_rollback' => 'ERROR! Restoring backup...',
        'err_generic' => 'Error: %s',
        'err_csrf' => 'Invalid security token.',
        'err_php' => 'PHP %s or higher is required (detected %s). Please upgrade PHP.',
        'msg_rollback_ok' => 'Backup restored successfully.',
        'msg_rollback_ko' => 'WARNING: Rollback failed. Check manually.',
    ]
];

function getLang() {
    return substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en', 0, 2) === 'it' ? 'it' : 'en';
}

function t(string $key, mixed ...$args) {
    global $langs;
    $txt = $langs[getLang()][$key] ?? $key;
    if (empty($args)) $args = [APP_VERSION];
    return vsprintf($txt, $args);
}

// ============================================================================
// 5. HELPER FUNCTIONS
// ============================================================================

function safeRmdir(string $dir) {
    if (!is_dir($dir)) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $op = $file->isDir() ? 'rmdir' : 'unlink';
        @$op($file->getRealPath());
    }
    return @rmdir($dir);
}

function performRollback() {
    logTech("ROLLBACK: Initiated");
    if (!is_dir(BACKUP_DIR)) {
        logTech("ROLLBACK: No backup found");
        return false;
    }
    
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BACKUP_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($items as $item) {
        $rel = substr($item->getPathname(), strlen(BACKUP_DIR) + 1);
        $target = __DIR__ . '/' . $rel;
        
        if ($item->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
        } else {
            @copy($item->getPathname(), $target);
        }
    }
    
    logTech("ROLLBACK: Completed");
    return true;
}

function sendProgress(float|int $pct, string $msg, string $status='current', bool $replace=false) {
    global $nonce;
    $pct = round($pct);
    $msg = addslashes($msg);
    $rep = $replace ? 'true' : 'false';
    echo "<script nonce='{$nonce}'>updateProgress({$pct}, '{$msg}', '{$status}', {$rep});</script>";
    echo str_repeat(' ', 4096);
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// FIX: Correzione per hosting con session.gc_divisor = 0
if ((int)ini_get('session.gc_divisor') === 0) {
    @ini_set('session.gc_divisor', 1000);
    @ini_set('session.gc_probability', 1);
}

session_start();

// ============================================================================
// 6. UI (GET Request)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title') ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: white; padding: 2.5rem; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 450px; text-align: center; }
        h2 { color: #1f2937; margin: 0 0 8px; font-size: 24px; }
        .version { color: #3b82f6; font-size: 32px; font-weight: 700; margin: 16px 0 24px; }
        p { color: #6b7280; margin: 0 0 24px; line-height: 1.6; }
        .btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 14px 28px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🚀</div>
        <h2><?= t('welcome') ?></h2>
        <div class="version">v<?= htmlspecialchars(APP_VERSION) ?></div>
        <p><?= sprintf(t('tagline'), APP_VERSION) ?></p>
        <?php if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 24px; font-weight: bold; font-size: 14px;">
                <?= sprintf(t('err_php'), MIN_PHP_VERSION, PHP_VERSION) ?>
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= $_SESSION['token'] ?>">
                <button type="submit" class="btn"><?= t('btn_text') ?></button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
<?php exit; }

// ============================================================================
// 7. EXECUTION (POST Request) - UI AGGIORNATA STYLE V12.4
// ============================================================================
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title') ?></title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f8fafc; color: #334155; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; width: 100%; max-width: 550px; padding: 2.5rem; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .bar-container { background: #f1f5f9; height: 10px; border-radius: 5px; overflow: hidden; margin: 20px 0; }
        .bar-fill { background: #3b82f6; height: 100%; width: 0%; transition: width 0.3s; }
        .bar-fill.error { background: #dc2626; }
        .log { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.85rem; height: 200px; overflow-y: auto; }
        .log-line { margin-bottom: 5px; }
        .ok { color: #4ade80; } .err { color: #f87171; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="text-align:center; margin-top:0"><?= t('welcome') ?></h2>
        <div class="bar-container"><div class="bar-fill" id="bar"></div></div>
        <div class="log" id="log"></div>
    </div>

<script nonce="<?= $nonce ?>">
function updateProgress(p, m, s, replace) {
    document.getElementById('bar').style.width = p + '%';
    const log = document.getElementById('log');
    if (replace && log.lastElementChild) { log.lastElementChild.innerHTML = '• ' + m; }
    else {
        const d = document.createElement('div');
        d.className = 'log-line';
        let icon = s === 'success' ? '<span class="ok">✓</span>' : (s === 'error' ? '<span class="err">✕</span>' : '•');
        d.innerHTML = icon + ' ' + m;
        log.appendChild(d); log.scrollTop = log.scrollHeight;
    }
    if (s === 'error') document.getElementById('bar').classList.add('error');
}
function showFinal(url) {
    const el = document.getElementById('log');
    const d = document.createElement('div');
    d.innerHTML = '<br><strong style="color:#4ade80">REDIRECT...</strong> <a href="'+url+'" style="color:#fff">Click here if stuck</a>';
    el.appendChild(d); el.scrollTop = el.scrollHeight;
}
</script>

<?php
flush();

$backupCreated = false;

try {
    // CSRF Validation (accept both session and bridge token)
    $postedToken = $_POST['token'] ?? '';
    $sessionToken = $_SESSION['token'] ?? '';
    $isValid = ($postedToken === $sessionToken) || ($bridgeToken && $postedToken === $bridgeToken);

    if (!$isValid) {
        throw new Exception(t('err_csrf'));
    }

    sendProgress(5, t('step_start'));

    // ============================================================================
    // PHASE 1: DOWNLOAD
    // ============================================================================
    
    if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
        throw new Exception(sprintf(t('err_php'), MIN_PHP_VERSION, PHP_VERSION));
    }

    sendProgress(10, sprintf(t('step_down'), APP_VERSION));
    
    $fp = fopen(ZIP_FILE, 'wb');
    $ch = curl_init(PACKAGE_URL);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($r, $ds, $d) {
            if ($ds > 0) {
                static $last = 0;
                $current = $d / 1048576;
                if ($current - $last >= 1) {
                    $pct = 10 + ($d / $ds) * 30;
                    $msg = sprintf("Download: %.1f MB / %.1f MB", $current, $ds/1048576);
                    sendProgress($pct, $msg, 'current', true);
                    $last = $current;
                }
            }
        }
    ]);
    
    if (!curl_exec($ch)) {
        $error = curl_error($ch);
        fclose($fp);
        @unlink(ZIP_FILE);
        throw new Exception("Download failed: {$error}");
    }
    
    fclose($fp);
    
    logTech("Download completed: " . filesize(ZIP_FILE) . " bytes");

    // ============================================================================
    // PHASE 2: INTEGRITY CHECK
    // ============================================================================
    
    sendProgress(40, t('step_hash'));
    
    $calculated = hash_file('sha256', ZIP_FILE);
    
    if (!hash_equals(PACKAGE_HASH, $calculated)) {
        logTech("Hash mismatch - Expected: " . PACKAGE_HASH);
        logTech("Hash mismatch - Calculated: " . $calculated);
        @unlink(ZIP_FILE);
        throw new Exception("Security error: Hash mismatch!");
    }
    
    logTech("Integrity verified");

    // ============================================================================
    // PHASE 3: BACKUP (Minimal - Only .env)
    // ============================================================================
    
    sendProgress(45, t('step_backup'));
    
    @mkdir(BACKUP_DIR, 0755, true);
    
    if (file_exists('.env')) {
        @copy('.env', BACKUP_DIR . '/.env');
        logTech("Backed up: .env (" . filesize('.env') . " bytes)");
    }
    
    $backupCreated = true;

    // ============================================================================
    // PHASE 4: EXTRACTION
    // ============================================================================
    
    sendProgress(50, t('step_unzip'));
    
    $zip = new ZipArchive();
    if ($zip->open(ZIP_FILE) !== true) {
        throw new Exception("Cannot open ZIP archive");
    }
    
    @mkdir(TEMP_DIR, 0755, true);
    $zip->extractTo(TEMP_DIR);
    $zip->close();
    
    logTech("Extraction completed");

    // ============================================================================
    // PHASE 5: DEPLOYMENT (with Exclude Logic)
    // ============================================================================
    
    sendProgress(70, t('step_install'));
    
    // Detect ZIP structure
    $sourceDir = TEMP_DIR;
    $files = array_diff(scandir(TEMP_DIR), ['.', '..', '__MACOSX']);
    
    if (count($files) === 1 && is_dir(TEMP_DIR . '/' . reset($files))) {
        $sourceDir = TEMP_DIR . '/' . reset($files);
        logTech("Nested structure detected: " . reset($files));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $deployed = 0;
    $skipped = 0;
    
    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
        
        if (str_starts_with($relativePath, '__MACOSX')) continue;
        
        // Check exclude items
        $isExcluded = false;
        foreach ($excludeItems as $excluded) {
            if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                $isExcluded = true;
                break;
            }
        }
        
        if ($isExcluded) {
            $skipped++;
            if ($skipped <= 5) logTech("SKIPPED: {$relativePath}");
            continue;
        }
        
        $targetPath = __DIR__ . '/' . $relativePath;
        
        if ($item->isDir()) {
            if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        } else {
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
            
            if (file_exists($targetPath)) {
                @chmod($targetPath, 0777);
                @unlink($targetPath);
            }
            
            if (@copy($item->getPathname(), $targetPath)) {
                @chmod($targetPath, 0644);
                $deployed++;
            } else {
                logTech("WARNING: Failed to deploy {$relativePath}");
            }
        }
    }
    
    logTech("Deployment: {$deployed} files deployed, {$skipped} excluded");

    // ============================================================================
    // PHASE 6: POST-INSTALL
    // ============================================================================
    
    sendProgress(85, t('step_opcache'));
    
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        logTech("OPcache reset successful");
    }

    sendProgress(88, t('step_health'));
    
    $criticalFiles = ['artisan', 'public/index.php', 'config/app.php'];
    $missing = [];
    
    foreach ($criticalFiles as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            $missing[] = $file;
        }
    }
    
    if (!empty($missing)) {
        throw new Exception("Critical files missing: " . implode(', ', $missing));
    }
    
    logTech("Health check passed");

    // ============================================================================
    // PHASE 7: CLEANUP
    // ============================================================================
    
    sendProgress(95, t('step_clean'));
    
    safeRmdir(TEMP_DIR);
    @unlink(ZIP_FILE);

    // PULIZIA FILE OBSOLETI (Vecchie versioni)
    $obsoletePaths = [
        __DIR__ . '/tests',
        __DIR__ . '/docs',
        __DIR__ . '/docker',
        __DIR__ . '/index.php.installed',
    ];

    foreach ($obsoletePaths as $path) {
        if (is_dir($path)) {
            safeRmdir($path);
            logTech("Removed obsolete directory: " . basename($path));
        } elseif (file_exists($path)) {
            @unlink($path);
            logTech("Removed obsolete file: " . basename($path));
        }
    }
    
    // Laravel cache clearing
    $cacheDir = __DIR__ . '/bootstrap/cache';
    $cleared = 0;
    
    foreach (['config.php', 'routes.php', 'packages.php', 'services.php'] as $file) {
        if (file_exists("{$cacheDir}/{$file}")) {
            @unlink("{$cacheDir}/{$file}");
            $cleared++;
        }
    }
    
    logTech("Cleared {$cleared} Laravel cache files");
    
    // View cache
    $viewDir = __DIR__ . '/storage/framework/views';
    if (is_dir($viewDir)) {
        $viewsCleared = 0;
        foreach (glob("{$viewDir}/*.php") as $view) {
            if (@unlink($view)) $viewsCleared++;
        }
        logTech("Cleared {$viewsCleared} compiled views");
    }
    
    // Restore .env
    if (file_exists(BACKUP_DIR . '/.env')) {
        @copy(BACKUP_DIR . '/.env', '.env');
        logTech("Restored: .env");

        // ------------------------------------------------------------------
        // SMART PATCH v1.9: INIEZIONE AUTOMATICA PROXY
        // ------------------------------------------------------------------
        // Poiché stiamo aggiornando da v1.8, il .env non ha TRUSTED_PROXIES.
        // Dobbiamo rilevarlo e aggiungerlo se necessario.
        
        $envContent = file_get_contents('.env');
        
        // Verifica se la variabile manca (evita duplicati)
        if (strpos($envContent, 'TRUSTED_PROXIES') === false) {
            
            // 1. Rilevamento Nome (Altervista, ecc.)
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $isRestricted = (@disk_free_space(__DIR__) === false) || 
                            (strpos($host, 'altervista') !== false) ||
                            (strpos($host, '.av') !== false) ||
                            (strpos($host, 'infinityfree') !== false) ||
                            (strpos($host, 'rf.gd') !== false) || 
                            (strpos($host, 'netsons') !== false);

            // 2. Rilevamento Tecnico (Proxy Headers)
            $isBehindProxy = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || 
                             !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) || 
                             !empty($_SERVER['HTTP_CF_CONNECTING_IP']);

            if ($isRestricted || $isBehindProxy) {
                logTech("Upgrade Patch: Proxy environment detected ($host) - Injecting TRUSTED_PROXIES=*");
                
                $patch  = "\n\n# --- AUTO-CONFIGURED BY V1.9 UPGRADER ---\n";
                $patch .= "# Fix per HTTPS e Cron Job su Hosting Condivisi/Proxy\n";
                $patch .= "TRUSTED_PROXIES=*\n";
                
                // Opzionale: Fix DB se mancano le definizioni moderne
                if (strpos($envContent, 'DB_ENGINE') === false) {
                    $patch .= "DB_CHARSET=utf8\nDB_COLLATION=utf8_unicode_ci\nDB_ENGINE=\"InnoDB ROW_FORMAT=DYNAMIC\"\n";
                }

                file_put_contents('.env', $patch, FILE_APPEND);
            } else {
                logTech("Upgrade Patch: Standard environment - No changes needed to .env");
            }
        } else {
            logTech("Upgrade Patch: TRUSTED_PROXIES already defined - Skipping patch"); 
        }
    }
    
    safeRmdir(BACKUP_DIR);
    
    // Remove bridge
    if (file_exists($bridgeFile)) {
        @unlink($bridgeFile);
        logTech("Bridge file removed");
    }

    // ============================================================================
    // PHASE 8: SELF-DESTRUCT
    // ============================================================================
    
    register_shutdown_function(function() {
        @unlink(__FILE__);
        logTech("Installer self-destructed");
    });

    sendProgress(100, t('step_done'), 'success');
    
    $redirect = '/system/upgrade/finalize';
    echo "<script nonce='{$nonce}'>showFinal('{$redirect}'); setTimeout(() => { window.location.href = '{$redirect}'; }, 2500);</script>";

} catch (Exception $e) {
    logTech("FATAL ERROR: " . $e->getMessage());
    sendProgress(100, sprintf(t('err_generic'), $e->getMessage()), 'error');
    
    if ($backupCreated) {
        sendProgress(100, t('step_rollback'), 'error');
        if (performRollback()) {
            logTech("Rollback successful");
        } else {
            logTech("Rollback failed");
        }
    }
    
    @unlink(ZIP_FILE);
    if (isset($tempDir) && is_dir($tempDir)) {
        safeRmdir($tempDir);
    }
}
?>
</body>
</html>
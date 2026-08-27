<?php
/**
 * KondoManager Auto-Update Engine - v14.0 Bridge (Root Aware)
 * QUESTO FILE È GESTITO DA GIT - NON CONTIENE HASH/URL HARDCODED
 * Funziona SOLO in modalità aggiornamento automatico via Laravel bridge
 * Posizione: resources/installer/index.php
 * Attivazione: copiato in public/km-update.php da UpdateService quando necessario
 * @version 14.0.0
 * @author KondoManager Team
 * @date 16 Agosto 2026
 *
 * NOVITÀ v14.0 (rispetto alla v13.2 "Proxy Aware")
 * 1. La radice del progetto non è più __DIR__ ma arriva da paths.base dentro
 *    update_bridge.json, che Laravel scrive conoscendola con certezza. Fino alla
 *    v13.2 il bridge veniva copiato in base_path('index.php') e ogni percorso
 *    pendeva dalla cartella in cui si trovava: funziona solo dove la cartella
 *    pubblica coincide con la radice del progetto — gli hosting condivisi, grazie
 *    all'.htaccess. Su ogni installazione con il document root su public/ (VPS,
 *    container, vhost Apache, l'immagine Docker standard) il POST di avvio
 *    finiva sul front controller di Laravel e l'aggiornamento non partiva.
 *    Ora il file vive in public/km-update.php e sa dove sta il progetto.
 * 2. Il JSON viene cercato accanto al file e poi un livello sopra, perché è
 *    scritto nella radice e deve restare fuori dal web (contiene il token).
 * 3. Eliminati i percorsi relativi: backup, ripristino e patch del .env usavano
 *    la cartella di lavoro del processo, che ora non è più la radice — con il
 *    file in public/ avrebbero lavorato in silenzio sulla cartella sbagliata.
 * 4. Rimozione degli assets compilati della versione precedente da public/build,
 *    dopo l'health check (vedi il commento alla fase 6-bis per il perché
 *    dell'ordine).
 * 5. Autodistruzione con ripiego: se la cancellazione non riesce, il file si
 *    riscrive come 410 Gone con una firma che la finalizzazione riconosce.
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
// 1-bis. IDENTITÀ VISIVA
// Stesso linguaggio del setup standalone (fondo scuro, card bianca, logo Km):
// per l'amministratore che aggiorna sono lo stesso strumento, e finché il
// bridge aveva un suo gradiente viola sembravano due prodotti diversi.
// Foglio unico condiviso dalle tre schermate (errore bridge, avvio, avanzamento).
// ============================================================================

$logo = <<<SVG
<svg class="logo-svg" viewBox="0 0 16 16">
    <circle cx="8" cy="8" r="8" fill="#030712"/>
    <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="8" fill="white" font-weight="bold">Km</text>
</svg>
SVG;

$footer = <<<HTML
<p class="powered-footer">
    Powered by <a href="https://www.kondomanager.com" target="_blank" rel="noopener noreferrer">Kondomanager</a>
    &mdash;
    <a href="https://github.com/vince844/kondomanager-free" target="_blank" rel="noopener noreferrer">Software Open Source (AGPL-3.0) per la gestione condominiale</a>
</p>
HTML;

$css = <<<CSS
:root { --primary: #030712; --danger: #dc2626; --success: #22c55e; --warning: #f59e0b; --info: #3b82f6; --bg: #030712; --card: #ffffff; --text: #374151; }
body { font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; margin: 0; }
.installer-card { background: var(--card); width: 100%; max-width: 600px; padding: 2.5rem; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3); border: 1px solid #e5e7eb; }
.header { text-align: center; margin-bottom: 2rem; }
.logo-svg { width: 3.5rem; height: 3.5rem; margin-bottom: 1rem; }
h1 { font-size: 1.5rem; font-weight: 700; color: #030712; margin: 0 0 0.5rem 0; }
.tagline { color: #6b7280; font-size: 0.95rem; line-height: 1.5; margin: 0; }
.intro-text { color: #6b7280; font-size: .85rem; line-height: 1.6; margin-top: .75rem; padding-top: .75rem; border-top: 1px solid #f3f4f6; }
.update-alert { background: #eff6ff; border-left: 3px solid var(--info); color: #1e40af; padding: 0.75rem; font-size: 0.85rem; margin-bottom: 1.5rem; border-radius: 6px; }
.error-alert { background: #fef2f2; border-left: 3px solid var(--danger); color: #b91c1c; padding: 0.75rem; font-size: 0.85rem; margin-bottom: 1.5rem; border-radius: 6px; }
.progress-container { margin: 2rem 0; }
.progress-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem; }
.progress-track { background: #f3f4f6; height: 10px; border-radius: 10px; overflow: hidden; }
.progress-fill { background: #34d399; height: 100%; width: 0%; transition: width 0.4s ease; }
.progress-fill.error { background: #dc2626; }
.log-window { background: #111827; color: #e5e7eb; padding: 1rem; border-radius: 12px; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 0.85rem; height: 200px; overflow-y: auto; border: 1px solid #1f2937; }
.log-entry { margin-bottom: 4px; display: flex; gap: 8px; }
.icon-ok { color: #4ade80; } .icon-err { color: #f87171; }
.btn { display: flex; width: 100%; justify-content: center; padding: 1rem; background: #1f2937; color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn:disabled { background: #d1d5db !important; color: #9ca3af !important; cursor: not-allowed !important; opacity: 1; }
.btn:hover:not(:disabled) { background: #030712; }
.btn:active:not(:disabled) { transform: scale(0.98); }
a { color: #3b82f6; text-decoration: none; font-weight: bold; }
a:hover { text-decoration: underline; }
.code { background: #f3f4f6; padding: 2px 8px; border-radius: 6px; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 0.85em; color: #1f2937; font-weight: 600; }
.hint { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 14px; margin-top: 1.25rem; text-align: left; }
.hint strong { color: #075985; font-size: .9rem; }
.hint p { color: #0c4a6e; font-size: 13px; line-height: 1.6; margin: 6px 0 0; }
.powered-footer { text-align: center; color: #9ca3af; font-size: .75rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #f3f4f6; margin-bottom: 0; }
.powered-footer a { color: #6b7280; font-weight: 600; text-decoration: none; }
.powered-footer a:hover { color: var(--primary); text-decoration: underline; }
CSS;

// ============================================================================
// 2. BRIDGE VALIDATION (CRITICAL)
// ============================================================================

function logTech(string $msg) {
    // Finché la radice non è nota si scrive accanto al file: serve a registrare
    // anche i guasti che avvengono PRIMA di sapere dove sia il progetto.
    $dir = defined('KM_ROOT') ? KM_ROOT : __DIR__;
    @file_put_contents($dir . '/install.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

// CRITICAL: This file REQUIRES a bridge to operate.
// Il file JSON lo scrive Laravel nella radice del progetto (fuori dal web), e
// questo file dalla 1.10 vive dentro public/: lo si cerca quindi prima accanto
// a sé (installazioni dove le due cartelle coincidono, cioè gli hosting
// condivisi) e poi un livello sopra. Nessun'altra posizione: due tentativi
// espliciti, non una ricerca.
$bridgeFile = null;

foreach ([__DIR__ . '/update_bridge.json', dirname(__DIR__) . '/update_bridge.json'] as $candidato) {
    if (file_exists($candidato)) {
        $bridgeFile = $candidato;
        break;
    }
}

$bridgeFile = $bridgeFile ?? __DIR__ . '/update_bridge.json';

if (!file_exists($bridgeFile)) {
    logTech("FATAL: Bridge file missing - cannot proceed");
    http_response_code(503);
    die(<<<HTML
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>KondoManager &mdash; Motore di aggiornamento</title>
        <style>{$css}</style>
    </head>
    <body>
        <div class="installer-card">
            <div class="header">
                {$logo}
                <h1>Motore di aggiornamento</h1>
                <p class="tagline">Questo file funziona solo in modalità aggiornamento automatico.</p>
            </div>

            <div class="error-alert">
                File mancante: <span class="code">update_bridge.json</span>
            </div>

            <p class="tagline" style="text-align:center">
                Avvia l'aggiornamento dalla <a href="/system/upgrade">dashboard di Kondomanager</a>.
            </p>

            <div class="hint">
                <strong>L'aggiornamento non riparte?</strong>
                <p>Puoi aggiornare con il <a href="https://kondomanager.short.gy/km-installer" target="_blank" rel="noopener">file standalone di setup</a>
                (sempre l'ultima versione ufficiale): va bene sia per una nuova installazione sia per
                aggiornarne una esistente &mdash; riconosce da solo il caso dalla presenza del file
                <span class="code">.env</span> e, in aggiornamento, <strong>preserva database, storage
                e configurazione</strong>, sostituendo i soli file di sistema. Al termine ti riporta su
                <span class="code">/system/upgrade/finalize</span>.</p>
            </div>

            {$footer}
        </div>
    </body>
    </html>
    HTML);
}

$bridge = json_decode(file_get_contents($bridgeFile), true);

if (empty($bridge['package']['version'])) {
    logTech("FATAL: Invalid bridge structure");
    die("Bridge file corrupted. Regenerate from Laravel.");
}

// ============================================================================
// 3. CONFIGURATION (From Bridge)
// ============================================================================

// --- RADICE DEL PROGETTO ---------------------------------------------------
// Non si deduce e non si indovina: la scrive Laravel dentro il JSON, che è
// l'unico a saperla con certezza (base_path()). Le altre due possibilità sono
// ripieghi in ordine di attendibilità:
//   1. paths.base dal JSON, se punta ancora a un'installazione vera
//   2. la cartella che contiene il JSON — che Laravel scrive nella radice
// Il controllo su artisan serve al caso in cui l'installazione sia stata
// spostata dopo che il bridge era già stato preparato: meglio la posizione
// reale di un percorso assoluto ormai vecchio.
$rootFromBridge = $bridge['paths']['base'] ?? null;
$rootFromJson   = dirname($bridgeFile);

if (is_string($rootFromBridge) && is_file(rtrim($rootFromBridge, '/') . '/artisan')) {
    define('KM_ROOT', rtrim($rootFromBridge, '/'));
} else {
    define('KM_ROOT', $rootFromJson);
}

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

define('LOG_FILE', KM_ROOT . '/install.log');
define('ZIP_FILE', KM_ROOT . '/update_temp.zip');
define('TEMP_DIR', KM_ROOT . '/_update_temp_' . time());
define('BACKUP_DIR', KM_ROOT . '/_km_safe_zone');

$bridgeToken = $bridge['security']['token'] ?? null;

logTech("=== AUTO-UPDATE ENGINE v14.0 ===");
logTech("Project root: " . KM_ROOT);
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

/**
 * Traduzione con segnaposto.
 *
 * L'iniezione automatica di APP_VERSION è una comodità per le stringhe di tipo
 * "Versione target: v%s", ma va applicata SOLO quando il chiamante non passa
 * argomenti propri: prima scattava sempre, e produceva due difetti seri proprio
 * sul percorso d'errore dell'aggiornamento.
 *
 *   - `sprintf(t('err_generic'), $e->getMessage())` mostrava "Errore: 1.10.0-beta.x"
 *     al posto del guasto reale, che restava leggibile solo in install.log.
 *   - `t('err_php')` ha DUE segnaposto: con un solo argomento iniettato
 *     `vsprintf` solleva ValueError. Con display_errors=0 significa pagina
 *     bianca — e quel ramo si raggiunge quando l'hosting ha PHP troppo vecchio,
 *     cioè quando quel messaggio è l'unica cosa che serve leggere.
 *
 * Il pareggiamento finale degli argomenti rende la funzione incapace di
 * sollevare: in un aggiornatore, la schermata d'errore non può essere essa
 * stessa una fonte di errori fatali.
 */
function t(string $key, mixed ...$args) {
    global $langs;
    $txt = $langs[getLang()][$key] ?? $key;

    $needed = substr_count($txt, '%s');
    if ($needed === 0) return $txt;

    if (empty($args)) $args = [APP_VERSION];
    $args = array_pad(array_slice($args, 0, $needed), $needed, '');

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
        $target = KM_ROOT . '/' . $rel;
        
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
    <style><?= $css ?></style>
</head>
<body>
    <div class="installer-card">
        <div class="header">
            <?= $logo ?>
            <h1><?= t('welcome') ?></h1>
            <p class="tagline"><?= t('tagline', APP_VERSION) ?></p>
        </div>

        <?php if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')): ?>
            <div class="error-alert"><?= t('err_php', MIN_PHP_VERSION, PHP_VERSION) ?></div>
            <button class="btn" disabled><?= t('btn_text') ?></button>
        <?php else: ?>
            <div class="update-alert">
                Verranno sostituiti i file di sistema. Database, documenti e configurazione restano al sicuro.
            </div>
            <form method="post">
                <input type="hidden" name="token" value="<?= $_SESSION['token'] ?>">
                <button type="submit" class="btn"><?= t('btn_text') ?></button>
            </form>
        <?php endif; ?>

        <?= $footer ?>
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
    <style><?= $css ?></style>
</head>
<body>
    <div class="installer-card">
        <div class="header">
            <?= $logo ?>
            <h1><?= t('welcome') ?></h1>
            <p class="tagline">Non chiudere questa pagina fino al completamento.</p>
        </div>

        <div class="progress-container">
            <div class="progress-header">
                <span>Avanzamento</span>
                <span id="pct-text">0%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="progress-bar"></div>
            </div>
        </div>

        <div class="log-window" id="log-box"></div>
        <div id="final-msg" style="display:none; text-align:center; margin-top:1.5rem;"></div>

        <?= $footer ?>
    </div>

<script nonce="<?= $nonce ?>">
function updateProgress(p, m, s, replaceLast = false) {
    const bar = document.getElementById('progress-bar');
    const pctText = document.getElementById('pct-text');
    const log = document.getElementById('log-box');
    bar.style.width = p + '%';
    pctText.innerText = Math.round(p) + '%';
    if (replaceLast && log.lastElementChild) {
        log.lastElementChild.innerHTML = '• ' + m;
    } else {
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        let icon = '•';
        if (s === 'success') icon = '<span class="icon-ok">✓</span>';
        if (s === 'error') icon = '<span class="icon-err">✕</span>';
        entry.innerHTML = icon + ' ' + m;
        log.appendChild(entry);
        log.scrollTop = log.scrollHeight;
    }
    if (s === 'error') {
        bar.classList.add('error');
    }
}
function showFinal(url) {
    const el = document.getElementById('final-msg');
    el.style.display = 'block';
    el.innerHTML = '<p style="color:#16a34a; font-weight:bold;">Operazione completata.</p><p>Reindirizzamento in corso...<br>Se non vieni reindirizzato, <a href="'+url+'">clicca qui</a>.</p>';
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
        throw new Exception(t('err_php', MIN_PHP_VERSION, PHP_VERSION));
    }

    sendProgress(10, t('step_down', APP_VERSION));
    
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
    
    // Percorsi espliciti, non relativi: la cartella di lavoro del processo e'
    // quella di QUESTO file, che dalla 1.10 sta dentro public/ e quindi non e'
    // la radice. Con i percorsi relativi il backup del .env non veniva fatto e
    // il ripristino piu' sotto non trovava nulla, in silenzio.
    if (file_exists(KM_ROOT . '/.env')) {
        @copy(KM_ROOT . '/.env', BACKUP_DIR . '/.env');
        logTech("Backed up: .env (" . filesize(KM_ROOT . '/.env') . " bytes)");
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
        
        $targetPath = KM_ROOT . '/' . $relativePath;
        
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
        if (!file_exists(KM_ROOT . '/' . $file)) {
            $missing[] = $file;
        }
    }
    
    if (!empty($missing)) {
        throw new Exception("Critical files missing: " . implode(', ', $missing));
    }
    
    logTech("Health check passed");

    // ============================================================================
    // PHASE 6-bis: ASSETS DELLA VERSIONE PRECEDENTE
    // ============================================================================
    // Vite mette l'hash del contenuto nel nome dei file compilati: quelli della
    // versione precedente non vengono sovrascritti da quelli nuovi e nessuno li
    // toglie. Il sito serve comunque quelli giusti — il manifest è aggiornato —
    // ma la cartella cresce a ogni aggiornamento (su un caso reale beta.46 →
    // beta.50 erano 375 file morti).
    //
    // Gira QUI, dopo l'health check, e non prima del deploy: svuotare
    // public/build in anticipo significherebbe che un aggiornamento interrotto a
    // metà lascia il sito senza alcun asset, cioè una pagina bianca al posto di
    // una versione vecchia ma funzionante. Le tre guardie (il pacchetto deve
    // avere la sua public/build col manifest, e il manifest dev'essere arrivato)
    // fanno sì che nel dubbio non si cancelli niente.
    $buildSrc = $sourceDir . '/public/build';
    $buildDst = KM_ROOT . '/public/build';

    if (is_dir($buildSrc) && is_file($buildSrc . '/manifest.json') && is_file($buildDst . '/manifest.json')) {
        $attesi = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($buildSrc, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $f) {
            if ($f->isFile()) $attesi[substr($f->getPathname(), strlen($buildSrc) + 1)] = true;
        }

        // Prima si elenca, poi si cancella: modificare l'albero mentre lo si
        // percorre è il modo classico per saltare qualche voce.
        $daRimuovere = [];
        $cartelle = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($buildDst, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            if ($f->isDir()) { $cartelle[] = $f->getPathname(); continue; }
            $rel = substr($f->getPathname(), strlen($buildDst) + 1);
            if (!isset($attesi[$rel])) $daRimuovere[] = $f->getPathname();
        }

        $rimossi = 0;
        foreach ($daRimuovere as $p) if (@unlink($p)) $rimossi++;
        foreach ($cartelle as $d) @rmdir($d); // fallisce da sé se non è vuota

        logTech("Stale assets removed from public/build: {$rimossi}");
    } else {
        logTech("Stale asset cleanup skipped: build folder or manifest missing");
    }

    // ============================================================================
    // PHASE 7: CLEANUP
    // ============================================================================
    
    sendProgress(95, t('step_clean'));
    
    safeRmdir(TEMP_DIR);
    @unlink(ZIP_FILE);

    // PULIZIA FILE OBSOLETI (Vecchie versioni)
    $obsoletePaths = [
        KM_ROOT . '/tests',
        KM_ROOT . '/docs',
        KM_ROOT . '/docker',
        KM_ROOT . '/index.php.installed',
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
    //
    // Si cancella TUTTO ciò che finisce in .php dentro bootstrap/cache, e non un
    // elenco di nomi. L'elenco c'era, ed era sbagliato: conteneva `routes.php`,
    // che è il nome usato da Laravel fino alla 6 — dalla 7 la cache delle rotte
    // si chiama `routes-v7.php`. Restavano quindi sul server, da prima
    // dell'aggiornamento, `routes-v7.php`, `events.php` e `settings.php`.
    //
    // Il costo non è teorico: se `bootstrap/cache` è preservato dal deploy, i file
    // restano quelli di prima e il risultato è codice nuovo con la tabella delle
    // rotte vecchia — `route:list` fallisce cercando un controller che non esiste
    // più, e le rotte nate nella versione nuova non esistono. Chi aggiorna dal
    // pannello non ha una console per accorgersene né per uscirne.
    //
    // ⚠️ Se sia preservato **non lo decide questo file**: l'elenco delle esclusioni
    // arriva dal bridge (`$bridge['package']['exclude']`, riga 226), cioè dal
    // `latest.json` remoto; la lista qui sotto è solo il ripiego per quando il
    // bridge non ne porta una. Quindi `bootstrap/cache` è escluso davvero soltanto
    // se sta anche nel `latest.json` della release — da verificare a ogni rilascio.
    // ✅ Verificato il 27/08/2026 sul manifest pubblicato della 1.10.0: `bootstrap/cache`
    //    c'è. Quindi la cartella è davvero preservata, e questa cancellazione toglie i
    //    file della versione precedente.
    // In entrambi i casi la cancellazione qui è giusta: se la cartella è preservata
    // toglie i file vecchi, se è sovrascritta toglie quelli appena depositati, che
    // sono comunque cache da rifare.
    //
    // Tutti questi file sono rigenerabili: Laravel li ricostruisce da solo alla
    // prima richiesta (`PackageManifest::build()` per packages/services) o al
    // prossimo `optimize`. Il `.gitignore` della cartella non è un `.php` e
    // sopravvive. Un elenco scritto a mano invece invecchia in silenzio ogni
    // volta che il framework rinomina un file, ed è già successo una volta.
    $cacheDir = KM_ROOT . '/bootstrap/cache';
    $cleared = 0;

    // `scandir()` e non `glob()`: glob interpreta il percorso come uno SCHEMA, quindi
    // su un'installazione il cui percorso contiene una parentesi quadra — es.
    // /home/utente/km[test] — restituisce un array vuoto anche con i file tutti lì, e
    // la pulizia non avverrebbe, in silenzio. Sarebbe una regressione rispetto al
    // vecchio codice a nomi letterali, che in quel percorso funzionava. Misurato.
    foreach (is_dir($cacheDir) ? (scandir($cacheDir) ?: []) : [] as $nome) {
        if (substr($nome, -4) !== '.php') {
            continue;
        }

        if (@unlink("{$cacheDir}/{$nome}")) {
            $cleared++;
            logTech("Cleared Laravel cache: {$nome}");
        }
    }

    logTech("Cleared {$cleared} Laravel cache files");
    
    // View cache
    $viewDir = KM_ROOT . '/storage/framework/views';
    if (is_dir($viewDir)) {
        $viewsCleared = 0;
        foreach (glob("{$viewDir}/*.php") as $view) {
            if (@unlink($view)) $viewsCleared++;
        }
        logTech("Cleared {$viewsCleared} compiled views");
    }
    
    // Restore .env
    if (file_exists(BACKUP_DIR . '/.env')) {
        @copy(BACKUP_DIR . '/.env', KM_ROOT . '/.env');
        logTech("Restored: .env");

        // ------------------------------------------------------------------
        // SMART PATCH v1.9: INIEZIONE AUTOMATICA PROXY
        // ------------------------------------------------------------------
        // Poiché stiamo aggiornando da v1.8, il .env non ha TRUSTED_PROXIES.
        // Dobbiamo rilevarlo e aggiungerlo se necessario.
        
        $envContent = file_get_contents(KM_ROOT . '/.env');
        
        // Verifica se la variabile manca (evita duplicati)
        if (strpos($envContent, 'TRUSTED_PROXIES') === false) {
            
            // 1. Rilevamento Nome (Altervista, ecc.)
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $isRestricted = (@disk_free_space(KM_ROOT) === false) || 
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

                file_put_contents(KM_ROOT . '/.env', $patch, FILE_APPEND);
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
        if (@unlink(__FILE__)) {
            logTech("Installer self-destructed");
            return;
        }

        // Se i permessi non consentono la cancellazione, il file resta
        // raggiungibile dal web: senza più il JSON mostrerebbe per sempre la
        // schermata "bridge mancante". Lo si neutralizza sul posto, e la firma
        // qui sotto è quella che SystemUpgradeController::cleanupInstallerJunk()
        // cerca per rimuoverlo alla finalizzazione.
        @file_put_contents(__FILE__, "<?php // KondoManager Auto-Update Engine\nheader('HTTP/1.1 410 Gone');\n");
        logTech("Installer self-destruct failed: neutralised in place (410 Gone)");
    });

    sendProgress(100, t('step_done'), 'success');
    
    $redirect = '/system/upgrade/finalize';
    echo "<script nonce='{$nonce}'>showFinal('{$redirect}'); setTimeout(() => { window.location.href = '{$redirect}'; }, 2500);</script>";

} catch (\Throwable $e) {
    // \Throwable e non solo \Exception: PHP 8.4 solleva \Error/\TypeError/\ValueError
    // in punti imprevisti, e quelli NON discendono da \Exception. Con display_errors=0
    // un errore del genere ucciderebbe lo script in silenzio a metà aggiornamento,
    // dando l'impressione che i file si scarichino ma non si venga mai reindirizzati —
    // e senza far scattare il rollback qui sotto. Stesso irrobustimento già applicato
    // al setup standalone.
    logTech("FATAL ERROR: " . $e->getMessage());
    sendProgress(100, t('err_generic', $e->getMessage()), 'error');
    
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
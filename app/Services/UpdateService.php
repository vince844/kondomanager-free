<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateService
 *
 * Gestisce l'intero ciclo di vita degli aggiornamenti automatici di KondoManager.
 *
 * FLUSSO PRINCIPALE:
 *   1. checkRemoteVersion()   → interroga il server remoto e popola la cache notifiche
 *   2. hasUpdateAvailable()   → la UI legge la cache per mostrare il badge
 *   3. prepareForUpgrade()    → valida i requisiti, scrive il bridge, attiva l'installer
 *   4. L'installer (index.php in root) esegue il deploy e si autodistrugge
 *   5. SystemUpgradeController::finalize() pulisce cache e allinea il DB
 *
 * MODALITÀ OPERATIVE:
 *   - Auto-update  (run_installer = true):  flusso completo abilitato
 *   - Manuale      (run_installer = false): questo service è disabilitato,
 *                                           l'utente aggiorna via FTP/setup.php
 *
 * @see resources/installer/index.php  Bridge installer (v13.2)
 * @see SystemUpgradeController        Controller post-update
 */
class UpdateService
{
    /**
     * Endpoint remoto che espone latest.json.
     * Struttura attesa:
     *   {
     *     "latest_stable": "1.9.0",
     *     "releases": [
     *       {
     *         "version": "1.9.0",
     *         "url": "...",
     *         "hash": "sha256...",
     *         "requirements": { "php": "8.4.0", "extensions": [...] },
     *         "exclude": [...]
     *       }
     *     ]
     *   }
     */
    private const UPDATE_URL = 'https://kondomanager.com/packages/latest.json';

    /**
     * Percorso relativo alla root del progetto del file bridge.
     * Viene scritto da prepareForUpgrade() e letto dall'installer PHP.
     * Viene cancellato dall'installer al termine dell'aggiornamento.
     */
    private const BRIDGE_FILE = 'update_bridge.json';

    /**
     * Percorso del file installer master, versionato in Git.
     * Non viene mai eseguito direttamente da qui — viene COPIATO in root
     * da activateInstaller() e da lì eseguito via browser.
     */
    private const MASTER_PATH = 'resources/installer/index.php';

    // -------------------------------------------------------------------------
    // Chiavi cache — persistenti (Cache::forever) finché:
    //   - non viene trovato aggiornamento → clearUpdateCache()
    //   - la cache globale viene svuotata (cache:clear)
    // -------------------------------------------------------------------------

    /** Flag booleano: true se esiste una versione più recente della corrente */
    private const CACHE_UPDATE_AVAILABLE = 'system.update_available';

    /** Stringa con il numero di versione remota disponibile (es. "1.9.0") */
    private const CACHE_REMOTE_VERSION   = 'system.remote_version';

    // =========================================================================
    // CONFIGURAZIONE
    // =========================================================================

    /**
     * Indica se gli aggiornamenti automatici sono abilitati per questa installazione.
     *
     * Il flag viene impostato a true dal Wizard Laravel durante l'installazione
     * iniziale (Scenario 1). Le installazioni manuali via FTP lo lasciano a false.
     *
     * @return bool
     */
    public function isAutoUpdateEnabled(): bool
    {
        return config('installer.run_installer', false) === true;
    }

    // =========================================================================
    // CHECK REMOTO
    // =========================================================================

    /**
     * Interroga il server remoto per verificare la disponibilità di aggiornamenti.
     *
     * - Se gli auto-update sono disabilitati, esce immediatamente (no HTTP call).
     * - Confronta latest_stable con la versione locale (config('app.version')).
     * - Se c'è una versione più recente, popola la cache notifiche e restituisce
     *   l'array della release (usato poi da prepareForUpgrade).
     * - Se non ci sono aggiornamenti, pulisce la cache per nascondere eventuali
     *   badge rimasti da un check precedente.
     *
     * Timeout HTTP: 5 secondi (non bloccante per l'utente in caso di server lento).
     *
     * @return array|null  Array della release disponibile, oppure null se aggiornato
     */
    public function checkRemoteVersion(): ?array
    {
        if (!$this->isAutoUpdateEnabled()) {
            Log::info('Auto-update disabled - manual installation detected');
            return null;
        }

        try {
            $response = Http::timeout(5)->get(self::UPDATE_URL);
            if (!$response->successful()) return null;

            $data = $response->json();

            $current = config('app.version');
            $latest  = $data['latest_stable'] ?? null;

            if (!$latest || version_compare($latest, $current, '<=')) {
                // Versione locale già aggiornata — rimuovi badge dalla UI
                $this->clearUpdateCache();
                return null;
            }

            // Recupera i dettagli completi della release dal array releases[]
            $release = collect($data['releases'] ?? [])->firstWhere('version', $latest);

            if ($release) {
                $this->setUpdateAvailable($latest);
            }

            return $release;

        } catch (\Exception $e) {
            // Fallimento silenzioso: rete assente, timeout, JSON malformato, ecc.
            // Non mostriamo errori all'utente, riproveremo al prossimo check.
            Log::warning('Update check failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // CACHE NOTIFICHE
    // =========================================================================

    /**
     * Persiste in cache il fatto che esiste una versione più recente.
     *
     * Usa Cache::forever per non avere scadenze automatiche:
     * il dato rimane finché non viene esplicitamente rimosso da clearUpdateCache()
     * (es. dopo aggiornamento completato o se la versione torna uguale).
     *
     * @param string $version  Versione remota disponibile (es. "1.9.0")
     */
    public function setUpdateAvailable(string $version): void
    {
        Cache::forever(self::CACHE_UPDATE_AVAILABLE, true);
        Cache::forever(self::CACHE_REMOTE_VERSION, $version);

        Log::info('Update available cached', [
            'version' => $version,
            'current' => config('app.version'),
        ]);
    }

    /**
     * Rimuove i flag di aggiornamento dalla cache.
     *
     * Chiamato in due casi:
     *   1. checkRemoteVersion() → nessun aggiornamento trovato
     *   2. SystemUpgradeController::finalize() → aggiornamento completato
     */
    public function clearUpdateCache(): void
    {
        Cache::forget(self::CACHE_UPDATE_AVAILABLE);
        Cache::forget(self::CACHE_REMOTE_VERSION);

        Log::info('Update cache cleared');
    }

    /**
     * Controlla se c'è un aggiornamento disponibile (lettura cache per la UI).
     *
     * Usato dal layout / widget dashboard per mostrare il badge di notifica
     * senza fare una chiamata HTTP ad ogni page load.
     *
     * @return bool
     */
    public function hasUpdateAvailable(): bool
    {
        return Cache::get(self::CACHE_UPDATE_AVAILABLE, false);
    }

    /**
     * Restituisce la versione remota disponibile (lettura cache per la UI).
     *
     * @return string  Versione (es. "1.9.0") o stringa vuota se non disponibile
     */
    public function getRemoteVersion(): string
    {
        return Cache::get(self::CACHE_REMOTE_VERSION, '');
    }

    // =========================================================================
    // PREPARAZIONE AGGIORNAMENTO
    // =========================================================================

    /**
     * Prepara il sistema per l'aggiornamento automatico via Bridge.
     *
     * SEQUENZA:
     *   1. Verifica che gli auto-update siano abilitati
     *   2. Verifica presenza hash SHA256 nel pacchetto remoto
     *   3. Verifica compatibilità versione PHP (blocca prima di toccare file)
     *   4. Genera token CSRF monouso (validità 10 minuti)
     *   5. Scrive update_bridge.json con tutti i parametri per l'installer
     *   6. Copia l'installer master in root (sovrascrive index.php temporaneamente)
     *
     * Il controller chiamerà poi redirect('/index.php?token=...') per avviare
     * il Bridge installer nel browser dell'utente.
     *
     * @param array $release  Array della release (da checkRemoteVersion())
     * @return array          ['token' => string] da passare all'installer via GET/POST
     *
     * @throws \Exception  Se auto-update disabilitato, hash mancante,
     *                     PHP incompatibile, o installer master non trovato
     */
    public function prepareForUpgrade(array $release): array
    {
        // Layer 3: controllo service-level (doppio rispetto al middleware di rotta)
        if (!$this->isAutoUpdateEnabled()) {
            throw new \Exception('Gli aggiornamenti automatici non sono disponibili per installazioni manuali.');
        }

        // L'hash SHA256 è obbligatorio per la verifica di integrità del pacchetto
        if (empty($release['hash'])) {
            throw new \Exception("Hash di sicurezza mancante per la versione " . $release['version']);
        }

        // Verifica PHP PRIMA di scrivere qualsiasi file:
        // se l'utente è su una versione incompatibile, blocchiamo qui con un
        // messaggio leggibile nella UI Laravel, senza rischio di deploy parziale.
        $minPhp = $release['requirements']['php'] ?? '8.4.0';

        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            throw new \Exception(
                "PHP {$minPhp} richiesto. Versione attuale: " . PHP_VERSION .
                " — Aggiorna PHP sul tuo hosting prima di procedere."
            );
        }

        // Token monouso: valida sia lato session (UI) sia lato bridge (redirect diretto)
        $token = bin2hex(random_bytes(32));

        $bridgeData = [
            'security' => [
                'token'        => $token,
                'expires_at'   => time() + 600,   // 10 minuti
                'initiated_by' => Auth::id() ?? 'system',
            ],
            'package' => [
                'version' => $release['version'],
                'url'     => $release['url'],
                'hash'    => $release['hash'],
                // Lista file/cartelle da NON sovrascrivere durante il deploy.
                // Se non specificata nel JSON remoto, usa i default sicuri.
                'exclude' => $release['exclude'] ?? [
                    '.env',
                    'storage',
                    'public/uploads',
                    'public/storage',
                    'install.log',
                    'update_bridge.json',
                    'bootstrap/cache',
                ],
            ],
            'requirements' => $release['requirements'] ?? [
                'php'        => '8.4.0',
                'extensions' => ['zip', 'curl', 'bcmath', 'xml', 'fileinfo', 'posix'],
            ],
        ];

        // Scrive il bridge — da questo momento l'installer ha tutto il necessario
        File::put(base_path(self::BRIDGE_FILE), json_encode($bridgeData, JSON_PRETTY_PRINT));
        chmod(base_path(self::BRIDGE_FILE), 0644);

        // Sovrascrive index.php in root con la copia dell'installer Bridge
        $this->activateInstaller();

        Log::info('Update bridge created', [
            'version'      => $release['version'],
            'initiated_by' => $bridgeData['security']['initiated_by'],
        ]);

        return ['token' => $token];
    }

    // =========================================================================
    // ATTIVAZIONE INSTALLER
    // =========================================================================

    /**
     * Copia il Bridge installer (versionato in Git) nella root pubblica.
     *
     * Il file master in resources/installer/index.php NON viene mai eseguito
     * direttamente — questa copia in root è quella che gestisce il deploy
     * e poi si autodistrugge al termine (register_shutdown_function).
     *
     * @throws \Exception  Se il file master non esiste nel repository
     */
    private function activateInstaller(): void
    {
        $master = base_path(self::MASTER_PATH);
        $target = base_path('index.php');

        if (!File::exists($master)) {
            throw new \Exception("Impossibile trovare l'installer master in: " . self::MASTER_PATH);
        }

        File::copy($master, $target);
        chmod($target, 0644);

        Log::info("Installer activated in root");
    }

    // =========================================================================
    // STATO AGGIORNAMENTO
    // =========================================================================

    /**
     * Verifica se un aggiornamento è attualmente in corso.
     *
     * Usato dal middleware/controller per mostrare una schermata di attesa
     * e impedire operazioni concorrenti durante il deploy.
     *
     * Controlla due segnali:
     *   1. Presenza del bridge file (aggiornamento avviato ma non ancora completato)
     *   2. Presenza di lock file temporanei creati dall'installer durante il deploy
     *
     * @return bool
     */
    public function isUpgradeInProgress(): bool
    {
        if (File::exists(base_path(self::BRIDGE_FILE))) {
            return true;
        }

        return !empty(glob(sys_get_temp_dir() . '/km_lock_*.lock'));
    }
}
<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemUpgradeController extends Controller
{
    /**
     * Dashboard aggiornamenti
     * Gestisce la visualizzazione della pagina principale degli aggiornamenti.
     * Controlla se la funzione di auto-update è abilitata per questa installazione
     * e recupera le informazioni sull'ultima versione disponibile e sullo stato
     * di un eventuale aggiornamento già in corso.
     */
    public function index(UpdateService $service)
    {
        // GATE: Verifica se auto-update è abilitato
        if (!$service->isAutoUpdateEnabled()) {
            return Inertia::render('system/upgrade/Disabled', [
                'reason'  => 'manual_installation',
                'message' => 'Gli aggiornamenti automatici non sono disponibili per installazioni manuali. Per aggiornare, segui la procedura manuale.'
            ]);
        }

        return Inertia::render('system/upgrade/Index', [
            'currentVersion'    => config('app.version'),
            'availableRelease'  => $service->checkRemoteVersion(),
            'inProgress'        => $service->isUpgradeInProgress()
        ]);
    }

    /**
     * Lancio aggiornamento
     * Inizializza il processo di aggiornamento automatico.
     * Verifica l'effettiva disponibilità di una nuova release, prepara i file necessari
     * (come il bridge di aggiornamento) e genera un token di sicurezza. Ritorna la vista
     * che guiderà l'utente al trigger effettivo del download/installazione.
     */
    public function launch(UpdateService $service)
    {
        // GATE: Verifica auto-update abilitato
        if (!$service->isAutoUpdateEnabled()) {
            return back()->withErrors([
                'msg' => 'Gli aggiornamenti automatici non sono disponibili. Usa la procedura manuale.'
            ]);
        }

        $release = $service->checkRemoteVersion();
        
        if (!$release) {
            return back()->withErrors(['msg' => 'Nessun aggiornamento disponibile.']);
        }

        try {
            $bridge = $service->prepareForUpgrade($release);
            
            return Inertia::render('system/upgrade/Launch', [
                'actionUrl' => url('/index.php'),
                'token'     => $bridge['token'],
                'version'   => $release['version']
            ]);

        } catch (\Exception $e) {
            Log::error('Upgrade launch failed', [
                'error'     => $e->getMessage(),
                'version'   => $release['version'] ?? 'unknown'
            ]);
            
            return back()->withErrors(['msg' => $e->getMessage()]);
        }
    }

    /**
     * Conferma post-aggiornamento
     * Punto di arrivo dopo che i file fisici sono stati sostituiti dal processo di update.
     * Confronta la versione registrata nel database con quella attuale dei file di sistema
     * per determinare se è necessario eseguire il processo di finalizzazione (migrazioni).
     */
    public function confirm(GeneralSettings $settings)
    {
        $dbVersion = $settings->version ?? '0.0.0';
        $fileVersion = config('app.version');

        return Inertia::render('system/upgrade/Confirm', [
            'currentVersion'    => $dbVersion,
            'newVersion'        => $fileVersion,
            'needsUpgrade'      => version_compare($fileVersion, $dbVersion, '>'),
        ]);
    }

    /**
     * Finalizzazione aggiornamento
     * Il cuore del processo post-sostituzione file. Applica le modifiche strutturali
     * necessarie (migrazioni del database), allinea la versione nel database, pulisce
     * tutte le cache di sistema per evitare inconsistenze e infine rimuove i file
     * temporanei creati durante il processo di aggiornamento.
     */
    public function run() 
    {
        // Previene il timeout PHP su ambienti Windows / hosting condiviso.
        // Artisan::call('migrate') gira nello stesso processo PHP della richiesta HTTP
        // e quindi eredita il max_execution_time del web server (default 60s).
        // ini_set + set_time_limit coprono entrambi i meccanismi di enforcement
        // (alcuni SAPI Windows rispettano solo uno dei due).
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        try {
            Log::info('Upgrade finalization started');

            // 1. Migrazioni con retry logic
            $this->runMigrationsWithRetry();

           // 2. Aggiornamento versione DB (Bypassiamo Spatie Singleton)
           DB::table('settings')
                ->where('group', 'general')
                ->where('name', 'version')
                ->update(['payload' => json_encode(config('app.version'))]);
            
            // 3. Cache clearing
            Artisan::call('optimize:clear'); 
            Artisan::call('view:clear');
            Artisan::call('route:clear');

            // 4. INVALIDA CACHE MIDDLEWARE
            Cache::forget('system.needs_upgrade');
            
            // AGGIUNTO: Pulisci anche cache aggiornamenti
            $updateService = app(UpdateService::class);
            $updateService->clearUpdateCache();

            Log::info('Upgrade middleware cache invalidated');

            // 4. Storage link
            $this->ensureStorageLink();

            // 5. Cleanup
            $this->cleanupInstallerJunk();
            $this->cleanupOldBackups();

            Log::info('Upgrade completed successfully', [
                'version' => config('app.version')
            ]);

            return Redirect::route('system.upgrade.changelog')
                ->with('success', 'Sistema aggiornato con successo!');

        } catch (\Exception $e) {
            Log::error('Upgrade finalization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Redirect::back()
                ->withErrors(['msg' => 'Errore durante la finalizzazione: ' . $e->getMessage()]);
        }
    }

    /**
     * Changelog
     * Mostra le novità introdotte dall'aggiornamento appena installato,
     * caricando i dati dal file JSON corrispondente alla versione corrente e alla lingua.
     */
    public function showChangelog(GeneralSettings $settings)
    {
        return Inertia::render('system/upgrade/Changelog', [
            'log' => $this->getChangelog($settings)
        ]);
    }

    /**
     * HELPERS
     */
    
    /**
     * Esegue le migrazioni del database con una logica di tolleranza agli errori.
     * Se una migrazione fallisce (es. per un blocco temporaneo del DB), ritenta
     * l'esecuzione fino a un numero massimo di tentativi specificato.
     */
    private function runMigrationsWithRetry(int $maxAttempts = 3): void
    {
        // Le migration vengono eseguite dentro una richiesta HTTP (via Artisan::call),
        // quindi ereditano il max_execution_time del web server.
        // Su Windows / hosting condiviso il default è 60 secondi: troppo poco per
        // migration con data-migration su tabelle grandi.
        // set_time_limit(0) rimuove il limite per tutta la durata di questa chiamata.
        set_time_limit(0);

        $attempts = 0;
        
        while ($attempts < $maxAttempts) {
            try {
                Artisan::call('migrate', ['--force' => true]);
                Log::info("Migrations completed on attempt " . ($attempts + 1));
                return;
                
            } catch (\Exception $e) {
                $attempts++;
                
                if ($attempts >= $maxAttempts) {
                    throw new \Exception("Migration failed after {$maxAttempts} attempts: " . $e->getMessage());
                }
                
                Log::warning("Migration attempt {$attempts} failed, retrying...", [
                    'error' => $e->getMessage()
                ]);
                
                sleep(2);
            }
        }
    }

    /**
     * Verifica e, se necessario, crea il link simbolico per la cartella di storage pubblico.
     * Essenziale affinché i file caricati dall'utente siano accessibili via web
     * anche dopo un aggiornamento che potrebbe aver rimosso la directory `public`.
     */
    private function ensureStorageLink(): void
    {
        $target = storage_path('app/public');
        $link = public_path('storage');

        if (!file_exists($link)) {
            if (@symlink($target, $link)) {
                Log::info('Storage symlink created');
            } else {
                Log::warning('Failed to create storage symlink - check permissions');
            }
        }
    }

    /**
     * Rimuove eventuali script "ponte" o file di setup lasciati dal processo di
     * auto-update nella root del progetto o nella cartella public, controllando il loro
     * contenuto per non eliminare per sbaglio file legittimi.
     */
    private function cleanupInstallerJunk(): void
    {
        $paths = [
            base_path('index.php'),
            public_path('index.php')
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = @file_get_contents($path);
                // Cerca la firma del bridge o il comando di autodistruzione
                if (strpos($content, '410 Gone') !== false || strpos($content, 'Bridge-Only') !== false) {
                    @unlink($path);
                    Log::info('Installer junk removed: ' . $path);
                }
            }
        }
    }

    /**
     * Recupera il contenuto del changelog associato alla versione corrente.
     * Cerca prima la versione tradotta in base alla lingua impostata; se non la trova,
     * tenta un fallback sull'italiano e, in ultima istanza, genera un payload di default.
     */
    private function getChangelog(GeneralSettings $settings): array
    {
        $version = config('app.version');
        $lang = $settings->language ?? 'it';
        
        $path = resource_path("data/changelogs/{$lang}/{$version}.json");

        if (!file_exists($path)) {
            $path = resource_path("data/changelogs/it/{$version}.json");
        }

        if (!file_exists($path)) {
            return [
                'date' => date('d/m/Y'), 
                'version' => $version,   
                'features' => ['Aggiornamento di sistema completato.'],
            ];
        }

        // 1. Leggiamo e decodifichiamo il JSON
        $parsedJson = json_decode(file_get_contents($path), true) ?? [];

        // 2. Se il file JSON è un semplice array di stringhe (manca la chiave 'features')
        if (is_array($parsedJson) && !isset($parsedJson['features'])) {
            return [
                'date' => date('d/m/Y'),
                'version' => $version,
                'features' => $parsedJson, // Inseriamo tutto il JSON dentro features
            ];
        }

        // 3. Se il JSON era già strutturato, facciamo comunque un fallback sicuro
        return [
            'date' => $parsedJson['date'] ?? date('d/m/Y'),
            'version' => $parsedJson['version'] ?? $version,
            'features' => $parsedJson['features'] ?? [],
        ];
    }

    /**
     * Elimina le vecchie directory di backup create durante precedenti processi di
     * aggiornamento, mantenendo solo quelle più recenti (create da meno di 24 ore)
     * per evitare di saturare lo spazio su disco.
     */
    private function cleanupOldBackups(): void
    {
        try {
            $backups = glob(base_path('_km_safe_zone*'));
            
            foreach ($backups as $dir) {
                if (is_dir($dir) && (time() - filemtime($dir) > 86400)) {
                    File::deleteDirectory($dir);
                    Log::info('Old backup removed', ['path' => basename($dir)]);
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('Backup cleanup failed', ['error' => $e->getMessage()]);
        }
    }
}
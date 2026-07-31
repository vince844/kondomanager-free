<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\Backup\BackupManager;
use App\Services\Backup\Exceptions\BackupInProgressException;
use App\Services\System\SystemFinalizer;
use App\Services\UpdateService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

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
        if (! $service->isAutoUpdateEnabled()) {
            return Inertia::render('system/upgrade/Disabled', [
                'reason' => 'manual_installation',
                'message' => 'Gli aggiornamenti automatici non sono disponibili per installazioni manuali. Per aggiornare, segui la procedura manuale.',
            ]);
        }

        return Inertia::render('system/upgrade/Index', [
            'currentVersion' => config('app.version'),
            'availableRelease' => $service->checkRemoteVersion(),
            'inProgress' => $service->isUpgradeInProgress(),
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
        if (! $service->isAutoUpdateEnabled()) {
            return back()->withErrors([
                'msg' => 'Gli aggiornamenti automatici non sono disponibili. Usa la procedura manuale.',
            ]);
        }

        $release = $service->checkRemoteVersion();

        if (! $release) {
            return back()->withErrors(['msg' => 'Nessun aggiornamento disponibile.']);
        }

        try {
            $bridge = $service->prepareForUpgrade($release);

            return Inertia::render('system/upgrade/Launch', [
                'actionUrl' => url('/index.php'),
                'token' => $bridge['token'],
                'version' => $release['version'],
            ]);

        } catch (\Exception $e) {
            Log::error('Upgrade launch failed', [
                'error' => $e->getMessage(),
                'version' => $release['version'] ?? 'unknown',
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
            'currentVersion' => $dbVersion,
            'newVersion' => $fileVersion,
            'needsUpgrade' => version_compare($fileVersion, $dbVersion, '>'),
            'canBackup' => $this->preUpgradeBackupAvailable($dbVersion),
        ]);
    }

    /**
     * Il backup di sicurezza gira PRIMA delle migrazioni, quindi può contare
     * solo sull'infrastruttura backup GIÀ presente nel database che sta per
     * essere migrato — non su quella che le migrazioni in arrivo creeranno.
     *
     * Chi aggiorna da una versione anteriore alla 1.10 quella tabella non ce
     * l'ha, e non gliela creiamo al volo: il primo aggiornamento alla 1.10
     * resta senza rete automatica — esattamente come ogni aggiornamento fatto
     * fino ad oggi — e la pagina di conferma gli chiede una copia del database
     * dal pannello dell'hosting.
     *
     * Il controllo si riapre da solo: dalla 1.11 in poi si aggiorna partendo
     * da una 1.10.0 o successiva, la tabella c'è, e il backup automatico torna
     * disponibile senza che nessuno debba ricordarsi di togliere un interruttore.
     */
    private function preUpgradeBackupAvailable(string $dbVersion): bool
    {
        return version_compare($dbVersion, '1.10.0', '>=')
            && Schema::hasTable('backups');
    }

    /**
     * Avvia un backup di sicurezza (solo database) PRIMA delle migrazioni.
     * Endpoint dedicato al flusso di aggiornamento: protetto dal ruolo
     * amministratore (gruppo rotte upgrade), indipendente dal permesso e dal
     * kill-switch della feature backup — è una rete di sicurezza, non la
     * funzione backup dell'utente. Il frontend poi lo fa avanzare via
     * backupStep() finché non è completato, quindi lancia le migrazioni.
     */
    public function backupStart(BackupManager $manager, GeneralSettings $settings): JsonResponse
    {
        // Stessa condizione della pagina di conferma: le due parti non possono
        // discordare su quando il backup pre-aggiornamento è disponibile.
        abort_unless(
            $this->preUpgradeBackupAvailable($settings->version ?? '0.0.0'),
            409,
            'Infrastruttura di backup non disponibile.'
        );

        // Il backup di sicurezza gira PRIMA delle migrazioni: se si aggiorna da
        // una versione anteriore alla beta.12, la tabella backups non ha ancora
        // le colonne type/encrypted usate dal motore di backup. Le allineiamo
        // qui (sola infrastruttura, nessun dato utente toccato) per non fallire.
        $this->ensureBackupsMetadataColumns();

        // Un backup di sicurezza già in corso (ripresa dopo un reload): riusalo.
        $running = $manager->runningBackup();

        if ($running !== null) {
            return response()->json(['backup' => $this->presentUpgradeBackup($running)]);
        }

        try {
            $backup = $manager->start(Auth::user(), ['type' => Backup::TYPE_DB_ONLY]);
        } catch (BackupInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['backup' => $this->presentUpgradeBackup($backup)]);
    }

    public function backupStep(Backup $backup, BackupManager $manager): JsonResponse
    {
        $manager->runStep($backup);

        return response()->json(['backup' => $this->presentUpgradeBackup($backup->refresh())]);
    }

    /**
     * Allinea le colonne di metadati della tabella backups (type/encrypted)
     * introdotte nella beta.12. Serve al solo backup di sicurezza pre-upgrade,
     * che gira prima delle migrazioni: aggiornando da una versione più vecchia
     * quelle colonne non esistono ancora e la creazione del record fallirebbe
     * con "Unknown column". È idempotente e non tocca alcun dato utente; la
     * migration dedicata resta la fonte di verità e diventa un no-op quando poi
     * verrà eseguita (è guardata con hasColumn).
     */
    private function ensureBackupsMetadataColumns(): void
    {
        if (! Schema::hasColumn('backups', 'type')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->string('type', 20)->default('full')->after('status');
            });
        }

        if (! Schema::hasColumn('backups', 'encrypted')) {
            Schema::table('backups', function (Blueprint $table) {
                $table->boolean('encrypted')->default(false)->after('type');
            });
        }
    }

    /**
     * Proiezione minima del backup di sicurezza per la barra di avanzamento
     * della pagina di conferma aggiornamento.
     */
    private function presentUpgradeBackup(Backup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'status' => $backup->status->value,
            'percent' => $backup->progress['percent'] ?? 0,
            'error' => $backup->error,
        ];
    }

    /**
     * Finalizzazione aggiornamento
     * Il cuore del processo post-sostituzione file. Applica le modifiche strutturali
     * necessarie (migrazioni del database), allinea la versione nel database, pulisce
     * tutte le cache di sistema per evitare inconsistenze e infine rimuove i file
     * temporanei creati durante il processo di aggiornamento.
     */
    public function run(SystemFinalizer $finalizer)
    {
        try {
            Log::info('Upgrade finalization started');

            // Migrazioni con retry + versione DB + cache + storage link:
            // logica condivisa con il ripristino dei backup (SystemFinalizer,
            // vedi docs/ripristino_backup_design.md §7-bis).
            $finalizer->finalize();

            Log::info('Upgrade middleware cache invalidated');

            // Cleanup specifici del processo di auto-update
            $this->cleanupInstallerJunk();
            $this->cleanupOldBackups();

            Log::info('Upgrade completed successfully', [
                'version' => config('app.version'),
            ]);

            return Redirect::route('system.upgrade.changelog')
                ->with('success', 'Sistema aggiornato con successo!');

        } catch (\Exception $e) {
            Log::error('Upgrade finalization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Redirect::back()
                ->withErrors(['msg' => 'Errore durante la finalizzazione: '.$e->getMessage()]);
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
            'log' => $this->getChangelog($settings),
        ]);
    }

    /**
     * HELPERS
     */

    /**
     * Rimuove eventuali script "ponte" o file di setup lasciati dal processo di
     * auto-update nella root del progetto o nella cartella public, controllando il loro
     * contenuto per non eliminare per sbaglio file legittimi.
     */
    private function cleanupInstallerJunk(): void
    {
        $paths = [
            base_path('index.php'),
            public_path('index.php'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = @file_get_contents($path);
                // Cerca la firma del bridge o il comando di autodistruzione
                if (strpos($content, '410 Gone') !== false || strpos($content, 'Bridge-Only') !== false) {
                    @unlink($path);
                    Log::info('Installer junk removed: '.$path);
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

        if (! file_exists($path)) {
            $path = resource_path("data/changelogs/it/{$version}.json");
        }

        if (! file_exists($path)) {
            return [
                'date' => date('d/m/Y'),
                'version' => $version,
                'features' => ['Aggiornamento di sistema completato.'],
            ];
        }

        // 1. Leggiamo e decodifichiamo il JSON
        $parsedJson = json_decode(file_get_contents($path), true) ?? [];

        // 2. Se il file JSON è un semplice array di stringhe (manca la chiave 'features')
        if (is_array($parsedJson) && ! isset($parsedJson['features'])) {
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

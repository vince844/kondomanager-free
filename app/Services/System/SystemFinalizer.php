<?php

namespace App\Services\System;

use App\Services\UpdateService;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Allinea l'installazione al codice corrente: migrazioni, versione nel
 * database, cache, symlink dello storage.
 *
 * È la logica di finalizzazione post-aggiornamento estratta da
 * SystemUpgradeController::run() per essere riusata dal RIPRISTINO dei
 * backup (beta.13, design doc §7-bis): dopo l'import di un backup più
 * vecchio, il database si trova ESATTAMENTE nella condizione post-update
 * ("file più nuovi del database") e la cura è la stessa. Il ripristino non
 * può affidarsi al middleware CheckForPendingUpdates (attivo solo con
 * run_installer=true e solo al login di un admin): chiama questo servizio
 * in modo sincrono nella fase di finalizzazione.
 */
class SystemFinalizer
{
    /**
     * Esegue l'intera finalizzazione. Idempotente: rieseguirla su
     * un'installazione già allineata non fa danni (migrate senza migrazioni
     * pendenti è un no-op, la versione viene semplicemente riscritta).
     */
    public function finalize(): void
    {
        // Artisan::call gira nello stesso processo PHP della richiesta HTTP
        // e ne eredita il max_execution_time (60s tipici su hosting
        // condiviso/Windows): troppo poco per migration con data-migration.
        set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $this->runMigrationsWithRetry();
        $this->alignDatabaseVersion();
        $this->clearSystemCaches();
        $this->ensureStorageLink();
    }

    /**
     * Migrazioni con tolleranza agli errori transitori (es. lock temporanei
     * del database): riprova fino a $maxAttempts prima di arrendersi.
     */
    public function runMigrationsWithRetry(int $maxAttempts = 3): void
    {
        set_time_limit(0);

        $attempts = 0;

        while ($attempts < $maxAttempts) {
            try {
                Artisan::call('migrate', ['--force' => true]);
                Log::info('Migrations completed on attempt '.($attempts + 1));

                return;
            } catch (Exception $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw new Exception("Migration failed after {$maxAttempts} attempts: ".$e->getMessage());
                }

                Log::warning("Migration attempt {$attempts} failed, retrying...", [
                    'error' => $e->getMessage(),
                ]);

                sleep(2);
            }
        }
    }

    /**
     * Riallinea la versione registrata nel database a quella dei file.
     * Update diretto sulla tabella (bypass del singleton spatie): la cache
     * dei settings potrebbe contenere ancora la versione vecchia.
     */
    public function alignDatabaseVersion(): void
    {
        DB::table('settings')
            ->where('group', 'general')
            ->where('name', 'version')
            ->update(['payload' => json_encode(config('app.version'))]);
    }

    public function clearSystemCaches(): void
    {
        Artisan::call('optimize:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');

        // Invalida il check del middleware CheckForPendingUpdates e la
        // cache del servizio aggiornamenti.
        Cache::forget('system.needs_upgrade');
        app(UpdateService::class)->clearUpdateCache();
    }

    /**
     * Verifica e, se serve, ricrea il symlink public/storage: essenziale
     * dopo un aggiornamento (la cartella public può essere stata sostituita)
     * e dopo un ripristino/trasferimento su server nuovo.
     */
    public function ensureStorageLink(): void
    {
        $target = storage_path('app/public');
        $link = public_path('storage');

        if (! file_exists($link)) {
            if (@symlink($target, $link)) {
                Log::info('Storage symlink created');
            } else {
                Log::warning('Failed to create storage symlink - check permissions');
            }
        }
    }
}

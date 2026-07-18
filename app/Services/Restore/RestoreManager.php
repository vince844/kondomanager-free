<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Enums\RestoreStatus;
use App\Events\Restore\RestoreCompleted;
use App\Events\Restore\RestoreFailed;
use App\Events\Restore\RestoreStarted;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\Exceptions\RestoreInProgressException;
use App\Services\System\SystemFinalizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Orchestratore del ripristino: macchina a stati riprendibile, speculare al
 * BackupManager ma con lo stato su FILE (l'import sovrascrive il database).
 *
 * Fasi: pending → safety_backup → extracting → verifying →
 * importing_database → restoring_files → finalizing → completed | failed.
 *
 * Vedi docs/ripristino_backup_design.md per le decisioni di design. I punti
 * cardine: safety backup PRIMA di toccare il DB, import via tokenizer
 * riprendibile, file DOPO il database (ordine deliberato: c'è sempre una via
 * di ritorno), finalizzazione con sonda di decifratura (APP_KEY diversa →
 * pulizia 2FA/SMTP) e ri-registrazione degli archivi da disco.
 */
class RestoreManager
{
    public function __construct(
        private readonly RestoreState $state,
        private readonly RestoreLock $lock,
        private readonly RestoreMode $mode,
        private readonly RestorePreflight $preflight,
        private readonly DatabaseImporter $importer,
        private readonly BackupManager $backupManager,
        private readonly SystemFinalizer $finalizer,
    ) {}

    /**
     * Avvia un ripristino. Valida (throws su incompatibilità), acquisisce il
     * lock, crea lo stato ed entra in modalità ripristino. Restituisce il
     * token di step in CHIARO (una volta sola): il chiamante lo consegna al
     * browser che dovrà autenticare gli step successivi.
     *
     * @param  array{safety_backup?: bool, adopt_app_key?: bool}  $options
     * @return array{uuid: string, token: string, manifest: array}
     */
    public function start(string $archivePath, ?string $password, array $options, ?User $user = null): array
    {
        $this->failStale();

        if ($this->isRunning()) {
            throw new RestoreInProgressException('Un ripristino è già in corso.');
        }

        // Mutua esclusione col backup. Interroga il database, che potrebbe non
        // avere ancora la tabella backups (ripristino dal wizard su DB fresco):
        // in quel caso non c'è alcun backup in corso per definizione.
        try {
            $backupRunning = $this->backupManager->runningBackup() !== null;
        } catch (Throwable) {
            $backupRunning = false;
        }

        if ($backupRunning) {
            throw new RestoreInProgressException('Un backup è in esecuzione: attendi che finisca prima di ripristinare.');
        }

        $reader = new ArchiveReader($archivePath, $password);
        $manifest = $reader->readManifest();            // valida anche la password
        $archiveBytes = (int) filesize($archivePath);
        $this->preflight->inspect($manifest, $archiveBytes); // throws su incompatibilità
        $reader->close();

        if (! $this->lock->acquire()) {
            throw new RestoreInProgressException('Impossibile acquisire il lock di ripristino.');
        }

        $uuid = (string) Str::uuid();

        $this->state->put([
            'uuid' => $uuid,
            'phase' => RestoreStatus::PENDING->value,
            'archive_path' => $archivePath,
            'password_enc' => ($password !== null && $password !== '') ? Crypt::encryptString($password) : null,
            'options' => [
                'safety_backup' => (bool) ($options['safety_backup'] ?? true),
                'adopt_app_key' => (bool) ($options['adopt_app_key'] ?? false),
            ],
            'manifest' => $manifest,
            'checkpoint' => [],
            'outcome' => [],
            'created_by' => $user?->id,
            'locale' => app()->getLocale(),
            'started_at' => time(),
        ]);

        $token = $this->state->issueToken($this->staleSeconds());

        $this->mode->enter($uuid, app()->getLocale());

        event(new RestoreStarted($uuid, $manifest));

        return ['uuid' => $uuid, 'token' => $token, 'manifest' => $manifest];
    }

    /**
     * Fa avanzare il ripristino di uno step entro il budget di tempo.
     * Idempotente rispetto al polling: se un altro processo detiene il lock,
     * restituisce lo stato corrente senza avanzare.
     */
    public function runStep(): ?array
    {
        $state = $this->state->get();

        if ($state === null || ! $this->statusOf($state)->isRunning()) {
            return $state;
        }

        if (! $this->lock->acquire()) {
            return $state; // un altro step è in volo
        }

        $budgetSeconds = max(5, (int) config('backup.step_time_budget', 20));
        @set_time_limit($budgetSeconds + 30);
        ignore_user_abort(true);

        try {
            $budget = new StepBudget($budgetSeconds);

            while (! $budget->exceeded() && $this->statusOf($state)->isRunning()) {
                $state = $this->advance($state, $budget);
            }
        } catch (Throwable $e) {
            $state = $this->fail($state, $e);
        } finally {
            $this->lock->release();
        }

        return $state;
    }

    public function current(): ?array
    {
        return $this->state->get();
    }

    /**
     * Riprende un ripristino FALLITO dal punto in cui si era interrotto:
     * riporta la fase a quella pre-fallimento (il cui checkpoint — offset
     * import, indice file — è già salvato) e rilancia gli step. Utile quando
     * l'errore era transitorio o è stato corretto (deploy di una fix).
     */
    public function resume(): ?array
    {
        $state = $this->state->get();

        if ($state === null) {
            return null;
        }

        if ($this->statusOf($state)->isRunning()) {
            return $this->runStep(); // già in corso: normale avanzamento
        }

        if ($this->statusOf($state) !== RestoreStatus::FAILED) {
            return $state; // completato o già chiuso: niente da riprendere
        }

        // La modalità potrebbe non essere mai stata spenta (fail non la spegne),
        // ma la riaccendiamo per sicurezza nel caso di un abort precedente.
        if (! $this->mode->active()) {
            $this->mode->enter($state['uuid'] ?? (string) Str::uuid(), $state['locale'] ?? null);
        }

        $this->state->merge([
            'phase' => $state['failed_phase'] ?? RestoreStatus::PENDING->value,
            'error' => null,
            'failed_at' => null,
            'resumed_at' => time(),
        ]);

        return $this->runStep();
    }

    /**
     * Annulla un ripristino: spegne la modalità (l'app torna raggiungibile) e
     * segna lo stato come annullato. NON tenta un rollback dei dati — il
     * database potrebbe restare in uno stato parziale, e la UI lo avvisa.
     */
    public function abort(): void
    {
        try {
            $state = $this->state->get();

            if ($state !== null) {
                // Se la fase file aveva spostato gli originali in tmp/old, va
                // fatto il rollback PRIMA di cancellare tmp: altrimenti
                // cleanupTemp distruggerebbe l'unica copia dei file utente
                // (il safety backup potrebbe essere db_only o disattivato).
                $this->rollbackFiles($state);
                $this->cleanupTemp($state);
                $this->state->merge([
                    'phase' => RestoreStatus::FAILED->value,
                    'aborted' => true,
                    'aborted_at' => time(),
                ]);
            }
        } catch (Throwable) {
            // best effort: l'importante è spegnere la modalità qui sotto
        }

        $this->mode->exit();
    }

    /* ------------------------------------------------------------------
     | Avanzamento
     | ------------------------------------------------------------------ */

    private function advance(array $state, StepBudget $budget): array
    {
        return match ($this->statusOf($state)) {
            RestoreStatus::PENDING => $this->advancePending($state),
            RestoreStatus::SAFETY_BACKUP => $this->advanceSafetyBackup($state, $budget),
            RestoreStatus::EXTRACTING => $this->advanceExtracting($state, $budget),
            RestoreStatus::VERIFYING => $this->advanceVerifying($state),
            RestoreStatus::IMPORTING_DATABASE => $this->advanceImporting($state, $budget),
            RestoreStatus::RESTORING_FILES => $this->advanceRestoringFiles($state, $budget),
            RestoreStatus::FINALIZING => $this->advanceFinalizing($state),
            default => $state,
        };
    }

    private function advancePending(array $state): array
    {
        $next = ($state['options']['safety_backup'] ?? true)
            ? RestoreStatus::SAFETY_BACKUP
            : RestoreStatus::EXTRACTING;

        return $this->transition($state, $next);
    }

    private function advanceSafetyBackup(array $state, StepBudget $budget): array
    {
        $uuid = $state['checkpoint']['safety_uuid'] ?? null;
        $backup = $uuid ? Backup::firstWhere('uuid', $uuid) : null;

        if ($backup === null) {
            // Se stiamo ripristinando un archivio FULL, il safety backup deve
            // essere anch'esso FULL: altrimenti storage/app (che la fase file
            // sovrascrive) non avrebbe alcun punto di rollback. Per un archivio
            // solo-database basta un db_only. (I manifest <= beta.11 non hanno
            // la chiave 'contents': default FULL, corretto per quei backup.)
            $safetyType = ($state['manifest']['contents'] ?? Backup::TYPE_FULL) === Backup::TYPE_DB_ONLY
                ? Backup::TYPE_DB_ONLY
                : Backup::TYPE_FULL;

            $backup = $this->backupManager->start(
                $uuid ? null : User::find($state['created_by'] ?? null),
                ['type' => $safetyType]
            );
            $state = $this->patchCheckpoint($state, ['safety_uuid' => $backup->uuid]);
        }

        // Guida il backup di sicurezza fin dove il budget lo consente; su un
        // db_only tipico si completa in uno o due giri.
        while (! $budget->exceeded() && $backup->isRunning()) {
            $backup = $this->backupManager->runStep($backup);
        }

        if ($backup->isRunning()) {
            return $this->state->get(); // riprende al prossimo step
        }

        return $this->transition($state, RestoreStatus::EXTRACTING);
    }

    private function advanceExtracting(array $state, StepBudget $budget): array
    {
        $reader = $this->reader($state);
        $target = $this->extractDir($state);
        $fromIndex = (int) ($state['checkpoint']['extract_index'] ?? 0);

        // Un batch per chiamata: il ciclo di runStep richiama finché budget.
        $result = $reader->extractBatch($target, $fromIndex, maxEntries: 100);
        $reader->close();

        $state = $this->patchCheckpoint($state, ['extract_index' => $result['nextIndex']]);

        if ($result['done']) {
            return $this->transition($state, RestoreStatus::VERIFYING);
        }

        return $state;
    }

    private function advanceVerifying(array $state): array
    {
        $dumpPath = $this->extractDir($state).'/db/database.sql';

        if (! is_file($dumpPath)) {
            throw new \RuntimeException("Il dump del database manca dall'archivio estratto.");
        }

        $expected = $state['manifest']['database']['dump_sha256'] ?? null;

        if (is_string($expected) && $expected !== '') {
            $actual = hash_file('sha256', $dumpPath);

            if (! hash_equals($expected, (string) $actual)) {
                throw new \RuntimeException(
                    'Il dump del database non supera la verifica di integrità (SHA-256): archivio corrotto o manomesso.'
                );
            }
        }

        return $this->transition($state, RestoreStatus::IMPORTING_DATABASE);
    }

    private function advanceImporting(array $state, StepBudget $budget): array
    {
        // 1) Azzeramento schema (una volta sola)
        if (! ($state['checkpoint']['wiped'] ?? false)) {
            $this->importer->wipe();
            $state = $this->patchCheckpoint($state, ['wiped' => true, 'import' => ['offset' => 0, 'delimiter' => ';']]);
        }

        // 2) Import a step dal checkpoint
        $dumpPath = $this->extractDir($state).'/db/database.sql';
        $result = $this->importer->importStep($dumpPath, $state['checkpoint']['import'] ?? [], $budget);

        $state = $this->patchCheckpoint($state, ['import' => $result['checkpoint']]);

        if ($result['done']) {
            $next = ($state['manifest']['contents'] ?? 'full') === 'db_only'
                ? RestoreStatus::FINALIZING
                : RestoreStatus::RESTORING_FILES;

            return $this->transition($state, $next);
        }

        return $state;
    }

    private function advanceRestoringFiles(array $state, StepBudget $budget): array
    {
        $extractedRoot = $this->extractDir($state).'/files/storage/app';

        // Niente file utente nell'archivio (raro ma possibile): salta.
        if (! is_dir($extractedRoot)) {
            return $this->transition($state, RestoreStatus::FINALIZING);
        }

        $liveRoot = storage_path('app');

        // 1) Metti da parte i file correnti (una volta): rollback possibile.
        if (! ($state['checkpoint']['files_set_aside'] ?? false)) {
            $this->setCurrentFilesAside($state, $liveRoot);
            $state = $this->patchCheckpoint($state, ['files_set_aside' => true, 'file_index' => 0]);
        }

        // 2) Copia i file estratti sopra storage/app, a batch riprendibile.
        $files = $this->extractedFileList($extractedRoot);
        $index = (int) ($state['checkpoint']['file_index'] ?? 0);
        $copied = 0;

        while ($index < count($files) && ! $budget->exceeded() && $copied < 500) {
            $relative = $files[$index];
            $source = $extractedRoot.'/'.$relative;
            $destination = $liveRoot.'/'.$relative;

            File::ensureDirectoryExists(dirname($destination));
            File::copy($source, $destination);

            $index++;
            $copied++;
        }

        $state = $this->patchCheckpoint($state, ['file_index' => $index]);

        if ($index >= count($files)) {
            return $this->transition($state, RestoreStatus::FINALIZING);
        }

        return $state;
    }

    private function advanceFinalizing(array $state): array
    {
        $outcome = $state['outcome'] ?? [];

        // a) Adozione dell'APP_KEY dall'archivio (se richiesta)
        if (($state['options']['adopt_app_key'] ?? false) && ! ($state['checkpoint']['app_key_adopted'] ?? false)) {
            $adopted = $this->adoptAppKey($state);
            $outcome['app_key_adopted'] = $adopted;
            $state = $this->patchCheckpoint($state, ['app_key_adopted' => true]);
        }

        // b) Finalizzazione di sistema: migrazioni + versione DB + cache + storage link
        $this->finalizer->finalize();

        // c) Sonda di decifratura: se l'APP_KEY non decifra i dati importati,
        //    azzera 2FA e password SMTP (altrimenti login in 500 / mail rotta)
        $cleanup = $this->decryptionProbeAndCleanup();
        if ($cleanup !== []) {
            $outcome['cleanup'] = $cleanup;
        }

        // d) Azzera lo stato di sessione/cache ereditato dal backup
        $this->resetVolatileState();

        // e) Ri-registra gli archivi presenti su disco (la tabella backups è
        //    stata importata come sola struttura: nessun record di backup)
        $outcome['reregistered_backups'] = $this->reregisterOrphanBackups();

        // f) Pulizia file temporanei del ripristino
        $this->cleanupTemp($state);

        // g) Esito e uscita dalla modalità ripristino
        $state = $this->state->merge([
            'phase' => RestoreStatus::COMPLETED->value,
            'outcome' => $outcome,
            'completed_at' => time(),
        ]);

        $this->mode->exit();

        event(new RestoreCompleted($state['uuid'], $outcome));

        return $state;
    }

    /* ------------------------------------------------------------------
     | Finalizzazione: sotto-operazioni
     | ------------------------------------------------------------------ */

    private function adoptAppKey(array $state): bool
    {
        $envInArchive = $this->extractDir($state).'/files/.env';

        if (! is_file($envInArchive)) {
            return false;
        }

        if (! preg_match('/^APP_KEY=(.*)$/m', (string) file_get_contents($envInArchive), $matches)) {
            return false;
        }

        $newKey = trim($matches[1]);
        $livePath = base_path('.env');
        $content = file_get_contents($livePath);

        // preg_replace_callback (non preg_replace) per non interpretare $ e
        // cifre nella chiave come backreference.
        $content = preg_match('/^APP_KEY=.*$/m', (string) $content)
            ? preg_replace_callback('/^APP_KEY=.*$/m', fn () => 'APP_KEY='.$newKey, (string) $content)
            : rtrim((string) $content).PHP_EOL.'APP_KEY='.$newKey.PHP_EOL;

        file_put_contents($livePath, $content);

        // La password salvata dei backup era cifrata con la VECCHIA APP_KEY:
        // ora è illeggibile, va rimossa (l'admin la re-imposta).
        $passwordFile = config('backup.password_file');
        if (is_string($passwordFile) && is_file($passwordFile)) {
            @unlink($passwordFile);
        }

        // Applica la nuova chiave a runtime, così i passi successivi della
        // finalizzazione usano già quella giusta.
        config(['app.key' => str_starts_with($newKey, 'base64:')
            ? base64_decode(substr($newKey, 7))
            : $newKey]);

        // Il singleton 'encrypter' è GIÀ stato risolto con la vecchia chiave a
        // inizio richiesta (EncryptCookies del gruppo web, MailConfigProvider):
        // un semplice config() non lo ricostruisce. Va dimenticato, altrimenti
        // la sonda di decifratura decifrerebbe con la chiave sbagliata e
        // azzererebbe proprio i dati 2FA/SMTP che l'adozione deve preservare.
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();

        return true;
    }

    /**
     * Prova a decifrare un campione dei dati cifrati con APP_KEY importati
     * (segreto 2FA, password SMTP). Se fallisce, la chiave non corrisponde:
     * azzera quei dati per non lasciare gli utenti chiusi fuori (il login 2FA
     * lancia DecryptException → 500) e la mail rotta.
     *
     * @return array<string, int|bool> cosa è stato ripulito (vuoto se nulla)
     */
    private function decryptionProbeAndCleanup(): array
    {
        $cleanup = [];
        $schema = DB::getSchemaBuilder();

        // 2FA (la tabella users c'è sempre in un'installazione vera; il guard
        // copre backup parziali o schemi inattesi)
        if ($schema->hasTable('users') && $schema->hasColumn('users', 'two_factor_secret')) {
            $twoFactorUsers = DB::table('users')->whereNotNull('two_factor_secret')->pluck('two_factor_secret', 'id');

            if ($twoFactorUsers->isNotEmpty() && ! $this->canDecrypt((string) $twoFactorUsers->first())) {
                DB::table('users')->whereNotNull('two_factor_secret')->update([
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ]);
                $cleanup['two_factor_reset_users'] = $twoFactorUsers->count();
            }
        }

        // Password SMTP nelle impostazioni (group=mail, name=mail_password)
        if ($schema->hasTable('settings')) {
            $mailPasswordRow = DB::table('settings')
                ->where('group', 'mail')
                ->where('name', 'mail_password')
                ->first();

            if ($mailPasswordRow !== null) {
                $encrypted = json_decode($mailPasswordRow->payload, true);

                if (is_string($encrypted) && $encrypted !== '' && ! $this->canDecrypt($encrypted)) {
                    DB::table('settings')
                        ->where('group', 'mail')
                        ->where('name', 'mail_password')
                        ->update(['payload' => json_encode('')]);
                    $cleanup['smtp_password_cleared'] = true;
                }
            }
        }

        return $cleanup;
    }

    private function canDecrypt(string $ciphertext): bool
    {
        try {
            Crypt::decryptString($ciphertext);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function resetVolatileState(): void
    {
        // sessions/cache + code (jobs/job_batches/failed_jobs): stato volatile
        // che nel dump di un backup finisce coi suoi dati. Reimportarlo farebbe
        // rieseguire job stantii e lascerebbe failed_jobs vecchi: va azzerato.
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $table) {
            try {
                DB::table($table)->truncate();
            } catch (Throwable) {
                // La tabella potrebbe non esistere (driver di sessione/cache/coda
                // diverso da database): non è un errore per il ripristino.
            }
        }

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // idem
        }
    }

    /**
     * Scansiona il disco backups e reinserisce nella tabella (ora vuota) un
     * record per ogni archivio presente, leggendone il manifest. È la stessa
     * tecnica usata per recuperare i record cancellati per errore, promossa a
     * funzione: dopo un ripristino la lista backup non deve restare vuota.
     */
    private function reregisterOrphanBackups(): int
    {
        $disk = config('backup.disk', 'backups');
        $root = config('filesystems.disks.'.$disk.'.root', storage_path('app/backups'));

        // La tabella backups fa parte di ogni archivio (solo struttura); il
        // guard copre backup atipici che non la contengono.
        if (! is_dir($root) || ! DB::getSchemaBuilder()->hasTable('backups')) {
            return 0;
        }

        $registered = 0;
        $archives = glob(rtrim($root, '/').'/*.zip') ?: [];

        foreach ($archives as $archivePath) {
            $filename = basename($archivePath);

            if (Backup::where('filename', $filename)->exists()) {
                continue;
            }

            $reader = new ArchiveReader($archivePath);
            $manifest = null;

            try {
                $manifest = $reader->readManifest();
            } catch (Throwable) {
                // Archivio cifrato o illeggibile: registriamo quel che si può.
            } finally {
                $reader->close();
            }

            $uuid = $manifest['backup_uuid'] ?? (string) Str::uuid();

            // updateOrCreate (non create) per non violare il vincolo UNIQUE su
            // uuid: i backup creati con versioni <= beta.11 includevano i DATI
            // della tabella backups nel dump, quindi dopo l'import la riga con
            // questo uuid può già esistere — magari con un filename non allineato
            // al file su disco (per cui il controllo per filename qui sopra non
            // l'ha intercettata). In quel caso la riconciliamo ai valori reali
            // dell'archivio invece di duplicarla.
            Backup::updateOrCreate(
                ['uuid' => $uuid],
                [
                    'filename' => $filename,
                    'disk' => $disk,
                    'status' => BackupStatus::COMPLETED,
                    'type' => $manifest['contents'] ?? Backup::TYPE_FULL,
                    'encrypted' => $manifest === null ? true : (bool) ($manifest['encrypted'] ?? false),
                    'manifest' => $manifest,
                    'size' => filesize($archivePath) ?: null,
                    'checksum' => hash_file('sha256', $archivePath) ?: null,
                    'progress' => ['percent' => 100, 'phase' => 'completed', 'detail' => null],
                    'completed_at' => now(),
                ]
            );

            $registered++;
        }

        // Riconcilia l'altro verso: elimina le righe COMPLETED ereditate dal
        // dump (backup <= beta.11 includevano i DATI della tabella backups) che
        // puntano ad archivi non più presenti su disco — altrimenti restano
        // voci fantasma nell'elenco che darebbero 404 a download/ripristino.
        $onDisk = array_map('basename', $archives);
        Backup::query()
            ->where('status', BackupStatus::COMPLETED->value)
            ->whereNotNull('filename')
            ->whereNotIn('filename', $onDisk ?: ['__nessun_archivio__'])
            ->delete();

        return $registered;
    }

    /* ------------------------------------------------------------------
     | File: setting aside + lista
     | ------------------------------------------------------------------ */

    /**
     * Rollback della fase file: rimette gli originali (spostati in tmp/old da
     * setCurrentFilesAside) al loro posto in storage/app, rimuovendo prima i
     * file parzialmente ripristinati. No-op se i file non erano stati spostati.
     * Best effort per-operazione: un intoppo su una voce non blocca lo sblocco.
     */
    private function rollbackFiles(array $state): void
    {
        if (! ($state['checkpoint']['files_set_aside'] ?? false)) {
            return; // la fase file non era mai iniziata: niente da ripristinare
        }

        $oldRoot = $this->tmpRoot($state).'/old/storage-app';

        if (! is_dir($oldRoot)) {
            return;
        }

        $liveRoot = storage_path('app');
        $backupsDirName = basename(config('filesystems.disks.'.config('backup.disk', 'backups').'.root', 'backups'));

        // 1) Rimuovi i file/dir parzialmente ripristinati (mai la dir backups)
        foreach (File::directories($liveRoot) as $directory) {
            if (basename($directory) === $backupsDirName) {
                continue;
            }
            try {
                File::deleteDirectory($directory);
            } catch (Throwable) {
            }
        }
        foreach (File::files($liveRoot) as $file) {
            @unlink($file->getPathname());
        }

        // 2) Rimetti gli originali al loro posto
        foreach (File::directories($oldRoot) as $directory) {
            try {
                File::moveDirectory($directory, $liveRoot.'/'.basename($directory));
            } catch (Throwable) {
            }
        }
        foreach (File::files($oldRoot) as $file) {
            try {
                File::move($file->getPathname(), $liveRoot.'/'.$file->getFilename());
            } catch (Throwable) {
            }
        }
    }

    private function setCurrentFilesAside(array $state, string $liveRoot): void
    {
        $oldRoot = $this->tmpRoot($state).'/old/storage-app';
        File::ensureDirectoryExists($oldRoot);

        $backupsDirName = basename(config('filesystems.disks.'.config('backup.disk', 'backups').'.root', 'backups'));

        foreach (File::directories($liveRoot) as $directory) {
            $name = basename($directory);

            // storage/app/backups non si tocca MAI (archivi, stato, tmp del
            // ripristino stesso, password salvata).
            if ($name === $backupsDirName) {
                continue;
            }

            File::moveDirectory($directory, $oldRoot.'/'.$name);
        }

        foreach (File::files($liveRoot) as $file) {
            File::move($file->getPathname(), $oldRoot.'/'.$file->getFilename());
        }
    }

    /**
     * @return array<int, string> percorsi relativi a files/storage/app
     */
    private function extractedFileList(string $extractedRoot): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $prefix = strlen($extractedRoot) + 1;

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = substr($item->getPathname(), $prefix);
            }
        }

        sort($files); // ordine stabile: la ripresa per indice è deterministica

        return $files;
    }

    /* ------------------------------------------------------------------
     | Terminazione / errori
     | ------------------------------------------------------------------ */

    private function fail(array $state, Throwable $e): array
    {
        // La modalità ripristino RESTA attiva: un'app col database a metà
        // import non deve tornare raggiungibile. Rimozione solo su ripresa
        // riuscita o rollback esplicito.
        report($e);

        $failed = $this->state->merge([
            'phase' => RestoreStatus::FAILED->value,
            // La fase in cui eravamo, per poter riprendere da lì (il checkpoint
            // di quella fase è già salvato: offset import, indice file, ecc.).
            'failed_phase' => $state['phase'] ?? null,
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'failed_at' => time(),
        ]);

        event(new RestoreFailed($state['uuid'] ?? '?', $state['phase'] ?? '?', $e->getMessage()));

        return $failed;
    }

    private function cleanupTemp(array $state): void
    {
        File::deleteDirectory($this->tmpRoot($state));
    }

    private function failStale(): void
    {
        $state = null;

        try {
            $state = $this->state->get();
        } catch (Throwable) {
            // Stato corrotto: trattalo come stantio e ripulisci.
            $this->state->clear();
            $this->mode->exit();

            return;
        }

        if ($state === null || ! $this->statusOf($state)->isRunning()) {
            return;
        }

        $updatedAt = (int) ($state['updated_at'] ?? $state['started_at'] ?? 0);

        if (time() - $updatedAt > $this->staleSeconds()) {
            $this->fail($state, new \RuntimeException('Ripristino interrotto e scaduto.'));
        }
    }

    private function isRunning(): bool
    {
        try {
            $state = $this->state->get();
        } catch (Throwable) {
            return false;
        }

        return $state !== null && $this->statusOf($state)->isRunning();
    }

    /* ------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function transition(array $state, RestoreStatus $to): array
    {
        return $this->state->merge(['phase' => $to->value]);
    }

    private function patchCheckpoint(array $state, array $patch): array
    {
        $checkpoint = array_replace($state['checkpoint'] ?? [], $patch);

        return $this->state->merge(['checkpoint' => $checkpoint]);
    }

    private function statusOf(array $state): RestoreStatus
    {
        return RestoreStatus::from($state['phase'] ?? RestoreStatus::PENDING->value);
    }

    private function reader(array $state): ArchiveReader
    {
        $password = null;

        if (! empty($state['password_enc'])) {
            $password = Crypt::decryptString($state['password_enc']);
        }

        return new ArchiveReader($state['archive_path'], $password);
    }

    private function tmpRoot(array $state): string
    {
        $base = config('backup.tmp_path') ?: storage_path('app/backups/tmp');

        return rtrim($base, '/').'/restore-'.$state['uuid'];
    }

    private function extractDir(array $state): string
    {
        return $this->tmpRoot($state).'/extracted';
    }

    private function staleSeconds(): int
    {
        return (int) config('backup.stale_after_hours', 2) * 3600;
    }
}

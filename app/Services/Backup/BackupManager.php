<?php

namespace App\Services\Backup;

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Events\Backup\BackupCompleted;
use App\Events\Backup\BackupDeleted;
use App\Events\Backup\BackupFailed;
use App\Events\Backup\BackupStarted;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\Destinations\DestinationManager;
use App\Services\Backup\Exceptions\BackupInProgressException;
use App\Services\Backup\Support\StepBudget;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Orchestratore del backup a passi riprendibili (step-runner).
 *
 * Ogni chiamata a runStep() fa avanzare il backup per al massimo
 * config('backup.step_time_budget') secondi e persiste il checkpoint:
 * il frontend richiama lo step successivo finché lo stato non diventa
 * COMPLETED o FAILED. Un backup interrotto (timeout, tab chiusa) riparte
 * esattamente dal checkpoint salvato.
 */
class BackupManager
{
    public function __construct(
        private readonly DestinationManager $destinations,
        private readonly FileSelector $fileSelector,
        private readonly Archiver $archiver,
        private readonly ManifestBuilder $manifestBuilder,
    ) {}

    /**
     * Il backup attualmente in lavorazione, se esiste.
     */
    public function runningBackup(): ?Backup
    {
        return Backup::running()->latest('id')->first();
    }

    /**
     * Crea un nuovo backup in stato PENDING. Il primo step va richiesto
     * subito dopo dal chiamante.
     *
     * Opzioni supportate:
     * - type: Backup::TYPE_FULL (default) oppure Backup::TYPE_DB_ONLY
     * - password: se presente, l'archivio viene cifrato AES-256. La password
     *   vive solo per la durata del backup, cifrata con l'APP_KEY dentro il
     *   checkpoint (che viene azzerato al completamento): non resta mai in
     *   chiaro nel database.
     */
    public function start(?User $user = null, array $options = []): Backup
    {
        $this->failStaleBackups();
        $this->cleanupOrphanTmpDirs();

        if ($this->runningBackup() !== null) {
            throw new BackupInProgressException('Un altro backup è già in esecuzione.');
        }

        $type = $options['type'] ?? Backup::TYPE_FULL;
        $password = $options['password'] ?? null;

        if (! in_array($type, [Backup::TYPE_FULL, Backup::TYPE_DB_ONLY], true)) {
            throw new RuntimeException("Tipo di backup [{$type}] non valido.");
        }

        $checkpoint = [];

        if ($password !== null && $password !== '') {
            $checkpoint['password_enc'] = Crypt::encryptString($password);
        }

        // Il lock serializza il controllo "c'è già un backup in corso?" e la
        // creazione: due store() simultanei non possono creare due backup.
        $startLock = Cache::lock('backup:start', 15);

        try {
            $startLock->block(5);
        } catch (Throwable) {
            throw new BackupInProgressException('Un altro backup sta partendo in questo momento.');
        }

        try {
            if ($this->runningBackup() !== null) {
                throw new BackupInProgressException('Un altro backup è già in esecuzione.');
            }

            $backup = Backup::create([
                'status' => BackupStatus::PENDING,
                'type' => $type,
                'encrypted' => isset($checkpoint['password_enc']),
                'disk' => config('backup.disk', 'backups'),
                'created_by' => $user?->id,
                'started_at' => now(),
                'progress' => ['percent' => 0, 'phase' => BackupStatus::PENDING->value, 'detail' => null],
                'checkpoint' => $checkpoint,
            ]);
        } finally {
            $startLock->release();
        }

        File::ensureDirectoryExists($this->tmpDir($backup));

        event(new BackupStarted($backup));

        return $backup;
    }

    /**
     * Fa avanzare il backup di uno step, entro il budget di tempo.
     * Idempotente rispetto al polling: se uno step è già in corso per lo
     * stesso backup (lock attivo), restituisce lo stato corrente.
     */
    public function runStep(Backup $backup): Backup
    {
        if (! $backup->isRunning()) {
            return $backup;
        }

        $budgetSeconds = max(5, (int) config('backup.step_time_budget', 20));
        $lock = Cache::lock("backup:step:{$backup->uuid}", $budgetSeconds + 60);

        if (! $lock->get()) {
            return $backup;
        }

        // Su hosting che lo consentono alziamo il limite; dove è disabilitato
        // il budget (ben sotto i max_execution_time tipici) fa da guardia.
        @set_time_limit($budgetSeconds + 30);
        ignore_user_abort(true);

        try {
            $budget = new StepBudget($budgetSeconds);

            try {
                while (! $budget->exceeded() && $backup->isRunning()) {
                    $this->advance($backup, $budget);
                }
            } catch (Throwable $e) {
                $this->fail($backup, $e);
            }
        } finally {
            $lock->release();
        }

        return $backup;
    }

    /**
     * Elimina un backup: archivio sulla destinazione, file temporanei e record.
     *
     * Su un backup in corso l'eliminazione equivale all'annullamento: il lock
     * dello step serializza con l'eventuale richiesta step in volo (fino a un
     * intero budget), altrimenti quella richiesta potrebbe depositare un
     * archivio orfano sulla destinazione dopo la cancellazione del record.
     */
    public function delete(Backup $backup): void
    {
        $budgetSeconds = max(5, (int) config('backup.step_time_budget', 20));
        $stepLock = Cache::lock("backup:step:{$backup->uuid}", $budgetSeconds + 60);

        try {
            $stepLock->block($budgetSeconds + 15);
        } catch (Throwable) {
            // Lock non ottenuto entro il timeout: si procede comunque, meglio
            // un caso raro di file orfano che un backup impossibile da eliminare.
        }

        try {
            // Lo step in volo potrebbe aver fatto avanzare (o completato) il
            // backup mentre aspettavamo il lock: rileggiamo lo stato reale.
            try {
                $backup->refresh();
            } catch (Throwable) {
                return; // Record già eliminato altrove
            }

            if ($backup->filename) {
                $destination = $this->destinations->destination();

                if ($destination->exists($backup->filename)) {
                    $destination->delete($backup->filename);
                }
            }

            $this->cleanupTmp($backup);

            $backup->delete();
        } finally {
            $stepLock->release();
        }

        event(new BackupDeleted($backup));
    }

    /**
     * Marca come falliti i backup fermi da troppo tempo (browser chiuso,
     * processo interrotto senza ripresa) e ne ripulisce i temporanei.
     */
    public function failStaleBackups(): void
    {
        $staleHours = max(1, (int) config('backup.stale_after_hours', 2));

        Backup::running()
            ->where('updated_at', '<', now()->subHours($staleHours))
            ->get()
            ->each(function (Backup $backup) {
                $backup->forceFill([
                    'status' => BackupStatus::FAILED,
                    'error' => __('impostazioni.backup_error_stale'),
                    // Il checkpoint (con l'eventuale password cifrata) non deve
                    // sopravvivere a un backup terminato
                    'checkpoint' => null,
                ])->save();

                $this->cleanupTmp($backup);

                event(new BackupFailed($backup));
            });
    }

    /**
     * Rimuove le cartelle temporanee orfane (nessun backup in esecuzione
     * corrispondente) più vecchie di un giorno.
     */
    public function cleanupOrphanTmpDirs(): void
    {
        $tmpRoot = $this->tmpRoot();

        if (! is_dir($tmpRoot)) {
            return;
        }

        $runningUuids = Backup::running()->pluck('uuid')->all();
        $threshold = now()->subDay()->getTimestamp();

        foreach (File::directories($tmpRoot) as $directory) {
            $uuid = basename($directory);

            if (! in_array($uuid, $runningUuids, true) && filemtime($directory) < $threshold) {
                File::deleteDirectory($directory);
            }
        }

        // Archivi temporanei rimasti orfani (tmp/{uuid}.zip)
        foreach (File::files($tmpRoot) as $file) {
            $uuid = $file->getBasename('.'.$file->getExtension());

            if (! in_array($uuid, $runningUuids, true) && $file->getMTime() < $threshold) {
                @unlink($file->getPathname());
            }
        }
    }

    private function cleanupTmp(Backup $backup): void
    {
        File::deleteDirectory($this->tmpDir($backup));

        if (is_file($this->tmpZipPath($backup))) {
            @unlink($this->tmpZipPath($backup));
        }
    }

    private function advance(Backup $backup, StepBudget $budget): void
    {
        switch ($backup->status) {
            case BackupStatus::PENDING:
                File::ensureDirectoryExists($this->tmpDir($backup));
                $backup->forceFill(['status' => BackupStatus::DUMPING_DATABASE])->save();
                break;

            case BackupStatus::DUMPING_DATABASE:
                $this->advanceDump($backup, $budget);
                break;

            case BackupStatus::ARCHIVING_FILES:
                $this->advanceArchive($backup, $budget);
                break;

            case BackupStatus::FINALIZING:
                $this->finalize($backup);
                break;

            default:
                break;
        }
    }

    private function advanceDump(Backup $backup, StepBudget $budget): void
    {
        $dumper = app(DatabaseDumperInterface::class);
        $checkpoint = $backup->checkpoint ?? [];
        $state = $checkpoint['dump'] ?? [];

        $dumpPath = $this->tmpDir($backup).DIRECTORY_SEPARATOR.$dumper->dumpFilename();
        $done = $dumper->dump($dumpPath, $state, $budget);

        $checkpoint['dump'] = $state;
        $checkpoint['dump_file'] = $dumper->dumpFilename();

        if ($done) {
            $checkpoint['dump_sha256'] = hash_file('sha256', $dumpPath) ?: null;
            $backup->status = BackupStatus::ARCHIVING_FILES;
        }

        $backup->forceFill([
            'checkpoint' => $checkpoint,
            'progress' => $this->progressSnapshot($backup->status, $checkpoint),
        ])->save();
    }

    private function advanceArchive(Backup $backup, StepBudget $budget): void
    {
        $checkpoint = $backup->checkpoint ?? [];
        $zipPath = $this->tmpZipPath($backup);
        $filesJsonPath = $this->tmpDir($backup).DIRECTORY_SEPARATOR.'files.json';
        $password = $this->archivePassword($backup);

        // Prima passata: enumera i file da archiviare e congela l'elenco su
        // disco (il checkpoint in DB resta piccolo, l'elenco può essere lungo).
        // Un backup "solo database" salta del tutto la raccolta dei file.
        if (! isset($checkpoint['files'])) {
            $collected = $backup->type === Backup::TYPE_DB_ONLY
                ? ['files' => [], 'total_bytes' => 0]
                : $this->fileSelector->collect();

            File::put($filesJsonPath, json_encode($collected['files']));

            $checkpoint['files'] = [
                'count' => count($collected['files']),
                'bytes' => $collected['total_bytes'],
            ];
        }

        // Il dump del database entra nello zip per primo, in un batch dedicato;
        // il file temporaneo viene poi rimosso per non occupare spazio doppio.
        if (empty($checkpoint['dump_added'])) {
            $dumpPath = $this->tmpDir($backup).DIRECTORY_SEPARATOR.$checkpoint['dump_file'];

            if (! is_file($dumpPath)) {
                throw new RuntimeException('File di dump del database non trovato: il backup non può continuare.');
            }

            $this->archiver->addSingleFile($zipPath, $dumpPath, 'db/'.$checkpoint['dump_file'], $password);

            // Prima si persiste il flag, poi si elimina il file temporaneo:
            // un'interruzione tra i due lascia solo un file orfano innocuo,
            // l'ordine inverso renderebbe il backup non riprendibile.
            $checkpoint['dump_added'] = true;
            $backup->forceFill(['checkpoint' => $checkpoint])->save();
            @unlink($dumpPath);
        }

        $files = json_decode(File::get($filesJsonPath), true) ?? [];
        $state = $checkpoint['archive'] ?? [];

        $done = $this->archiver->archiveStep($zipPath, $files, $state, $budget, $password);

        $checkpoint['archive'] = $state;

        if ($done) {
            $backup->status = BackupStatus::FINALIZING;
        }

        $backup->forceFill([
            'checkpoint' => $checkpoint,
            'progress' => $this->progressSnapshot($backup->status, $checkpoint),
        ])->save();
    }

    private function finalize(Backup $backup): void
    {
        $checkpoint = $backup->checkpoint ?? [];
        $zipPath = $this->tmpZipPath($backup);

        if (! is_file($zipPath)) {
            throw new RuntimeException('Archivio di backup non trovato durante la finalizzazione.');
        }

        $manifest = $this->manifestBuilder->build(
            $backup,
            $checkpoint['dump_file'] ?? 'database.sql',
            $checkpoint['dump_sha256'] ?? null,
            $checkpoint['dump'] ?? [],
            $checkpoint['files'] ?? ['count' => 0, 'bytes' => 0],
        );

        $this->archiver->addFromString(
            $zipPath,
            'manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $this->archivePassword($backup)
        );

        $filename = sprintf(
            '%s-%s-%s.zip',
            config('backup.filename_prefix', 'kondomanager-backup'),
            now()->format('Y-m-d_His'),
            substr($backup->uuid, 0, 8)
        );

        $checksum = hash_file('sha256', $zipPath) ?: null;
        $size = (int) filesize($zipPath);

        $this->destinations->destination()->store($zipPath, $filename);

        $backup->forceFill([
            'status' => BackupStatus::COMPLETED,
            'filename' => $filename,
            'size' => $size,
            'checksum' => $checksum,
            'manifest' => $manifest,
            'checkpoint' => null,
            'progress' => ['percent' => 100, 'phase' => BackupStatus::COMPLETED->value, 'detail' => null],
            'completed_at' => now(),
        ])->save();

        File::deleteDirectory($this->tmpDir($backup));

        $this->enforceRetention();

        event(new BackupCompleted($backup));
    }

    private function fail(Backup $backup, Throwable $e): void
    {
        report($e);

        $backup->forceFill([
            'status' => BackupStatus::FAILED,
            'error' => mb_substr($e->getMessage(), 0, 1000),
            // Un backup fallito non è riprendibile: il checkpoint (con
            // l'eventuale password cifrata) non deve restare nel database
            'checkpoint' => null,
        ])->save();

        event(new BackupFailed($backup));
    }

    private function enforceRetention(): void
    {
        try {
            $keepLast = max(1, (int) app(BackupSettings::class)->retention_keep_last);
        } catch (Throwable) {
            $keepLast = 5;
        }

        Backup::query()
            ->where('status', BackupStatus::COMPLETED->value)
            ->orderByDesc('completed_at')
            ->get()
            ->slice($keepLast)
            ->each(fn (Backup $backup) => $this->delete($backup));
    }

    private function progressSnapshot(BackupStatus $status, array $checkpoint): array
    {
        // Pesi delle fasi sulla percentuale complessiva
        $percent = match ($status) {
            BackupStatus::PENDING => 0,
            BackupStatus::DUMPING_DATABASE => (int) round(40 * $this->dumpFraction($checkpoint)),
            BackupStatus::ARCHIVING_FILES => 40 + (int) round(55 * $this->archiveFraction($checkpoint)),
            BackupStatus::FINALIZING => 97,
            BackupStatus::COMPLETED => 100,
            BackupStatus::FAILED => 0,
        };

        $detail = match ($status) {
            BackupStatus::DUMPING_DATABASE => $checkpoint['dump']['table']['name'] ?? null,
            BackupStatus::ARCHIVING_FILES => sprintf(
                '%d/%d',
                (int) ($checkpoint['archive']['file_index'] ?? 0),
                (int) ($checkpoint['files']['count'] ?? 0)
            ),
            default => null,
        };

        return ['percent' => min(100, max(0, $percent)), 'phase' => $status->value, 'detail' => $detail];
    }

    private function dumpFraction(array $checkpoint): float
    {
        $estimated = (int) ($checkpoint['dump']['rows_estimated_total'] ?? 0);

        if ($estimated <= 0) {
            return 0.0;
        }

        return min(1.0, ((int) ($checkpoint['dump']['rows_written_total'] ?? 0)) / $estimated);
    }

    private function archiveFraction(array $checkpoint): float
    {
        $total = (int) ($checkpoint['files']['bytes'] ?? 0);

        if ($total <= 0) {
            // Nessun file da archiviare: la fase è di fatto conclusa
            return isset($checkpoint['archive']) ? 1.0 : 0.0;
        }

        return min(1.0, ((int) ($checkpoint['archive']['bytes_done'] ?? 0)) / $total);
    }

    /**
     * Password dell'archivio per il backup in corso, se cifrato.
     * Vive cifrata con l'APP_KEY nel checkpoint e sparisce col checkpoint
     * stesso al completamento (o alla cancellazione) del backup.
     */
    private function archivePassword(Backup $backup): ?string
    {
        $encrypted = $backup->checkpoint['password_enc'] ?? null;

        if ($encrypted === null) {
            return null;
        }

        return Crypt::decryptString($encrypted);
    }

    private function tmpDir(Backup $backup): string
    {
        return $this->tmpRoot().'/'.$backup->uuid;
    }

    private function tmpZipPath(Backup $backup): string
    {
        return $this->tmpRoot().'/'.$backup->uuid.'.zip';
    }

    private function tmpRoot(): string
    {
        return config('backup.tmp_path') ?: storage_path('app/backups/tmp');
    }
}

<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBackupSettingsRequest;
use App\Models\Backup;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupPasswordStore;
use App\Services\Backup\BackupPreflight;
use App\Services\Backup\Destinations\DestinationManager;
use App\Services\Backup\Exceptions\BackupInProgressException;
use App\Settings\BackupSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Gestione backup dalla pagina impostazioni.
 *
 * La creazione avviene a passi riprendibili: store() crea il backup ed
 * esegue il primo step, poi il frontend richiama step() in sequenza finché
 * lo stato non diventa completed o failed (pattern step-runner, compatibile
 * con gli hosting condivisi).
 */
class BackupSettingsController extends Controller
{
    use HandleFlashMessages;

    /**
     * Con i backup disabilitati (config backup.enabled, es. demo pubblica o
     * installazioni gestite) tutte le rotte rispondono 404: l'archivio
     * conterrebbe il .env del server e non deve esistere come funzione.
     */
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('backup.enabled', true), 404);
    }

    public function index(BackupSettings $settings, BackupManager $manager, BackupPreflight $preflight, BackupPasswordStore $passwordStore): Response
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $manager->failStaleBackups();
        $manager->cleanupOrphanTmpDirs();

        $running = $manager->runningBackup();

        return Inertia::render('impostazioni/impostazioniBackups', [
            'backups' => Backup::query()
                ->with('creator')
                ->latest('id')
                ->paginate(10, ['*'], 'backups_page')
                ->withQueryString()
                ->through(fn (Backup $backup) => $this->presentBackup($backup)),
            'runningBackup' => $running ? $this->presentBackup($running) : null,
            'preflight' => $preflight->run(),
            'retention_keep_last' => $settings->retention_keep_last,
            // Solo se la password è impostata, MAI il valore in chiaro
            'backup_has_password' => $passwordStore->has(),
        ]);
    }

    /**
     * Crea un nuovo backup ed esegue subito il primo step (endpoint JSON).
     */
    public function store(Request $request, BackupSettings $settings, BackupManager $manager, BackupPreflight $preflight, BackupPasswordStore $passwordStore): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $validated = $request->validate([
            'type' => 'nullable|string|in:full,db_only',
            // 'protect' = cifra l'archivio con la password salvata una volta
            // sola nelle impostazioni (nessuna password per-backup da digitare).
            'protect' => 'nullable|boolean',
        ]);

        $type = $validated['type'] ?? Backup::TYPE_FULL;
        $check = $preflight->run();

        // Un backup "solo database" richiede molto meno spazio: viene valutato
        // con la propria soglia invece di quella del backup completo.
        $preflightOk = $type === Backup::TYPE_DB_ONLY ? $check['ok_db_only'] : $check['ok'];

        if (! $preflightOk) {
            return response()->json([
                'message' => __('impostazioni.backup_error_preflight'),
                'preflight' => $check,
            ], 422);
        }

        $password = null;

        if ($request->boolean('protect')) {
            if (! $check['encryption_supported']) {
                return response()->json(['message' => __('impostazioni.backup_error_encryption_unsupported')], 422);
            }

            $password = $passwordStore->get();

            // Toggle attivo ma nessuna password salvata: non si parte in chiaro
            // silenziosamente (l'utente crede di aver cifrato). Deve impostarla.
            if ($password === null) {
                return response()->json(['message' => __('impostazioni.backup_error_no_saved_password')], 422);
            }
        }

        try {
            $backup = $manager->start($request->user(), [
                'type' => $type,
                'password' => $password,
            ]);
        } catch (BackupInProgressException) {
            return response()->json(['message' => __('impostazioni.backup_error_in_progress')], 409);
        }

        $backup = $manager->runStep($backup);

        return response()->json(['backup' => $this->presentBackup($backup)]);
    }

    /**
     * Esegue lo step successivo di un backup in corso (endpoint JSON,
     * chiamato in sequenza dal frontend).
     */
    public function step(BackupSettings $settings, Backup $backup, BackupManager $manager): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $backup = $manager->runStep($backup);

        return response()->json(['backup' => $this->presentBackup($backup->refresh())]);
    }

    public function download(BackupSettings $settings, Backup $backup, DestinationManager $destinations): BinaryFileResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        abort_unless($backup->status === BackupStatus::COMPLETED && $backup->filename, 404);

        $path = $destinations->destination()->localPath($backup->filename);

        abort_unless($path !== null && is_file($path), 404);

        return response()->download($path, $backup->filename);
    }

    /**
     * Elimina un backup. Su un backup in corso equivale ad annullarlo.
     */
    public function destroy(BackupSettings $settings, Backup $backup, BackupManager $manager): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $manager->delete($backup);

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_delete_backup'))
        );
    }

    public function updateSettings(UpdateBackupSettingsRequest $request, BackupSettings $settings, BackupPasswordStore $passwordStore): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $validated = $request->validated();

        try {
            $settings->retention_keep_last = (int) $validated['retention_keep_last'];
            $settings->save();

            // Un unico "Salva impostazioni" per tutta la card: se il form
            // password è aperto e compilato, la (ri)imposta nello stesso colpo.
            if (filled($validated['password'] ?? null)) {
                $passwordStore->set((string) $validated['password']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with(
                $this->flashError(__('impostazioni.error_save_backup_settings'))
            );
        }

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_save_backup_settings'))
        );
    }

    /**
     * Rimuove la password di cifratura predefinita dei backup. L'impostazione
     * avviene invece insieme alle altre impostazioni (updateSettings).
     */
    public function removePassword(Request $request, BackupSettings $settings, BackupPasswordStore $passwordStore): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $request->validate([
            'remove' => 'required|accepted',
        ]);

        $passwordStore->clear();

        return redirect()->back()->with(
            $this->flashSuccess(__('impostazioni.success_remove_backup_password'))
        );
    }

    private function presentBackup(Backup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'filename' => $backup->filename,
            'status' => $backup->status->value,
            'type' => $backup->type ?? Backup::TYPE_FULL,
            'encrypted' => (bool) $backup->encrypted,
            'progress' => $backup->progress,
            'size' => $backup->size,
            'checksum' => $backup->checksum,
            'error' => $backup->error,
            'created_at' => $backup->created_at?->toIso8601String(),
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'created_by' => $backup->relationLoaded('creator') ? $backup->creator?->name : null,
            'database_rows' => $backup->manifest['database']['rows'] ?? null,
            'files_count' => $backup->manifest['files']['count'] ?? null,
        ];
    }
}

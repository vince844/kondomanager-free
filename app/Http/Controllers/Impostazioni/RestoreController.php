<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\Destinations\DestinationManager;
use App\Services\Restore\ArchiveReader;
use App\Services\Restore\Exceptions\IncompatibleBackupException;
use App\Services\Restore\Exceptions\InvalidArchivePasswordException;
use App\Services\Restore\Exceptions\MalformedArchiveException;
use App\Services\Restore\Exceptions\RestoreInProgressException;
use App\Services\Restore\RestoreManager;
use App\Services\Restore\RestorePreflight;
use App\Services\Restore\RestoreState;
use App\Settings\BackupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Ripristino di un backup dall'interfaccia amministrativa.
 *
 * L'avvio è protetto da permesso + conferma con la password del proprio
 * account (sudo mode): è l'operazione più distruttiva dell'applicazione.
 * Gli step e lo stato, invece, NON usano la sessione (l'import la sovrascrive)
 * ma un token monouso, verificati dal middleware EnsureRestoreToken.
 */
class RestoreController extends Controller
{
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('backup.enabled', true), 404);
    }

    /**
     * Ispeziona un archivio per la finestra di conferma: legge il manifest
     * (validando l'eventuale password) e il preflight, senza avviare nulla.
     */
    public function inspect(Request $request, BackupSettings $settings, Backup $backup, DestinationManager $destinations, RestorePreflight $preflight): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $path = $this->archivePath($backup, $destinations);
        $password = $request->input('password');

        try {
            $reader = new ArchiveReader($path, $password);
            $manifest = $reader->readManifest();
            $reader->close();

            $info = $preflight->inspect($manifest, (int) filesize($path));
        } catch (InvalidArchivePasswordException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        } catch (MalformedArchiveException|IncompatibleBackupException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return response()->json(['preflight' => $info]);
    }

    /**
     * Avvia il ripristino. Richiede la password dell'account (sudo) e, se
     * l'archivio è cifrato, la sua password. Entra in modalità ripristino e
     * restituisce il token con cui il frontend guiderà gli step successivi.
     */
    public function start(Request $request, BackupSettings $settings, Backup $backup, DestinationManager $destinations, RestoreManager $manager): JsonResponse
    {
        $this->ensureEnabled();
        Gate::authorize('manage', $settings);

        $validated = $request->validate([
            'account_password' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'safety_backup' => ['boolean'],
            'adopt_app_key' => ['boolean'],
        ]);

        // Sudo mode: ri-conferma della password del proprio account. Protegge
        // anche da una sessione lasciata aperta su un computer condiviso.
        if (! Hash::check($validated['account_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'account_password' => 'La password del tuo account non è corretta.',
            ]);
        }

        $path = $this->archivePath($backup, $destinations);

        try {
            $result = $manager->start(
                $path,
                $validated['password'] ?? null,
                [
                    'safety_backup' => $validated['safety_backup'] ?? true,
                    'adopt_app_key' => $validated['adopt_app_key'] ?? false,
                ],
                $request->user(),
            );
        } catch (InvalidArchivePasswordException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        } catch (MalformedArchiveException|IncompatibleBackupException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        } catch (RestoreInProgressException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return response()->json([
            'uuid' => $result['uuid'],
            'token' => $result['token'],
            'manifest' => $result['manifest'],
        ]);
    }

    /**
     * Fa avanzare il ripristino di uno step. Autenticato dal token (nessuna
     * sessione: la tabella sessions viene sovrascritta durante l'import).
     */
    public function step(Request $request, RestoreManager $manager): JsonResponse
    {
        $state = $manager->runStep();

        return response()->json(['restore' => $this->present($state)]);
    }

    /**
     * Stato corrente del ripristino (polling del frontend).
     */
    public function status(Request $request, RestoreManager $manager): JsonResponse
    {
        return response()->json(['restore' => $this->present($manager->current())]);
    }

    /**
     * Pagina di esito, raggiungibile dopo il ripristino (la modalità è già
     * spenta se completato). Legge lo stato su file: nessuna dipendenza dal
     * database, che potrebbe richiedere un nuovo login.
     */
    public function result(RestoreManager $manager): InertiaResponse
    {
        return Inertia::render('impostazioni/RestoreResult', [
            'restore' => $this->present($manager->current()),
        ]);
    }

    /**
     * Riprende un ripristino fallito/in stallo (pulsante della pagina di
     * blocco o dell'overlay admin). Vedi authorizeRecovery per le due vie di
     * autenticazione senza sessione.
     */
    public function resume(Request $request, RestoreManager $manager, RestoreState $stateStore): JsonResponse
    {
        $this->authorizeRecovery($request, $manager, $stateStore);

        $state = $manager->resume();

        // Riemetti un token: la pagina di recupero (senza sessione) può così
        // guidare gli step successivi come l'overlay admin, senza ridigitare la
        // password a ogni passo.
        $token = $stateStore->issueToken((int) config('backup.stale_after_hours', 2) * 3600);

        return response()->json([
            'restore' => $this->present($state),
            'token' => $token,
        ]);
    }

    /**
     * Annulla un ripristino fallito/in stallo e sblocca l'applicazione. Il
     * database può restare in uno stato parziale: la UI lo avvisa.
     */
    public function abort(Request $request, RestoreManager $manager, RestoreState $stateStore): JsonResponse
    {
        $this->authorizeRecovery($request, $manager, $stateStore);

        $manager->abort();

        return response()->json(['restore' => $this->present($manager->current())]);
    }

    /**
     * Autorizza un'azione di recupero senza sessione (l'import l'ha azzerata).
     * Due vie: (1) il token del ripristino, che chi ha ancora aperta la pagina
     * admin conserva in memoria; (2) la password dell'account che ha avviato
     * il ripristino, per chi arriva dalla pagina di blocco 503.
     */
    private function authorizeRecovery(Request $request, RestoreManager $manager, RestoreState $stateStore): void
    {
        $state = $manager->current();

        abort_if($state === null, 404);

        // Via 1 — token valido (overlay admin ancora aperto)
        $token = $request->header('X-Restore-Token') ?? $request->input('token');
        if ($token !== null && $stateStore->validateToken($token)) {
            return;
        }

        // Via 2 — password dell'account che ha avviato il ripristino
        $password = (string) $request->input('account_password', '');
        $userId = $state['created_by'] ?? null;
        $user = $userId ? User::find($userId) : null;

        if ($password !== '' && $user !== null && Hash::check($password, $user->password)) {
            return;
        }

        throw ValidationException::withMessages([
            'account_password' => __('impostazioni.restore_recovery.auth_failed'),
        ]);
    }

    private function archivePath(Backup $backup, DestinationManager $destinations): string
    {
        abort_unless($backup->status === BackupStatus::COMPLETED && $backup->filename, 404);

        $path = $destinations->destination()->localPath($backup->filename);

        abort_unless($path !== null && is_file($path), 404);

        return $path;
    }

    /**
     * Proietta lo stato del ripristino per il frontend, SENZA mai esporre
     * segreti (token, password cifrata).
     */
    private function present(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return [
            'uuid' => $state['uuid'] ?? null,
            'phase' => $state['phase'] ?? null,
            'manifest' => $state['manifest'] ?? null,
            'outcome' => $state['outcome'] ?? [],
            'error' => $state['error'] ?? null,
            // Contesto per il log di supporto (nessun segreto)
            'failed_phase' => $state['failed_phase'] ?? null,
            'failed_at' => $state['failed_at'] ?? null,
            'aborted' => (bool) ($state['aborted'] ?? false),
            'app_version' => config('app.version'),
        ];
    }
}

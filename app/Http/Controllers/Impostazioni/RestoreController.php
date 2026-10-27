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
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestorePreflight;
use App\Services\Restore\RestoreState;
use App\Settings\BackupSettings;
use App\Support\IndirizzoDeiLimiti;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

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
    /** Quanto resta la parte pubblica della pagina dell'esito dopo la chiusura: 24 ore (decisione 127.5). */
    public const ESITO_PUBBLICO_SECONDI = 86400;

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
                'account_password' => __('impostazioni.restore_error_account_password'),
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
     *
     * Si apre senza accesso, perché dopo il ripristino le sessioni sono azzerate. Chi gestisce i
     * backup, con l'accesso, vede tutto lo stato; a chiunque altro la pagina dà solo l'esito e che
     * cosa riconfigurare (`presentPubblico`), e niente 24 ore dopo la chiusura. Un ospite che la vede
     * torna qui dopo l'accesso: è così che l'amministratore arriva ai dettagli (Coda 343, decisione
     * 127.5).
     */
    public function result(Request $request, RestoreManager $manager): InertiaResponse
    {
        $state = $manager->current();

        if ($state !== null && $this->vedeIlDettaglio($request)) {
            return Inertia::render('impostazioni/RestoreResult', [
                'restore' => $this->present($state),
                'dettaglio' => true,
            ]);
        }

        $pubblico = $this->presentPubblico($state);

        if ($pubblico !== null && $request->user() === null && ! app(RestoreMode::class)->active()) {
            redirect()->setIntendedUrl(route('ripristino.result'));
        }

        return Inertia::render('impostazioni/RestoreResult', [
            'restore' => $pubblico,
            'dettaglio' => false,
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
     *
     * La password si verifica sull'impronta che l'avvio ha salvato nello stato,
     * non nella tabella degli utenti: durante l'import quella tabella manca, è
     * vuota o ha già i dati del backup, dove lo stesso id può essere di un'altra
     * persona con un'altra password. L'impronta si toglie quando il ripristino
     * si chiude (completato o annullato), e da lì la rotta risponde 404. Per un
     * ripristino avviato prima di questa versione vedi `improntaDiChiHaAvviato`.
     * Senza un'impronta la password non si può verificare: la risposta (409) lo
     * dice, invece di «Password non corretta.», e resta la via del token.
     */
    private function authorizeRecovery(Request $request, RestoreManager $manager, RestoreState $stateStore): void
    {
        $state = $manager->current();

        abort_if($state === null, 404);

        // Il recupero ha senso solo mentre l'applicazione è bloccata: la pagina 503 e i pulsanti
        // dell'overlay esistono solo con la modalità ripristino attiva (un ripristino fallito la
        // lascia accesa). Lo stato resta su file anche dopo un ripristino completato o annullato
        // (lo legge la pagina dell'esito), ma la rotta — senza login, senza CSRF — prova una password
        // solo finché la modalità è attiva, e con il suo limite qui sotto. Fuori dal ripristino
        // risponde 404: lì la password dell'account passa dal login, con il suo blocco e il secondo
        // fattore.
        abort_unless(app(RestoreMode::class)->active(), 404);

        // Via 1 — token valido (overlay admin ancora aperto)
        $token = $request->header('X-Restore-Token') ?? $request->input('token');
        if ($token !== null && $stateStore->validateToken($token)) {
            return;
        }

        // Via 2 — password dell'account che ha avviato il ripristino. Senza login e senza CSRF: al
        // massimo cinque password sbagliate al minuto per indirizzo, contate solo qui, dopo la via
        // del token, così chi prova password non blocca l'amministratore che ha il token
        // (1.11.0-beta.39). Il tentativo si conta PRIMA della verifica e si restituisce se la
        // password è giusta: contandolo dopo, dieci richieste in parallelo provavano dieci password
        // mentre il contatore era ancora a zero.
        // Un IPv6 conta per la sua rete /64, come in ogni limite per indirizzo (`IndirizzoDeiLimiti`).
        $chiaveLimite = 'ripristino-recupero|'.IndirizzoDeiLimiti::di($request->ip());
        $limitatore = $this->limitatoreDelRecupero();

        if ($limitatore->hit($chiaveLimite, 60) > 5) {
            abort(429);
        }

        $password = (string) $request->input('account_password', '');
        $impronta = $this->improntaDiChiHaAvviato($state);

        if ($impronta === null) {
            // La frase dice quando la finestra dei backup può ancora riprenderlo con il suo token: il token dura
            // `stale_after_hours` dall'avvio, e da ogni passo, quindi entro quelle ore dall'avvio vale sempre; un
            // ripristino fermo da quelle ore ha il token scaduto, e lì la frase non promette niente.
            $ore = (int) config('backup.stale_after_hours', 2);

            abort(409, trans_choice('impostazioni.restore_recovery.auth_unavailable', $ore, ['ore' => $ore]));
        }

        if ($password !== '' && Hash::check($password, $impronta)) {
            $limitatore->decrement($chiaveLimite, 60);

            return;
        }

        throw ValidationException::withMessages([
            'account_password' => __('impostazioni.restore_recovery.auth_failed'),
        ]);
    }

    /**
     * L'impronta della password di chi ha avviato il ripristino, o `null` se non c'è. L'avvio la
     * salva nello stato, e lo stato ha sempre la chiave: con l'impronta, o vuota quando il ripristino si chiude. Lo
     * stato di un ripristino avviato con una versione precedente, e rimasto aperto durante l'aggiornamento, non ha la
     * chiave: per quello la password si cerca nella tabella degli utenti. A metà import
     * la tabella può mancare o non avere ancora l'account: allora l'impronta non c'è.
     */
    private function improntaDiChiHaAvviato(array $state): ?string
    {
        if (array_key_exists('created_by_password_hash', $state)) {
            $impronta = $state['created_by_password_hash'];
        } else {
            try {
                $impronta = User::find($state['created_by'] ?? null)?->getAuthPassword();
            } catch (Throwable) {
                $impronta = null;
            }
        }

        return is_string($impronta) ? $impronta : null;
    }

    /**
     * Il conto delle prove della password del recupero. Con la cache nel database (`CACHE_STORE=database`, come
     * nell'immagine Docker) il conto starebbe nella tabella `cache`, che l'import toglie e ricrea: a metà import ogni
     * prova finiva in un errore 500, e il recupero con la password non poteva riuscire. Lì il conto sta su file,
     * accanto allo stato del ripristino; con ogni altra cache resta dove stanno i limiti delle altre rotte.
     */
    private function limitatoreDelRecupero(): RateLimiter
    {
        $cache = config('cache.limiter') ?: config('cache.default');

        if (config("cache.stores.{$cache}.driver") !== 'database') {
            return app(RateLimiter::class);
        }

        $statoSuFile = (string) (config('backup.restore.state_file') ?: storage_path('app/backups/.restore-state.json'));

        return new RateLimiter(Cache::build(['driver' => 'file', 'path' => dirname($statoSuFile).'/.restore-limiter']));
    }

    private function archivePath(Backup $backup, DestinationManager $destinations): string
    {
        abort_unless($backup->status === BackupStatus::COMPLETED && $backup->filename, 404);

        $path = $destinations->destination()->localPath($backup->filename);

        abort_unless($path !== null && is_file($path), 404);

        return $path;
    }

    /**
     * Chi ha l'accesso e può gestire i backup (lo stesso controllo dell'avvio del ripristino). Dopo
     * un ripristino fallito il database può essere a metà: se il controllo non si riesce a fare, la
     * pagina resta quella pubblica.
     */
    private function vedeIlDettaglio(Request $request): bool
    {
        try {
            $utente = $request->user();

            return $utente !== null && Gate::forUser($utente)->allows('manage', BackupSettings::class);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ciò che la pagina dell'esito dice a chi non vede il dettaglio: la fase, se è stato annullato, e
     * che cosa riconfigurare (quanti utenti hanno la 2FA azzerata, se la password SMTP è stata tolta,
     * se è stata adottata la chiave del backup). Niente manifest (nome e indirizzo dell'installazione,
     * versioni, righe per tabella), niente errore (un messaggio del database può contenere dati di
     * persone), niente identificativo né versione (Coda 343, decisione 127.5).
     *
     * Con la modalità ripristino spenta, la parte pubblica vale 24 ore dall'ultima chiusura
     * (completato, annullato o fallito); dopo, o senza l'ora di chiusura, la pagina è quella di
     * nessun ripristino. Con la modalità attiva lo stato resta: la 503 e il recupero ne hanno bisogno.
     */
    private function presentPubblico(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        if (! app(RestoreMode::class)->active()) {
            $chiusoAlle = max(array_map('intval', array_filter([
                $state['completed_at'] ?? null,
                $state['aborted_at'] ?? null,
                $state['failed_at'] ?? null,
            ], fn ($ora) => is_numeric($ora))) ?: [0]);

            // Senza nessuna ora di chiusura il massimo è 0, e lo stato conta come vecchio.
            if (time() - $chiusoAlle > self::ESITO_PUBBLICO_SECONDI) {
                return null;
            }
        }

        $outcome = $state['outcome'] ?? [];
        $daRiconfigurare = [];

        $pulizia = array_intersect_key(
            (array) ($outcome['cleanup'] ?? []),
            array_flip(['two_factor_reset_users', 'smtp_password_cleared'])
        );
        if ($pulizia !== []) {
            $daRiconfigurare['cleanup'] = $pulizia;
        }
        if (array_key_exists('app_key_adopted', $outcome)) {
            $daRiconfigurare['app_key_adopted'] = $outcome['app_key_adopted'];
        }

        return [
            'phase' => $state['phase'] ?? null,
            'outcome' => $daRiconfigurare,
            'aborted' => (bool) ($state['aborted'] ?? false),
        ];
    }

    /**
     * Proietta lo stato del ripristino per il frontend, SENZA mai esporre
     * segreti (token, password cifrata). È la vista completa: la ricevono chi ha
     * il token del ripristino (step, stato), chi ha messo la password giusta
     * («riprendi», «annulla») e, sulla pagina dell'esito, chi gestisce i backup.
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

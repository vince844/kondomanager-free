<?php

use App\Http\Controllers\System\SaluteController;
use App\Http\Middleware\AutenticaSessione;
use App\Http\Middleware\CheckForPendingUpdates;
use App\Http\Middleware\CheckHasAnagrafica;
use App\Http\Middleware\CheckRestoreMode;
use App\Http\Middleware\CheckSuspendedUser;
use App\Http\Middleware\ConfermaPasswordRecente;
use App\Http\Middleware\CheckUserRegistration;
use App\Http\Middleware\EnsureAutoUpdateEnabled;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureTwoFactorChallengeSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IntestazioniDiSicurezza;
use App\Http\Middleware\SetAppNameMiddleware;
use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // `/up` non è più la rotta di salute di Laravel (200 appena l'app si avvia, database o
        // no): risponde SaluteController, 200 solo a installazione chiusa e migrazioni applicate.
        // Registrata qui, e non in routes/web.php, per restare fuori dal gruppo `web`: niente
        // sessione e niente middleware dell'interfaccia per il controllo di salute.
        then: function () {
            Route::get('/up', SaluteController::class)->name('salute');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {

        // TRUSTED PROXIES: configurati in config/trustedproxy.php, NON qui.
        // Questo closure gira quando lo HttpKernel viene risolto
        // (Application::handleRequest -> make(Kernel)), PRIMA dei bootstrapper
        // LoadEnvironmentVariables/LoadConfiguration: in questo punto né env()
        // né config() vedono ancora il .env, quindi env('TRUSTED_PROXIES')
        // tornerebbe sempre null e i proxy non verrebbero MAI configurati.
        // Il middleware globale TrustProxies legge invece a request-time
        // config('trustedproxy.proxies'), valorizzato dal .env dopo il boot.
        // Vedi il commento esteso in config/trustedproxy.php.

        // `/up` passa anche in manutenzione e dice lo stato vero; le altre pagine restano chiuse.
        // Fino alla 1.11.0-beta.31 `/up` era la rotta `health:` di Laravel, che Laravel toglie da
        // solo dalla manutenzione; spostata in `then:` (beta.32), l'eccezione si è persa. Da allora,
        // in manutenzione, rispondeva come le altre pagine: il rimando (302), che un controllo di
        // salute con `curl -f` (Coolify) dà per sano anche con migrazioni da applicare; e con la
        // manutenzione nel database (`APP_MAINTENANCE_STORE=database`, come su KondoCloud) e il
        // database giù, 500. Laravel esegue questa closure anche all'avvio della console: è da lì
        // che `artisan down` prende l'elenco e lo scrive nel file `framework/down` (driver `file`),
        // che `public/index.php` legge prima di avviare l'applicazione (1.11.0-beta.46, Coda 224).
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        // Le intestazioni di sicurezza (Coda 264), in testa alla pila globale: le riceve ogni risposta,
        // anche `/up` (fuori dal gruppo `web`), la 503 del ripristino, la manutenzione e le pagine
        // d'errore, che passano tutte da qui all'uscita.
        $middleware->prepend(IntestazioniDiSicurezza::class);

        // CheckRestoreMode va per PRIMO: se un ripristino è in corso deve
        // bloccare tutto (update check incluso) senza toccare DB/sessione.
        $middleware->web(prepend: [
            CheckRestoreMode::class,
        ]);

        // CheckSuspendedUser sta nel gruppo `web` e non sulle singole rotte: agganciato rotta per
        // rotta è già stato perso una volta — montava solo `dashboard`, quella rotta è stata
        // sostituita nel 2025 e per sedici mesi la sospensione non ha avuto alcun effetto su
        // nessuno. La guardia `Auth::check()` interna lo rende innocuo su rotte per ospiti e
        // installer.
        $middleware->web(append: [
            CheckSuspendedUser::class,
            CheckForPendingUpdates::class,
            SetLocaleMiddleware::class,
            SetAppNameMiddleware::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // La lingua dell'installazione prima dei limiti di rotta (Coda 261). Il router riordina i middleware con la
        // sua lista delle priorità, che porta davanti al gruppo i limiti, la sessione dell'utente e la ricerca dei
        // modelli della rotta: senza un posto nella lista, la lingua scelta nelle impostazioni generali arrivava
        // dopo di loro, e le loro risposte (il 429, la scheda che non c'è) restavano nella lingua del `.env`. Lo
        // stesso il rimando di chi è sospeso, per un'altra ragione: `CheckSuspendedUser` non è nella lista, e la
        // lingua gli veniva dopo per l'ordine del gruppo qui sopra. Nella lista la lingua sta dopo
        // l'autenticazione e prima dei limiti, e passa davanti anche a lui, che nel gruppo segue la ricerca dei
        // modelli; `CheckRestoreMode` resta il primo del gruppo. `SetLocaleMiddleware` legge il database con un
        // ripiego: sulle rotte del ripristino a token, a metà dell'import, non ferma niente.
        $middleware->prependToPriorityList(ThrottleRequests::class, SetLocaleMiddleware::class);

        // Le altre sessioni di un utente si chiudono quando la sua password cambia (Coda 261):
        // `AuthenticateSession` confronta a ogni richiesta la password con cui la sessione è nata con
        // quella dell'account, e vale anche per il cookie «ricordami». Funziona con ogni driver di
        // sessione (`file`, `database`, `redis`), perché non cerca le righe di `sessions`: è ogni
        // sessione che, alla richiesta dopo, si accorge di essere vecchia. Chi cambia la password resta
        // dentro: la sua sessione riceve la password nuova alla fine della stessa richiesta.
        // `auth.session` è la nostra sottoclasse (alias qui sotto): chiude anche le sessioni di un
        // account senza password, cioè rimesso in attesa del primo accesso da «reinvia» (Coda 202).
        $middleware->authenticateSessions();

        $middleware->alias([
            'auth.session' => AutenticaSessione::class,
            // La password ridigitata da non più di quindici minuti (Coda 261).
            'password.recente' => ConfermaPasswordRecente::class,
            'CheckSuspendedUser' => CheckSuspendedUser::class,
            'CheckHasAnagrafica' => CheckHasAnagrafica::class,
            'CheckUserRegistration' => CheckUserRegistration::class,
            'EnsureCondominioHasEsercizio' => EnsureCondominioHasEsercizio::class,
            'ensure-two-factor-challenge-session' => EnsureTwoFactorChallengeSession::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'auto.update' => EnsureAutoUpdateEnabled::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'system/run-scheduler',
            // Lo step di ripristino è autenticato dal token, non dalla
            // sessione (che l'import sovrascrive): niente cookie CSRF.
            'ripristino/step',
            // Recupero da ripristino fallito: autenticato da token o password
            // account nel controller, senza sessione (quindi senza CSRF).
            'ripristino/riprendi',
            'ripristino/annulla',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ====================================================================
        // IL FILE TROPPO GRANDE CHE SEMBRAVA UNA SESSIONE SCADUTA
        // ====================================================================
        // Quando il corpo di una richiesta supera `post_max_size`, PHP lo scarta **prima** di
        // consegnarlo all'applicazione: spariscono campi, file e token CSRF. Laravel non vede un
        // caricamento troppo grande, vede una richiesta senza token, e risponde 419 «pagina
        // scaduta» — cioè risponde parlando di sessione a un problema di dimensione.
        //
        // È la trappola di chi segue a metà il consiglio del messaggio di caricamento, che dice di
        // alzare `upload_max_filesize` **e** `post_max_size`: chi alza solo il primo finisce qui.
        // Aggiunta nella beta.58, insieme al limite letto dal server.
        // ====================================================================
        // IL FILE TROPPO GRANDE CHE SEMBRAVA UNA SESSIONE SCADUTA
        // ====================================================================
        // Quando il corpo supera `post_max_size`, PHP lo scarta prima di consegnarlo
        // all'applicazione: spariscono campi, file e token CSRF. Senza questo gestore l'utente
        // riceveva un 419 «pagina scaduta», cioè una risposta che parla di sessione a un problema
        // di dimensione.
        //
        // ⚠️ **Si risponde con una vista, non con `back()->withErrors()`.** Il ripasso della beta.58
        // ha dimostrato che il redirect non consegnava niente: `ValidatePostSize` sta nello stack
        // globale e `StartSession` nel gruppo web, quindi in questo punto la sessione **non è
        // ancora partita** — il flash finiva in un archivio mai salvato e l'utente tornava al
        // modulo senza vedere alcun avviso. Una vista non ha bisogno della sessione.
        //
        // Il gestore su `TokenMismatchException` è stato tolto perché era **codice morto**:
        // `Handler::prepareException()` la converte in `HttpException(419)` alla riga 716, prima
        // che i callback di `renderViaCallbacks()` la vedano alla 718. Un callback tipizzato su
        // quella classe non corrisponde mai.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('validation.corpo_troppo_grande', [
                        'limite' => \App\Support\LimiteCaricamento::etichettaPost(),
                    ]),
                ], 413);
            }

            return response()->view('errors.413', [], 413);
        });

        $exceptions->render(function (InvalidSignatureException $e, Request $request) {

            // DEBUG LOGGING PER IL 403 DEL FIRMATARIO (Temporaneo)
            // Della query solo i nomi dei parametri, e il valore di `expires` e `id`: con un proxy
            // configurato male un link https visto come http risponde 403 pur avendo una firma
            // buona, e chi leggeva il log (o lo incollava sul forum) poteva riaprire quel link. Non
            // basta togliere `signature=`: un client di posta che scrive «&amp;» la porta sotto la
            // chiave «amp;signature» (1.11.0-beta.45).
            $query = collect(explode('&', (string) $request->server->get('QUERY_STRING')))
                ->map(function ($parte) {
                    $nome = Str::before($parte, '=');

                    return in_array(urldecode($nome), ['expires', 'id'], true) ? $parte : $nome;
                })
                ->implode('&');

            Log::error('403 Invalid Signature', [
                'request_url' => $request->url(),
                'query_string' => $query,
                'is_secure' => $request->isSecure(),
                'proxies' => Request::getTrustedProxies(),
            ]);

            // Dentro una pagina Inertia (il salvataggio della password del primo accesso o di un
            // invito, con il link scaduto nel frattempo) un 403 HTML arrivava come finestra sopra
            // il modulo: si torna al login con lo stesso messaggio (1.11.0-beta.45).
            if ($request->header('X-Inertia')) {
                return redirect()->route('login')->with('avviso', __('errors.403.invalid_signature'));
            }

            return response()->view('errors.403', [
                'exception' => new Exception(__('errors.403.invalid_signature')),
            ], 403);

        });

    })->create();

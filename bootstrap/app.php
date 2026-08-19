<?php

use App\Http\Middleware\CheckForPendingUpdates;
use App\Http\Middleware\CheckHasAnagrafica;
use App\Http\Middleware\CheckRestoreMode;
use App\Http\Middleware\CheckSuspendedUser;
use App\Http\Middleware\CheckUserRegistration;
use App\Http\Middleware\EnsureAutoUpdateEnabled;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureTwoFactorChallengeSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetAppNameMiddleware;
use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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

        $middleware->alias([
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
            Log::error('403 Invalid Signature', [
                'request_url' => $request->url(),
                'query_string' => $request->server->get('QUERY_STRING'),
                'is_secure' => $request->isSecure(),
                'proxies' => Request::getTrustedProxies(),
            ]);

            return response()->view('errors.403', [
                'exception' => new Exception(__('errors.403.invalid_signature')),
            ], 403);

        });

    })->create();

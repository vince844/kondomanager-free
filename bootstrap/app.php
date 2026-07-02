<?php

use App\Http\Middleware\CheckForPendingUpdates;
use App\Http\Middleware\CheckHasAnagrafica;
use App\Http\Middleware\CheckSuspendedUser;
use App\Http\Middleware\CheckUserRegistration;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use App\Http\Middleware\EnsureTwoFactorChallengeSession;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetAppNameMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        
        // env() è necessario qui: config() non è ancora disponibile durante il bootstrap.
        // Default: null (nessun proxy accettato = sicuro per VPS nude).
        $trustedProxies = env('TRUSTED_PROXIES');
        if ($trustedProxies === '*') {
            $middleware->trustProxies(at: '*');
        } elseif ($trustedProxies) {
            $proxies = array_map('trim', explode(',', $trustedProxies));
            $middleware->trustProxies(at: $proxies);
        }

        $middleware->web(append: [
            CheckForPendingUpdates::class,
            SetLocaleMiddleware::class,
            SetAppNameMiddleware::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'CheckSuspendedUser'    => CheckSuspendedUser::class,
            'CheckHasAnagrafica'    => CheckHasAnagrafica::class,
            'CheckUserRegistration' => CheckUserRegistration::class,
            'EnsureCondominioHasEsercizio' => EnsureCondominioHasEsercizio::class,
            'ensure-two-factor-challenge-session' => EnsureTwoFactorChallengeSession::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'auto.update' => \App\Http\Middleware\EnsureAutoUpdateEnabled::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'system/run-scheduler', 
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        $exceptions->render(function (InvalidSignatureException $e, \Illuminate\Http\Request $request) {

            // DEBUG LOGGING PER IL 403 DEL FIRMATARIO (Temporaneo)
            \Illuminate\Support\Facades\Log::error('403 Invalid Signature', [
                'request_url' => $request->url(),
                'query_string' => $request->server->get('QUERY_STRING'),
                'is_secure' => $request->isSecure(),
                'proxies' => \Illuminate\Http\Request::getTrustedProxies(),
            ]);

            return response()->view('errors.403', [
                'exception' => new Exception(__('errors.403.invalid_signature'),)
            ], 403);
    
        });

    })->create();

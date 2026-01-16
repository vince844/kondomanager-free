<?php

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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->web(append: [
            SetLocaleMiddleware::class,
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        $exceptions->render(function (InvalidSignatureException $e) {

            return response()->view('errors.403', [
                'exception' => new Exception(__('errors.403.invalid_signature'),)
            ], 403);
    
        });

    })->create();

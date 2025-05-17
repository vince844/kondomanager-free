<?php

use App\Http\Middleware\CheckHasAnagrafica;
use App\Http\Middleware\CheckSuspendedUser;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use App\Http\Middleware\EnsureTwoFactorChallengeSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'CheckSuspendedUser' => CheckSuspendedUser::class,
            'CheckHasAnagrafica' => CheckHasAnagrafica::class,
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

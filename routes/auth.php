<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\TwoFactorAuthChallengeController;
use App\Http\Controllers\Inviti\InvitoRegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'CheckUserRegistration'])->group(function () {
    
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:modulo');
    
});

Route::middleware('guest')->group(function () {

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Il limite vero è in `LoginRequest` (errore sul modulo); questo è un tetto alto (Coda 259).
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:accesso');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:modulo')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:modulo')
        ->name('password.store');
    
    // Firmata come il `GET` di `routes/web.php`: il modulo si manda all'URL del link dell'invito.
    // Fino alla 1.11.0-beta.44 bastava l'email di un invito in sospeso (Coda 223, 1.11.0-beta.45).
    Route::post('/invito/register', [InvitoRegisteredUserController::class, 'store'])
        ->name('invito.register.store')
        ->middleware(['signed', 'throttle:modulo']);
});

Route::middleware('auth')->group(function () {
    
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:modulo'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:modulo')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // Lo stato della conferma della propria sessione, per lo scarico di un backup (Coda 404): sola lettura.
    Route::get('confirm-password/stato', [ConfirmablePasswordController::class, 'stato'])
        ->name('password.confirm.stato');

    // Conta fra le prove della password attuale: un conto per utente con il cambio della password, il
    // profilo e l'avvio del ripristino (`password-attuale`, Coda 261).
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:password-attuale');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

// Two-factor challenge routes with the ensure-two-factor-challenge-session middleware
Route::middleware('ensure-two-factor-challenge-session')->group(function () {
    
    Route::inertia('two-factor-challenge', 'auth/TwoFactorChallenge')
        ->name('two-factor.challenge');
    
    // I limiti veri sono nel controller (per utente e per sessione); questo è un tetto alto (Coda 259).
    Route::post('two-factor-challenge', [TwoFactorAuthChallengeController::class, 'store'])
        ->middleware('throttle:due-fattori');
    
});

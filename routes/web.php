<?php

use App\Http\Controllers\Auth\NewUserPasswordController;
use App\Http\Controllers\Condomini\CondominioController;
use App\Http\Controllers\Condomini\FetchCondominiController;
use App\Http\Controllers\Frontend\WelcomeController;
use App\Http\Controllers\Inviti\InvitoController;
use App\Http\Controllers\Inviti\InvitoRegisteredUserController;
use App\Http\Controllers\Permissions\PermissionController;
use App\Http\Controllers\Permissions\RevokePermissionFromUserController;
use App\Http\Controllers\Roles\RevokePermissionFromRoleController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Segnalazioni\SegnalazioniStatsController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserReinviteController;
use App\Http\Controllers\Users\UserStatusController;
use App\Http\Controllers\Users\UserVerifyController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)
    ->name('home');

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/
Route::resource('/utenti', UserController::class)
    ->middleware(['auth', 'verified']); 

Route::put('/utenti/{user}/suspend', [UserStatusController::class, 'suspend'])
    ->middleware(['auth', 'verified'])
    ->name('utenti.suspend');

Route::put('/utenti/{user}/unsuspend', [UserStatusController::class, 'unsuspend'])
    ->middleware(['auth', 'verified'])
    ->name('utenti.unsuspend');

Route::post('/utenti/reinvite/{email}', [UserReinviteController::class, 'reinviteUser'])
    ->name('utenti.reinvite');

Route::delete('users/{user}/permissions/{permission}', RevokePermissionFromUserController::class)
    ->middleware(['auth', 'verified'])
    ->name('users.permissions.destroy');

Route::put('/utenti/{user}/toggle-verification', UserVerifyController::class)
    ->middleware(['auth', 'verified'])
    ->name('utenti.toggle-verification');

/*
|--------------------------------------------------------------------------
| Roles Routes
|--------------------------------------------------------------------------
*/
Route::resource('/ruoli', RoleController::class)
    ->middleware(['auth', 'verified']);

Route::delete('roles/{role}/permissions/{permission}', RevokePermissionFromRoleController::class)
    ->middleware(['auth', 'verified'])
    ->name('ruoli.permissions.destroy');

/*
|--------------------------------------------------------------------------
| Permission Routes
|--------------------------------------------------------------------------
*/
Route::get('/permessi', [PermissionController::class, 'index'] )
    ->middleware(['auth', 'verified']);

Route::get('/segnalazioni/stats', SegnalazioniStatsController::class)
    ->middleware(['auth', 'verified'])
    ->name('segnalazioni.stats');

/*
|--------------------------------------------------------------------------
| Condomini Routes
|--------------------------------------------------------------------------
*/
Route::resource('/condomini', CondominioController::class)
    ->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Visualizza condomini'])
    ->parameters([
        'condomini' => 'condominio'
    ]);

Route::get('/condomini/options', [CondominioController::class, 'options'])
    ->name('condomini.options');

Route::get('/fetch-condomini', FetchCondominiController::class)
    ->middleware(['auth', 'verified']);

/*
|--------------------------------------------------------------------------
| Passwords Routes
|--------------------------------------------------------------------------
*/
Route::get('/password/new/', [NewUserPasswordController::class, 'showResetForm'])
    ->name('password.new')
    ->middleware('signed'); 

Route::post('/password/new', [NewUserPasswordController::class, 'reset'])
    ->name('password.create');

/*
|--------------------------------------------------------------------------
| Inviti Routes
|--------------------------------------------------------------------------
*/
Route::resource('/inviti', InvitoController::class)
    ->middleware(['auth', 'verified']);

Route::get('/invito/register/', [InvitoRegisteredUserController::class, 'show'])
    ->name('invito.register')
    ->middleware('signed', 'throttle:6,1');

/*
|--------------------------------------------------------------------------
| Settings nd Auth Routes
|--------------------------------------------------------------------------
*/
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/user.php';


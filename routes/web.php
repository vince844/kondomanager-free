<?php

use App\Http\Controllers\Anagrafiche\AnagraficaController;
use App\Http\Controllers\Anagrafiche\FetchAnagraficheController;
use App\Http\Controllers\Anagrafiche\UserAnagraficaController;
use App\Http\Controllers\Auth\NewUserPasswordController;
use App\Http\Controllers\Comunicazioni\ComunicazioneController;
use App\Http\Controllers\Condomini\CondominioController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\UserDashboardController;
use App\Http\Controllers\Inviti\InvitoController;
use App\Http\Controllers\Inviti\InvitoRegisteredUserController;
use App\Http\Controllers\Permissions\PermissionController;
use App\Http\Controllers\Permissions\RevokePermissionFromUserController;
use App\Http\Controllers\Roles\RevokePermissionFromRoleController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Segnalazioni\SegnalazioneController;
use App\Http\Controllers\Segnalazioni\UserSegnalazioneController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserReinviteController;
use App\Http\Controllers\Users\UserStatusController;
use App\Models\Anagrafica;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Http\Request;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

/* Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'CheckSuspendedUser', 'CheckHasAnagrafica'])->name('dashboard');  */

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/
Route::resource('/utenti', UserController::class)->middleware(['auth', 'verified']); 
Route::put('/utenti/{user}/suspend', [UserStatusController::class, 'suspend'])->middleware(['auth', 'verified'])->name('utenti.suspend'); 
Route::put('/utenti/{user}/unsuspend', [UserStatusController::class, 'unsuspend'])->middleware(['auth', 'verified'])->name('utenti.unsuspend');
Route::post('/utenti/reinvite/{email}', [UserReinviteController::class, 'reinviteUser'])->name('utenti.reinvite');
Route::delete('users/{user}/permissions/{permission}', RevokePermissionFromUserController::class)->middleware(['auth', 'verified'])->name('users.permissions.destroy');

/*
|--------------------------------------------------------------------------
| Roles Routes
|--------------------------------------------------------------------------
*/
Route::resource('/ruoli', RoleController::class)->middleware(['auth', 'verified']);
Route::delete('roles/{role}/permissions/{permission}', RevokePermissionFromRoleController::class)->middleware(['auth', 'verified'])->name('ruoli.permissions.destroy');

/*
|--------------------------------------------------------------------------
| Permission Routes
|--------------------------------------------------------------------------
*/
Route::get('/permessi', [PermissionController::class, 'index'] )->middleware(['auth', 'verified']);

Route::get('/fetch-anagrafiche', [FetchAnagraficheController::class, 'fetchAnagrafiche']);

// Admin routes
Route::prefix('admin')->as('admin.')->middleware(['auth', 'verified', 'role:amministratore|collaboratore'])->group(function () {
    Route::resource('anagrafiche', AnagraficaController::class);
    Route::resource('segnalazioni', SegnalazioneController::class)->parameters([
        'segnalazioni' => 'segnalazione'
    ]);
    Route::resource('comunicazioni', ComunicazioneController::class)->parameters([
        'comunicazioni' => 'comunicazione'
    ]);
    Route::post('segnalazioni/{segnalazione}/toggle-resolve', [SegnalazioneController::class, 'toggleResolve'])->name('segnalazioni.toggleResolve');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

// User routes
Route::prefix('user')->as('user.')->middleware(['auth', 'verified'])->group(function () {
    Route::resource('anagrafiche', UserAnagraficaController::class);
    Route::resource('segnalazioni', UserSegnalazioneController::class)->parameters([
        'segnalazioni' => 'segnalazione'
    ]);
    Route::get('/dashboard', UserDashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Condomini Routes
|--------------------------------------------------------------------------
*/
Route::resource('/condomini', CondominioController::class)->middleware(['auth', 'verified']);

/*
|--------------------------------------------------------------------------
| Passwords Routes
|--------------------------------------------------------------------------
*/
Route::get('/password/new/', [NewUserPasswordController::class, 'showResetForm'])->name('password.new')->middleware('signed'); 
Route::post('/password/new', [NewUserPasswordController::class, 'reset'])->name('password.create');

/*
|--------------------------------------------------------------------------
| Inviti Routes
|--------------------------------------------------------------------------
*/
Route::resource('/inviti', InvitoController::class)->middleware(['auth', 'verified']);
Route::get('/invito/register/', [InvitoRegisteredUserController::class, 'show'])->name('invito.register')->middleware('signed', 'throttle:6,1');


/*
|--------------------------------------------------------------------------
| Settings nd Auth Routes
|--------------------------------------------------------------------------
*/
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


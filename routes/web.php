<?php

use App\Http\Controllers\Anagrafiche\AnagraficaController;
use App\Http\Controllers\Auth\NewUserPasswordController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\Condomini\CondominioController;
use App\Http\Controllers\Permissions\PermissionController;
use App\Http\Controllers\Permissions\RevokePermissionFromUserController;
use App\Http\Controllers\Roles\RevokePermissionFromRoleController;
use App\Http\Controllers\Roles\RoleController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::resource('/utenti', UserController::class)->middleware(['auth', 'verified']); 
Route::resource('/ruoli', RoleController::class)->middleware(['auth', 'verified']);
Route::get('/permessi', [PermissionController::class, 'index'] )->middleware(['auth', 'verified']);
Route::delete('roles/{role}/permissions/{permission}', RevokePermissionFromRoleController::class)->middleware(['auth', 'verified'])->name('ruoli.permissions.destroy');
Route::delete('users/{user}/permissions/{permission}', RevokePermissionFromUserController::class)->middleware(['auth', 'verified'])->name('users.permissions.destroy');
Route::resource('/anagrafiche', AnagraficaController::class)->middleware(['auth', 'verified']);
Route::resource('/condomini', CondominioController::class)->middleware(['auth', 'verified']);

Route::get('/password/new/', [NewUserPasswordController::class, 'showResetForm'])->name('password.new')->middleware('signed'); ;
Route::post('/password/new', [NewUserPasswordController::class, 'reset'])->name('password.create');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


<?php

use App\Http\Controllers\Auth\NewUserPasswordController;
use App\Http\Controllers\BuildingController;
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
Route::resource('/condomini', BuildingController::class)->middleware(['auth', 'verified']);

Route::get('/password/new/', [NewUserPasswordController::class, 'showResetForm'])->name('password.new')->middleware('signed'); ;
Route::post('/password/new', [NewUserPasswordController::class, 'reset'])->name('password.create');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


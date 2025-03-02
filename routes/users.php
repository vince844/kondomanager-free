<?php

use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

/* Route::get('/utenti', function () {
    return 'olaaaa';
})->middleware(['auth', 'verified'])->name('utenti'); */

Route::resource('/utenti', UserController::class)->middleware(['auth', 'verified']);
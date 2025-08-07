<?php

use App\Http\Controllers\Gestionale\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/gestionale/{condominio}', DashboardController::class)
    ->name('gestionale.index');
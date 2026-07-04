<?php

use App\Http\Controllers\Installer\InstallController;
use App\Http\Middleware\CheckInstaller;
use App\Livewire\Installer\InstallerWizard;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', CheckInstaller::class])->group(function () {
    Route::get('/install', [InstallController::class, 'start'])->name('install.start');
    Route::livewire('/install/{step}', InstallerWizard::class)->name('install.step');
});

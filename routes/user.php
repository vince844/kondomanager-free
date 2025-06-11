<?php

use App\Http\Controllers\Anagrafiche\UserAnagraficaController;
use App\Http\Controllers\Comunicazioni\UserComunicazioneController;
use App\Http\Controllers\Dashboard\UserDashboardController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Segnalazioni\UserSegnalazioneController;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->as('user.')->middleware(['auth', 'verified'])->group(function () {
    
    Route::get('/dashboard', UserDashboardController::class)->name('dashboard');
    
    Route::resource('anagrafiche', UserAnagraficaController::class);
    
    Route::resource('segnalazioni', UserSegnalazioneController::class)->parameters([
        'segnalazioni' => 'segnalazione'
    ]);
    
    Route::resource('comunicazioni', UserComunicazioneController::class)->parameters([
        'comunicazioni' => 'comunicazione'
    ]);
   
    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('settings.notifications.index');
    
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('settings.notifications.update');

});
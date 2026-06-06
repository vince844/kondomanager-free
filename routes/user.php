<?php

use App\Http\Controllers\Anagrafiche\Utenti\AnagraficaController;
use App\Http\Controllers\Comunicazioni\Utenti\ComunicazioneController;
use App\Http\Controllers\Documenti\Utenti\CategoriaDocumentoController;
use App\Http\Controllers\Documenti\Utenti\DocumentoController;
use App\Http\Controllers\Segnalazioni\Utenti\SegnalazioneController;
use App\Http\Controllers\Dashboard\UserDashboardController;
use App\Http\Controllers\Eventi\Utenti\EventoController;
use App\Http\Controllers\Eventi\Utenti\PaymentReportingController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Segnalazioni\CommentoController;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->as('user.')->middleware(['auth', 'verified'])->group(function () {
    
    Route::get('/dashboard', UserDashboardController::class)
        ->name('dashboard');
    
    Route::resource('anagrafiche', AnagraficaController::class);
    
    Route::resource('segnalazioni', SegnalazioneController::class)
        ->parameters([
            'segnalazioni' => 'segnalazione'
        ]);
    
    /*
    |--------------------------------------------------------------------------
    | Commenti Segnalazioni Routes (Utenti)
    |--------------------------------------------------------------------------
    */
    Route::post('segnalazioni/{segnalazione}/commenti', [CommentoController::class, 'store'])
        ->name('segnalazioni.commenti.store');

    Route::patch('commenti/{commento}', [CommentoController::class, 'update'])
        ->name('commenti.update');

    Route::delete('commenti/{commento}', [CommentoController::class, 'destroy'])
        ->name('commenti.destroy');

    Route::resource('comunicazioni', ComunicazioneController::class)
        ->parameters([
            'comunicazioni' => 'comunicazione'
        ]);

    Route::resource('documenti', DocumentoController::class)
        ->parameters([
            'documenti' => 'documento'
        ]);
    
    // Rotta per segnalare il pagamento (Single Action Controller)
    Route::post('eventi/{evento}/segnala-pagamento', PaymentReportingController::class)
        ->name('eventi.report_payment');

    Route::resource('eventi', EventoController::class)
        ->parameters([
            'eventi' => 'evento'
        ]);

    Route::post('documenti/{documento}', [DocumentoController::class, 'update'])
        ->name('documenti.update');

    Route::get('documenti/{documento}/download', [DocumentoController::class, 'download'])
        ->name('documenti.download');

    Route::resource('categorie-documenti', CategoriaDocumentoController::class)
        ->parameters([
            'categorie-documenti' => 'categoriaDocumento'
        ]);

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('settings.notifications.index');
    
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('settings.notifications.update');

});
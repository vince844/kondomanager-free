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

    // La pagina dove chi non ha ancora un'anagrafica completa i suoi dati, e le preferenze delle
    // notifiche: restano fuori dal gruppo qui sotto, che ci rimanda chi non ce l'ha.
    Route::resource('anagrafiche', AnagraficaController::class);

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('settings.notifications.index');
    
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('settings.notifications.update');

    // Il portale legge i palazzi dall'anagrafica collegata all'account: senza, le pagine andavano in
    // errore 500. Chi non ce l'ha (e non ha il pannello) va a completare i suoi dati, con la frase
    // che dice perché (decisione 134.4, `CheckHasAnagrafica`).
    Route::middleware('CheckHasAnagrafica')->group(function () {

    Route::get('/dashboard', UserDashboardController::class)
        ->name('dashboard');
    
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

    // `only()` e non `except(['update'])`: l'`update` sta a parte più sotto perché accetta anche
    // `POST`. L'`except` però lasciava registrate anche `index` e `show`, che il
    // `DocumentoController` dell'area utente non implementa e che rispondevano **500**. Il
    // condòmino i documenti li sfoglia **per categoria** (`categorie-documenti.index` e
    // `.show`), non da un elenco unico: quelle due schermate non sono mai esistite. Rimosse
    // nella beta.62.
    Route::resource('documenti', DocumentoController::class)
        ->parameters([
            'documenti' => 'documento'
        ])
        ->only(['create', 'store', 'edit', 'destroy']);
    
    // Rotta per segnalare il pagamento (Single Action Controller)
    Route::post('eventi/{evento}/segnala-pagamento', PaymentReportingController::class)
        ->name('eventi.report_payment');

    Route::resource('eventi', EventoController::class)
        ->parameters([
            'eventi' => 'evento'
        ]);

    Route::match(['put', 'patch', 'post'], 'documenti/{documento}', [DocumentoController::class, 'update'])
        ->name('documenti.update');

    Route::get('documenti/{documento}/download', [DocumentoController::class, 'download'])
        ->name('documenti.download');

    // `only()` e non un `resource` intero: il `CategoriaDocumentoController` dell'area utente
    // implementa **solo** `index` (l'elenco delle categorie) e `show` (i documenti di una
    // categoria). Le altre cinque — `create`, `store`, `edit`, `update`, `destroy` — puntavano a
    // metodi inesistenti e rispondevano **500**: il condòmino le categorie le consulta, non le
    // gestisce. Rimosse nella beta.62.
    Route::resource('categorie-documenti', CategoriaDocumentoController::class)
        ->parameters([
            'categorie-documenti' => 'categoriaDocumento'
        ])
        ->only(['index', 'show']);

    }); // fine del gruppo con CheckHasAnagrafica

});
<?php

use App\Http\Controllers\Anagrafiche\AnagraficaController;
use App\Http\Controllers\Anagrafiche\FetchAnagraficheController;
use App\Http\Controllers\Api\TestContabilitaController;
use App\Http\Controllers\Comunicazioni\ComunicazioneApprovalController;
use App\Http\Controllers\Comunicazioni\ComunicazioneController;
use App\Http\Controllers\Dashboard\ActionInboxController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Documenti\CategoriaDocumentoController;
use App\Http\Controllers\Documenti\DocumentoApprovalController;
use App\Http\Controllers\Documenti\DocumentoController;
use App\Http\Controllers\Documenti\FetchCategorieController;
use App\Http\Controllers\Eventi\ApprovalController;
use App\Http\Controllers\Eventi\EventoController;
use App\Http\Controllers\Eventi\FetchCategorieController as EventiFetchCategorieController;
use App\Http\Controllers\Fornitori\Anagrafiche\FornitoreAnagraficaController;
use App\Http\Controllers\Fornitori\Documenti\FornitoreDocumentoController;
use App\Http\Controllers\Fornitori\FornitoreController;
use App\Http\Controllers\Fornitori\FornitoreSituazioneDebitoriaController;
use App\Http\Controllers\Newsletter\NewsletterController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Segnalazioni\CommentoController;
use App\Http\Controllers\Segnalazioni\SegnalazioneApprovalController;
use App\Http\Controllers\Segnalazioni\SegnalazioneController;
use App\Http\Middleware\CheckForPendingUpdates;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->as('admin.')
->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Accesso pannello amministratore', CheckForPendingUpdates::class])
->group(function () {

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    // ROTTA NEWSLETTER
    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
        ->name('newsletter.subscribe');

    /*
    |--------------------------------------------------------------------------
    | Inbox console routes
    |--------------------------------------------------------------------------
    */
    Route::get('/inbox', ActionInboxController::class)->name('inbox');

    Route::post('/admin/inbox/{task}/reject', [ActionInboxController::class, 'reject'])
        ->name('inbox.reject');

    Route::patch('/admin/inbox/{task}/complete', [ActionInboxController::class, 'complete'])
        ->name('inbox.complete');

    /*
    |--------------------------------------------------------------------------
    | Anagrafiche e fornitori routes
    |--------------------------------------------------------------------------
    */
    Route::get('/fetch-anagrafiche', [FetchAnagraficheController::class, 'fetchAnagrafiche']);

    Route::resource('anagrafiche', AnagraficaController::class)
        ->parameters([
            'anagrafiche' => 'anagrafica'
        ]);

    Route::resource('fornitori', FornitoreController::class)
        ->parameters([
            'fornitori' => 'fornitore'
        ]);

    Route::resource('fornitori.anagrafiche', FornitoreAnagraficaController::class)
        ->parameters([
            'fornitori' => 'fornitore',
            'anagrafiche' => 'anagrafica'
        ]);

    Route::resource('fornitori.documenti', FornitoreDocumentoController::class)
        ->parameters([
            'fornitori' => 'fornitore',
            'documenti' => 'documento'

        ]);

    /*
    |--------------------------------------------------------------------------
    | Situazione Debitoria Fornitori (Double Lock Engine)
    |--------------------------------------------------------------------------
    */
    Route::resource('fornitori.situazione-debitoria', FornitoreSituazioneDebitoriaController::class)
        ->parameters([
            'fornitori' => 'fornitore',
            'situazione-debitoria' => 'saldo' // Questo mappa l'ID dell'URL alla variabile $saldo nel controller
        ])
        ->only(['index', 'store', 'destroy']);

    Route::resource('categorie', CategoriaDocumentoController::class)
        ->parameters([
            'categorie' => 'categoria'
        ])
        ->except(['store']);

    Route::resource('eventi', EventoController::class)
        ->parameters([
            'eventi' => 'evento'
        ]);

    Route::get('/fetch-categorie-documenti', FetchCategorieController::class)
        ->name('categorie.documenti');

    Route::get('/fetch-categorie-eventi', EventiFetchCategorieController::class)
        ->name('categorie.eventi');

    Route::resource('segnalazioni', SegnalazioneController::class)
        ->parameters([
            'segnalazioni' => 'segnalazione'
        ]);

    /*
    |--------------------------------------------------------------------------
    | Commenti Segnalazioni Routes
    |--------------------------------------------------------------------------
    */
    Route::post('segnalazioni/{segnalazione}/commenti', [CommentoController::class, 'store'])
        ->name('segnalazioni.commenti.store');

    Route::patch('segnalazioni/{segnalazione}/commenti/toggle', [CommentoController::class, 'toggle'])
        ->name('segnalazioni.commenti.toggle');

    Route::patch('commenti/{commento}', [CommentoController::class, 'update'])
        ->name('commenti.update');

    Route::delete('commenti/{commento}', [CommentoController::class, 'destroy'])
        ->name('commenti.destroy');

    Route::post('commenti/{commento}/approva', [CommentoController::class, 'approva'])
        ->name('commenti.approva');

    Route::post('commenti/{commento}/modera', [CommentoController::class, 'modera'])
        ->name('commenti.modera');

    Route::resource('comunicazioni', ComunicazioneController::class)
        ->parameters([
            'comunicazioni' => 'comunicazione'
        ]);

    Route::resource('documenti', DocumentoController::class)
        ->parameters([
            'documenti' => 'documento'
        ])
        ->except(['update']);

    Route::match(['put', 'patch', 'post'], 'documenti/{documento}', [DocumentoController::class, 'update'])
        ->name('documenti.update');

    Route::get('documenti/{documento}/download', [DocumentoController::class, 'download'])
        ->name('documenti.download');

    Route::post('/categorie-documento', [CategoriaDocumentoController::class, 'store'])
        ->name('categorie.store');

    Route::put('eventi/{evento}/toggle-approval', ApprovalController::class)
        ->name('eventi.toggle-approval');

    Route::put('documenti/{documento}/toggle-approval', DocumentoApprovalController::class)
        ->name('documenti.toggle-approval');

    Route::put('comunicazioni/{comunicazione}/toggle-approval', ComunicazioneApprovalController::class)
        ->name('comunicazioni.toggle-approval');

    Route::put('segnalazioni/{segnalazione}/toggle-approval', SegnalazioneApprovalController::class)
        ->name('segnalazioni.toggle-approval');

    Route::post('segnalazioni/{segnalazione}/toggle-resolve', [SegnalazioneController::class, 'toggleResolve'])
        ->name('segnalazioni.toggleResolve');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('settings.notifications.index');

    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('settings.notifications.update');

    // Rotta di TEST per sviluppatori: Verifica Quadratura Partita Doppia
    Route::get('/test-quadratura/{condominio}', [TestContabilitaController::class, 'checkQuadratura']);

    require __DIR__.'/gestionale.php';
});

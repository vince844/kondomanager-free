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
use App\Http\Controllers\Anagrafiche\Documenti\AnagraficaDocumentoController;
use App\Http\Controllers\Fornitori\Categorie\CategoriaFornitoreController;
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

    // La scheda «Documenti» dell'anagrafica, dalla 1.11.0-beta.9: i documenti **della persona** —
    // copia del documento d'identità, deleghe, contratti — che da qui si caricano, si rinominano e
    // si eliminano, come già si fa sulla scheda del fornitore.
    //
    // `only()` e non un `resource` intero: manca `show`, perché un documento non ha una pagina di
    // dettaglio — si apre il file, oppure lo si modifica. Registrarla vorrebbe dire una rotta verso
    // un metodo inesistente in attesa di qualcuno, che su questo progetto è già costato due
    // segnalazioni dal forum.
    Route::resource('anagrafiche.documenti', AnagraficaDocumentoController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->parameters([
            'anagrafiche' => 'anagrafica',
            'documenti'   => 'documento',
        ]);

    Route::resource('fornitori', FornitoreController::class)
        ->parameters([
            'fornitori' => 'fornitore'
        ]);

    // `only()` e non un `resource` intero: `FornitoreAnagraficaController` implementa **solo**
    // queste quattro azioni. Le altre tre — `show`, `edit`, `update` — puntavano a metodi
    // inesistenti e rispondevano **500** a chiunque ci arrivasse per URL. I referenti di un
    // fornitore si aggiungono e si tolgono dall'elenco del fornitore; la scheda del singolo
    // referente non è mai esistita. Rimosse nella beta.62.
    // Le categorie di fornitore, gestibili dalla 1.11.0-beta.9. `only()` e non un `resource` intero,
    // per la ragione già scritta più sotto per le categorie dei documenti: una categoria non ha una
    // pagina di dettaglio né una di creazione — si crea, si rinomina e si elimina dall'elenco, e
    // dalla tendina del modulo del fornitore. `create`, `show` ed `edit` sarebbero tre rotte
    // registrate verso metodi che non esistono, cioè tre **500** in attesa di qualcuno.
    Route::resource('categorie-fornitore', CategoriaFornitoreController::class)
        ->parameters([
            'categorie-fornitore' => 'categoria'
        ])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::resource('fornitori.anagrafiche', FornitoreAnagraficaController::class)
        ->only(['index', 'create', 'store', 'destroy'])
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

    // `only()` e non `except(['store'])`: quell'`except` non era una potatura ragionata, serviva
    // solo a evitare la collisione di nome con la rotta di `store` registrata a mano più sotto
    // (`categorie-documento`), e nel frattempo teneva registrate **tre rotte fantasma**.
    // `CategoriaDocumentoController` implementa `index`, `store`, `update`, `destroy`: `create`,
    // `show` ed `edit` puntavano a metodi inesistenti e rispondevano **500**.
    //
    // `show` era l'unica delle diciassette effettivamente linkata — il nome della categoria
    // nell'elenco — ed è arrivata dal forum nella beta.62: *«cliccando su una qualsiasi delle
    // categorie mi compare Call to undefined method»*. Una categoria non ha una pagina di
    // dettaglio: si crea, si rinomina e si elimina dall'elenco, come i referenti dei fornitori.
    Route::resource('categorie', CategoriaDocumentoController::class)
        ->parameters([
            'categorie' => 'categoria'
        ])
        ->only(['index', 'update', 'destroy']);

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

    // `only()` e non `except(['update'])`: l'`update` sta a parte qui sotto perché accetta anche
    // `POST` (i moduli con allegato). L'`except` però lasciava registrata anche `show`, che
    // `DocumentoController` non implementa e che rispondeva **500**: un documento si scarica
    // (`documenti.download`) o si modifica, non si "apre in una scheda". Rimossa nella beta.62.
    Route::resource('documenti', DocumentoController::class)
        ->parameters([
            'documenti' => 'documento'
        ])
        ->only(['index', 'create', 'store', 'edit', 'destroy']);

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

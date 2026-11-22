<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Allegati\AllegatoController;
use App\Http\Controllers\Anagrafiche\AnagraficaController;
use App\Http\Controllers\Anagrafiche\FetchAnagraficheController;
use App\Http\Controllers\Api\TestContabilitaController;
use App\Http\Controllers\Commenti\CommentiPaginaController;
use App\Http\Controllers\Comunicazioni\ComunicazioneApprovalController;
use App\Http\Controllers\Comunicazioni\ComunicazioneCommentoController;
use App\Http\Controllers\Comunicazioni\ComunicazioneController;
use App\Http\Controllers\Dashboard\ActionInboxController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\SeiMesiStudioController;
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
use App\Http\Controllers\Segnalazioni\SegnalazioneAssegnazioneController;
use App\Http\Controllers\Segnalazioni\SegnalazioneController;
use App\Http\Controllers\Segnalazioni\SegnalazioneRendiPubblicaController;
use App\Http\Middleware\CheckForPendingUpdates;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->as('admin.')
->middleware(['auth', 'verified', 'role_or_permission:amministratore|collaboratore|Accesso pannello amministratore', CheckForPendingUpdates::class])
->group(function () {

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    // La lista dello studio dei sei mesi per agire: l'Action inbox chiede un condominio alla volta, dopo che la pagina si è aperta.
    Route::get('/dashboard/sei-mesi/{condominio}', SeiMesiStudioController::class)
        ->name('dashboard.sei-mesi');

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

    // Le risposte a un compito: «Fatto», «Non serve», «Ricordamelo».
    Route::post('/admin/inbox/{task}/risposta', [ActionInboxController::class, 'risposta'])
        ->name('inbox.risposta');

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

    // La linguetta «Assemblee» della scheda della persona (decisione 651): solo lo studio, con «Vede le assemblee». Si legge e basta.
    Route::get('anagrafiche/{anagrafica}/assemblee', [\App\Http\Controllers\Anagrafiche\AnagraficaAssembleeController::class, 'index'])
        ->whereNumber('anagrafica')->name('anagrafiche.assemblee');

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

    // Scaricare e approvare un documento del fornitore si fa dalla sua scheda, con i permessi del fornitore: le rotte
    // dell'archivio (`documenti.download`, `documenti.toggle-approval`) lavorano solo sui documenti dell'archivio.
    Route::get('fornitori/{fornitore}/documenti/{documento}/download', [FornitoreDocumentoController::class, 'download'])
        ->name('fornitori.documenti.download');

    Route::put('fornitori/{fornitore}/documenti/{documento}/toggle-approval', [FornitoreDocumentoController::class, 'approvazione'])
        ->name('fornitori.documenti.toggle-approval');

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

    Route::patch('segnalazioni/{segnalazione}/commenti/impostazioni', [CommentoController::class, 'impostazioni'])
        ->name('segnalazioni.commenti.impostazioni');

    Route::patch('commenti/{commento}', [CommentoController::class, 'update'])
        ->name('commenti.update');

    Route::delete('commenti/{commento}', [CommentoController::class, 'destroy'])
        ->name('commenti.destroy');

    Route::post('commenti/{commento}/approva', [CommentoController::class, 'approva'])
        ->name('commenti.approva');

    Route::post('commenti/{commento}/modera', [CommentoController::class, 'modera'])
        ->name('commenti.modera');

    Route::post('commenti/{commento}/mostra', [CommentoController::class, 'mostra'])
        ->name('commenti.mostra');

    /*
    |--------------------------------------------------------------------------
    | La pagina «Commenti» del pannello (Coda 657, decisione 442)
    |--------------------------------------------------------------------------
    | Le azioni sulla riga usano le rotte di sopra, quelle della scheda; qui solo l'elenco e le azioni in blocco.
    */
    Route::get('commenti', [CommentiPaginaController::class, 'index'])
        ->name('commenti.index');

    Route::post('commenti/approva-in-blocco', [CommentiPaginaController::class, 'approvaInBlocco'])
        ->name('commenti.approva-in-blocco');

    Route::post('commenti/nascondi-in-blocco', [CommentiPaginaController::class, 'nascondiInBlocco'])
        ->name('commenti.nascondi-in-blocco');

    Route::post('commenti/mostra-in-blocco', [CommentiPaginaController::class, 'mostraInBlocco'])
        ->name('commenti.mostra-in-blocco');

    Route::post('commenti/elimina-in-blocco', [CommentiPaginaController::class, 'eliminaInBlocco'])
        ->name('commenti.elimina-in-blocco');

    Route::resource('comunicazioni', ComunicazioneController::class)
        ->parameters([
            'comunicazioni' => 'comunicazione'
        ]);

    Route::post('comunicazioni/{comunicazione}/commenti', [ComunicazioneCommentoController::class, 'store'])
        ->name('comunicazioni.commenti.store');

    Route::patch('comunicazioni/{comunicazione}/commenti/impostazioni', [ComunicazioneCommentoController::class, 'impostazioni'])
        ->name('comunicazioni.commenti.impostazioni');

    /*
    |--------------------------------------------------------------------------
    | Allegati Segnalazioni e Comunicazioni Routes
    |--------------------------------------------------------------------------
    */
    Route::get('allegati', [AllegatoController::class, 'index'])
        ->name('allegati.index');

    Route::get('allegati/zip', [AllegatoController::class, 'zip'])
        ->name('allegati.zip');

    Route::post('allegati/approva-in-blocco', [AllegatoController::class, 'approvaInBlocco'])
        ->name('allegati.approva-in-blocco');

    Route::post('allegati/elimina-in-blocco', [AllegatoController::class, 'eliminaInBlocco'])
        ->name('allegati.elimina-in-blocco');

    Route::post('segnalazioni/{segnalazione}/allegati', [AllegatoController::class, 'storeSegnalazione'])
        ->name('segnalazioni.allegati.store');

    Route::patch('segnalazioni/{segnalazione}/allegati/impostazioni', [AllegatoController::class, 'impostazioniSegnalazione'])
        ->name('segnalazioni.allegati.impostazioni');

    Route::post('comunicazioni/{comunicazione}/allegati', [AllegatoController::class, 'storeComunicazione'])
        ->name('comunicazioni.allegati.store');

    Route::patch('comunicazioni/{comunicazione}/allegati/impostazioni', [AllegatoController::class, 'impostazioniComunicazione'])
        ->name('comunicazioni.allegati.impostazioni');

    Route::get('allegati/{allegato}/apri', [AllegatoController::class, 'apri'])
        ->name('allegati.apri');

    Route::get('allegati/{allegato}/scarica', [AllegatoController::class, 'scarica'])
        ->name('allegati.scarica');

    Route::post('allegati/{allegato}/approva', [AllegatoController::class, 'approva'])
        ->name('allegati.approva');

    Route::patch('allegati/{allegato}', [AllegatoController::class, 'update'])
        ->name('allegati.update');

    Route::delete('allegati/{allegato}', [AllegatoController::class, 'destroy'])
        ->name('allegati.destroy');

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

    // «Rendi pubblica» una segnalazione privata: un comando a sé, con la conferma nella pagina
    // (Coda 304, decisione 127.4). Il contrario non esiste.
    Route::put('segnalazioni/{segnalazione}/rendi-pubblica', SegnalazioneRendiPubblicaController::class)
        ->name('segnalazioni.rendi-pubblica');

    Route::patch('segnalazioni/{segnalazione}/assegnazione', SegnalazioneAssegnazioneController::class)
        ->name('segnalazioni.assegna');

    // Le azioni veloci della scheda (Coda 671): lo stato lavorazione o la priorità, con lo stesso permesso di «Modifica».
    Route::patch('segnalazioni/{segnalazione}/parametro', [SegnalazioneController::class, 'aggiornaParametro'])
        ->name('segnalazioni.parametro');

    Route::post('segnalazioni/{segnalazione}/toggle-resolve', [SegnalazioneController::class, 'toggleResolve'])
        ->name('segnalazioni.toggleResolve');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('settings.notifications.index');

    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('settings.notifications.update');

    // Il controllo di quadratura del giornale (bilancio di verifica in JSON, nessuna pagina lo
    // chiama) è uno strumento di sviluppo: esiste solo in `local` e in `testing`, dove lo usano i
    // test del giornale (`phpunit.xml` mette `APP_ENV=testing`). In produzione la rotta non è
    // registrata, e nemmeno nella cache delle rotte che l'immagine scrive all'avvio (Coda 312).
    if (app()->environment(['local', 'testing'])) {
        Route::get('/test-quadratura/{condominio}', [TestContabilitaController::class, 'checkQuadratura']);
    }

    /*
     * Il registro delle modifiche (1.12.0-beta.13, §8, §14 e P18 del progetto della tappa 1). Il controllo sta nei controller: il permesso «Vede la
     * storia delle schede» o «Vede il registro delle modifiche», e mai i ruoli `utente` e `fornitore`, che il gruppo qui sopra lascerebbe entrare con
     * «Accesso pannello amministratore». Nessuna `Route::resource` ha questo prefisso: un indirizzo nuovo sotto `registro-modifiche` non finisce in uno
     * `show()` di un'altra rotta (il difetto della Coda ㉗; lo controlla `RegistroModifichePermessiTest`).
     */
    Route::get('registro-modifiche/storia/{tipo}/{id?}', \App\Http\Controllers\RegistroModifiche\StoriaController::class)
        ->where(['tipo' => '[a-z_]+', 'id' => '[0-9]+'])
        ->name('registro-modifiche.storia');
    Route::get('registro-modifiche/righe/{riga}/ripristina', [\App\Http\Controllers\RegistroModifiche\RipristinoController::class, 'anteprima'])
        ->whereNumber('riga')
        ->name('registro-modifiche.ripristina.anteprima');
    Route::post('registro-modifiche/righe/{riga}/ripristina', [\App\Http\Controllers\RegistroModifiche\RipristinoController::class, 'ripristina'])
        ->whereNumber('riga')
        ->name('registro-modifiche.ripristina');
    Route::post('registro-modifiche/verifica', [\App\Http\Controllers\RegistroModifiche\VerificaController::class, 'verifica'])
        ->name('registro-modifiche.verifica');
    Route::get('registro-modifiche/verifica/rapporto', [\App\Http\Controllers\RegistroModifiche\VerificaController::class, 'rapporto'])
        ->name('registro-modifiche.verifica.rapporto');

    // Il registro delle modifiche, i casi speciali (1.12.0-beta.13): versioni dei documenti, oscuramento, conservazione, sospensione.
    require __DIR__.'/registro_modifiche_casi_speciali.php';

    // Le assemblee (Coda 679): le pagine dell'assemblea e della cerniera, poi la convocazione e il fascicolo, poi la sala, poi il verbale, poi il seguito.
    require __DIR__.'/assemblee.php';
    require __DIR__.'/assemblee_convocazione.php';
    require __DIR__.'/assemblee_sala.php';
    require __DIR__.'/assemblee_verbale.php';
    require __DIR__.'/assemblee_seguito.php';

    require __DIR__.'/gestionale.php';
});

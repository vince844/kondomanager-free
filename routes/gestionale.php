<?php

use App\Http\Controllers\Gestionale\Controlli\ControlliPostImportController;
use App\Http\Controllers\Gestionale\Casse\CassaController;
use App\Http\Controllers\Gestionale\Dashboard\DashboardController;
use App\Http\Controllers\Gestionale\Esercizi\EsercizioController;
use App\Http\Controllers\Gestionale\Gestioni\GestioneController;
use App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController;
use App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController;
use App\Http\Controllers\Gestionale\Immobili\ImmobileController;
use App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController;
use App\Http\Controllers\Gestionale\Movimenti\GirocontoController;
use App\Http\Controllers\Gestionale\Movimenti\IncassoRateController;
use App\Http\Controllers\Gestionale\Movimenti\MovimentiController;
use App\Http\Controllers\Gestionale\Movimenti\PagamentoFornitoreController;
use App\Http\Controllers\Gestionale\Movimenti\RegolazioneImmediataController;
use App\Http\Controllers\Gestionale\Movimenti\DelegaF24Controller;
use App\Http\Controllers\Gestionale\Movimenti\DelegaF24PrintController;
use App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController;
use App\Http\Controllers\Gestionale\Movimenti\SituazioneDebitoriaController;
use App\Http\Controllers\Gestionale\Movimenti\StornoFatturaController;
use App\Http\Controllers\Gestionale\Movimenti\StornoIncassoController;
use App\Http\Controllers\Gestionale\Movimenti\StornoPagamentoController;
use App\Http\Controllers\Gestionale\Palazzine\PalazzinaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\AssociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\DissociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\AggiornaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\FetchCapitoliContiController;
use App\Http\Controllers\Gestionale\PianiConti\MovimentiPerVoceController;
use App\Http\Controllers\Gestionale\PianiConti\PianoContiController;
use App\Http\Controllers\Gestionale\PianiConti\PianoContiPrintController;
use App\Http\Controllers\Gestionale\PianiRate\BudgetMovementController;
use App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController;
use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
use App\Http\Controllers\Gestionale\PianiRate\FetchCapitoliPerGestioneController;
use App\Http\Controllers\Gestionale\PianiRate\FetchFattureStraordinarieController;
use App\Http\Controllers\Gestionale\PianiRate\PianoRatePrintController;
use App\Http\Controllers\Gestionale\PianiRate\PianoRateController;
use App\Http\Controllers\Gestionale\PianiRate\PianoRateGenerationController;
use App\Http\Controllers\Gestionale\Saldi\SaldoInizialeController;
use App\Http\Controllers\Gestionale\Scale\ScalaController;
use App\Http\Controllers\Gestionale\Struttura\StrutturaController;
use App\Http\Controllers\Gestionale\Tabelle\FetchTabelleController;
use App\Http\Controllers\Gestionale\Tabelle\Quote\TabellaQuotaController;
use App\Http\Controllers\Gestionale\Tabelle\TabellaController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,   
        EnsureCondominioHasPianoConti::class  
    ])
    ->group(function () {
    
    Route::get('/', DashboardController::class)
        ->name('index');
    
    // «Da controllare dopo l'importazione». Sta qui e non nel gruppo dell'import perché la
    // domanda arriva giorni dopo, quando l'uuid del lotto non ce l'ha più nessuno — e perché il
    // permesso giusto è quello del gestionale: chi non poteva importare deve poter sistemare.
    Route::get('/controlli-import', [ControlliPostImportController::class, 'index'])
        ->name('controlli-import.index');

    Route::put('/controlli-import/{batch}', [ControlliPostImportController::class, 'aggiorna'])
        ->name('controlli-import.aggiorna');

    Route::get('/struttura', [StrutturaController::class, 'index'])
        ->name('struttura.index');

    Route::get('/fetch-tabelle', FetchTabelleController::class)
        ->name('fetch-tabelle');

    Route::get('/fetch-capitoli-conti', FetchCapitoliContiController::class)
        ->name('fetch-capitoli-conti');

    Route::get('fetch-capitoli-gestione', FetchCapitoliPerGestioneController::class)
        ->name('fetch-capitoli-gestione');

    // --- INIZIO FIX: NUOVA ROUTE CARRELLO STRAORDINARIO ---
    Route::get('fetch-fatture-straordinarie', FetchFattureStraordinarieController::class)
        ->name('fetch-fatture-straordinarie');
    // --- FINE FIX ---
    
    Route::resource('palazzine', PalazzinaController::class)
        ->parameters(['palazzine' => 'palazzina']);
    
    Route::resource('scale', ScalaController::class)
        ->parameters(['scale' => 'scala']);
    
    Route::resource('saldi', SaldoInizialeController::class)
        ->parameters(['saldi' => 'saldo']);

    // Sblocco manuale di un lucchetto senza titolare (dati storici anteriori alla
    // beta.32, o il caso ambiguo che la migrazione di riparazione lascia chiuso
    // di proposito quando più piani rivendicano gli stessi saldi).
    Route::post('saldi/{saldo}/sblocca', [SaldoInizialeController::class, 'sblocca'])
        ->name('saldi.sblocca');

    // Già versato per voce di spesa: alimenta il netting del riparto (beta.26).
    Route::get('contributi', [\App\Http\Controllers\Gestionale\Contributi\ContributoVersatoController::class, 'index'])
        ->name('contributi.index');
    Route::get('contributi/{conto}', [\App\Http\Controllers\Gestionale\Contributi\ContributoVersatoController::class, 'edit'])
        ->name('contributi.edit');
    Route::put('contributi/{conto}', [\App\Http\Controllers\Gestionale\Contributi\ContributoVersatoController::class, 'update'])
        ->name('contributi.update');

    Route::get('esercizi/{esercizio}/fetch-saldi-analitici/{gestione}', [PianoRateController::class, 'fetchSaldiAnalitici'])
        ->name('fetch-saldi-analitici');
    
    Route::resource('immobili', ImmobileController::class)
        ->parameters(['immobili' => 'immobile']);

    Route::resource('immobili.anagrafiche', ImmobileAnagraficaController::class)
        ->parameters([
            'immobili' => 'immobile',
            'anagrafiche' => 'anagrafica'
        ]);
    
    Route::resource('immobili.documenti', ImmobileDocumentoController::class)
        ->parameters([
            'immobili'  => 'immobile',
            'documenti' => 'documento'
        ]);

    // Le pertinenze di un'unità: sola lettura. Il legame si dichiara dalla scheda della
    // **pertinenza**, non da qui — è la pertinenza che punta al principale, e un secondo punto di
    // scrittura sullo stesso dato sarebbe due verità con due regole.
    Route::get('immobili/{immobile}/pertinenze', [ImmobileController::class, 'pertinenze'])
        ->name('immobili.pertinenze.index');
    
    // --- CASSE ---
    Route::resource('casse', CassaController::class)
        ->parameters(['casse' => 'cassa']);

    // Porta a giornale il saldo di apertura di una cassa che l'ha in colonna ma non in
    // contabilità. È l'azione che mancava alla diagnosi del Libro Giornale: il widget
    // sapeva nominare la causa dello sbilancio e non offriva niente per curarla.
    Route::post('casse/{cassa}/registra-apertura', [CassaController::class, 'registraApertura'])
        ->name('casse.registra-apertura');
    
    Route::resource('tabelle', TabellaController::class)
        ->parameters(['tabelle' => 'tabella']);
    
    Route::prefix('tabelle/{tabella}')->group(function () {
        Route::get('/quote', [TabellaQuotaController::class, 'index'])->name('tabelle.quote.index');
        Route::put('/quote', [TabellaQuotaController::class, 'update'])->name('tabelle.quote.update');
    });

    Route::resource('esercizi', EsercizioController::class)
        ->parameters(['esercizi' => 'esercizio']);
    
    Route::resource('esercizi.gestioni', GestioneController::class)
        ->parameters([
            'esercizi' => 'esercizio',
            'gestioni' => 'gestione'
        ]);
    
    Route::resource('esercizi.piani-conti', PianoContiController::class)
        ->parameters([
            'esercizi'    => 'esercizio',
            'piani-conti' => 'pianoConto'
        ]);
        
    Route::get('esercizi/{esercizio}/piani-conti/{pianoConto}/print-distinta', [PianoContiPrintController::class, 'distinta'])
        ->name('esercizi.piani-conti.print-distinta');

    Route::get('esercizi/{esercizio}/piani-conti/{pianoConto}/print-riparto', [PianoContiPrintController::class, 'riparto'])
        ->name('esercizi.piani-conti.print-riparto');
    
    // `only()` e non un `resource` intero: `ContoController` implementa **solo** queste tre
    // azioni. Le altre quattro che `Route::resource` generava — `index`, `create`, `show`,
    // `edit` — puntavano a metodi inesistenti e rispondevano **500** a chiunque ci arrivasse
    // per URL. Nessuna pagina le linkava, quindi il difetto era latente: le voci di spesa si
    // gestiscono tutte dalla pagina del piano dei conti e dai suoi modali, e quelle quattro
    // schermate non sono mai state volute. Rimosse nella beta.48.
    Route::resource('esercizi.piani-conti.conti', ContoController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters([
            'esercizi'    => 'esercizio',
            'piani-conti' => 'pianoConto',
            'conti'       => 'conto'
        ]); 
    
    // Drill-down della colonna Consuntivo: i movimenti che compongono lo speso di
    // una voce. Non annidata sotto {pianoConto} perché il conto identifica già la
    // voce e il controller ne verifica l'appartenenza al condominio.
    Route::get('esercizi/{esercizio}/voci/{conto}/movimenti', MovimentiPerVoceController::class)
        ->name('esercizi.voci.movimenti');

    Route::post('esercizi/{esercizio}/piani-conti/{pianoConto}/conti/{conto}/associa-tabella', AssociaTabellaController::class)
        ->name('esercizi.piani-conti.conti.associa-tabella');

    Route::delete('esercizi/{esercizio}/piani-conti/{pianoConto}/conti/{conto}/dissocia-tabella/{tabella}', DissociaTabellaController::class)
        ->name('esercizi.piani-conti.conti.dissocia-tabella');
    
    Route::put('esercizi/{esercizio}/piani-conti/{pianoConto}/conti/{conto}/aggiorna-tabella/{tabella}', AggiornaTabellaController::class)
        ->name('esercizi.piani-conti.conti.aggiorna-tabella');

    Route::resource('esercizi.piani-rate', PianoRateController::class)
        ->parameters([
            'esercizi'   => 'esercizio',
            'piani-rate' => 'pianoRate',
        ]);

    Route::get('esercizi/{esercizio}/piani-rate/{pianoRate}/print-scadenziario', [PianoRatePrintController::class, 'scadenziario'])
        ->name('esercizi.piani-rate.print-scadenziario');

    Route::get('esercizi/{esercizio}/piani-rate/{pianoRate}/print-riparto-tabelle', [PianoRatePrintController::class, 'ripartoTabelle'])
        ->name('esercizi.piani-rate.print-riparto-tabelle');

    Route::get('esercizi/{esercizio}/piani-rate/{pianoRate}/print-riparto-capitoli', [PianoRatePrintController::class, 'ripartoCapitoli'])
        ->name('esercizi.piani-rate.print-riparto-capitoli');

    Route::put('/esercizi/{esercizio}/piani-rate/{pianoRate}/stato', [PianoRateController::class, 'updateStato'])
    ->name('piani-rate.update-stato');

    Route::post('esercizi/{esercizio}/piani-rate/{pianoRate}/publish-silent', [EmissioneRateController::class, 'publishSilent'])
        ->name('piani-rate.publish-silent');

    // Emissione Rate (Massiva)
    Route::post('/piani-rate/{pianoRate}/emetti', [EmissioneRateController::class, 'store'])
        ->name('piani-rate.emetti');

    // Annulla Emissione Singola Rata
    Route::delete('/piani-rate/{pianoRate}/rate/{rata}/annulla-emissione', [EmissioneRateController::class, 'destroy'])
        ->name('piani-rate.annulla-emissione');
    
    Route::delete('esercizi/{esercizio}/piani-rate/{pianoRate}/capitoli/{capitolo}', [PianoRateController::class, 'detachCapitolo'])
        ->name('piani-rate.capitoli.detach');
    
    // Route for "Sposta Spesa" (Budget Reallocation)
    Route::post('/piani-rate/{pianoRate}/move-budget', [BudgetMovementController::class, 'store'])
        ->name('piani-rate.move-budget');
    
    // Rotta per vedere l'estratto conto (accessibile dal piano rate)
    Route::get('/anagrafiche/{anagrafica}/estratto-conto', [EstrattoContoAnagraficaController::class, 'show'])
        ->name('anagrafiche.estratto-conto');
    
    Route::get('/anagrafiche/{anagrafica}/estratto-conto/print', [EstrattoContoAnagraficaController::class, 'print'])
        ->name('anagrafiche.estratto-conto.print');
    
    Route::post('/esercizi/{esercizio}/piani-rate/{pianoRate}/regenerate', PianoRateGenerationController::class)
    ->name('esercizi.piani-rate.regenerate');

    // Libro Giornale — elenco scritture contabili, annidato per esercizio così
    // il dropdown esercizio di PageHeaderGuide funziona out-of-the-box.
    Route::get('esercizi/{esercizio}/scritture', [ScritturaContabileController::class, 'index'])
        ->name('esercizi.scritture.index');

    Route::get('situazione-debitoria', SituazioneDebitoriaController::class)
        ->name('situazione-debitoria');
    
    Route::resource('movimenti-rate', IncassoRateController::class)
        ->parameters(['movimenti-rate' => 'scrittura']);

    Route::post('movimenti-rate/{scrittura}/storno', StornoIncassoController::class)
        ->name('movimenti-rate.storno');
    
    Route::get('/movimenti', [MovimentiController::class, 'index'])
        ->name('movimenti.index');

    // --- PRIMA NOTA DIRETTA: REGOLAZIONE IMMEDIATA (costo → banca, senza fattura) ---
    Route::get('/regolazioni-immediate/create', [RegolazioneImmediataController::class, 'create'])
        ->name('regolazioni-immediate.create');

    Route::post('/regolazioni-immediate', [RegolazioneImmediataController::class, 'store'])
        ->name('regolazioni-immediate.store');

    Route::post('/regolazioni-immediate/{scrittura}/storno', [RegolazioneImmediataController::class, 'storno'])
        ->name('regolazioni-immediate.storno');

    // --- GIROCONTI: spostamenti di liquidità fra casse (fondi = partizioni del c/c) ---
    Route::get('/giroconti', [GirocontoController::class, 'index'])
        ->name('giroconti.index');

    Route::get('/giroconti/create', [GirocontoController::class, 'create'])
        ->name('giroconti.create');

    Route::post('/giroconti', [GirocontoController::class, 'store'])
        ->name('giroconti.store');

    Route::post('/giroconti/{scrittura}/storno', [GirocontoController::class, 'storno'])
        ->name('giroconti.storno');

    Route::post('/giroconti/riallinea-fondi', [GirocontoController::class, 'riallinea'])
        ->name('giroconti.riallinea');

    // --- CICLO PASSIVO: FATTURE ---
    Route::get('/fatture', [FatturaPassivaController::class, 'index'])
        ->name('fatture.index');

    Route::get('/fatture/create', [FatturaPassivaController::class, 'create'])
        ->name('fatture.create');

    Route::post('/fatture', [FatturaPassivaController::class, 'store'])
        ->name('fatture.store');

    Route::get('/fatture/{fattura}', [FatturaPassivaController::class, 'show'])
        ->name('fatture.show');

    Route::get('/fatture/{fattura}/edit', [FatturaPassivaController::class, 'edit'])
        ->name('fatture.edit');

    Route::put('/fatture/{fattura}', [FatturaPassivaController::class, 'update'])
        ->name('fatture.update');

    Route::delete('/fatture/{fattura}', [FatturaPassivaController::class, 'destroy'])
        ->name('fatture.destroy');

    Route::post('/fatture/{fattura}/storno', StornoFatturaController::class)
        ->name('fatture.storno');
    
    // --- APPROVAZIONE BASE: transizione da_approvare → approvata ---
    Route::post('/fatture/{fattura}/approva', [FatturaPassivaController::class, 'approva'])
        ->name('fatture.approva');
    
    // --- RATIFICA SFORO: transizione sforo_motivato → approvata dopo delibera assembleare ---
    Route::post('/fatture/{fattura}/approva-sforo', [FatturaPassivaController::class, 'approvaSforo'])
        ->name('fatture.approva-sforo');
    
    Route::get('/fatture/{fattura}/download/{documento}', [FatturaPassivaController::class, 'download'])
        ->name('fatture.download');
    
    // --- CICLO PASSIVO: PAGAMENTI (v1.9.1) ---
    Route::get('/pagamenti-fornitori', [PagamentoFornitoreController::class, 'index'])
        ->name('pagamenti-fornitori.index');
        
    Route::get('/pagamenti-fornitori/create', [PagamentoFornitoreController::class, 'create'])
        ->name('pagamenti-fornitori.create');

    Route::post('/pagamenti-fornitori', [PagamentoFornitoreController::class, 'store'])
        ->name('pagamenti-fornitori.store');

    Route::get('/pagamenti-fornitori/pendenze', [PagamentoFornitoreController::class, 'pendenze'])
        ->name('pagamenti-fornitori.pendenze');

    Route::get('/pagamenti-fornitori/{pagamento}/distinta', [PagamentoFornitoreController::class, 'distinta'])
        ->name('pagamenti-fornitori.distinta');

    Route::get('/pagamenti-fornitori/{pagamento}', [PagamentoFornitoreController::class, 'show'])
        ->name('pagamenti-fornitori.show');

    Route::get('/pagamenti-fornitori/{pagamento}/edit', [PagamentoFornitoreController::class, 'edit'])
        ->name('pagamenti-fornitori.edit');

    Route::put('/pagamenti-fornitori/{pagamento}', [PagamentoFornitoreController::class, 'update'])
        ->name('pagamenti-fornitori.update');

    Route::post('/pagamenti-fornitori/{pagamento}/storno', StornoPagamentoController::class)
        ->name('pagamenti-fornitori.storno');

    // --- RITENUTE E DELEGHE F24 (v1.10.0-beta.38) ---
    Route::get('/f24', [DelegaF24Controller::class, 'index'])
        ->name('f24.index');

    Route::post('/f24/genera', [DelegaF24Controller::class, 'genera'])
        ->name('f24.genera');

    Route::get('/f24/{delega}', [DelegaF24Controller::class, 'show'])
        ->name('f24.show');

    // Il modello ministeriale, per chi paga allo sportello (v1.10.0-beta.39).
    Route::get('/f24/{delega}/modello', DelegaF24PrintController::class)
        ->name('f24.modello');

    Route::post('/f24/{delega}/versa', [DelegaF24Controller::class, 'conferma'])
        ->name('f24.versa');

    Route::post('/f24/{delega}/storna', [DelegaF24Controller::class, 'storna'])
        ->name('f24.storna');

    Route::post('/f24/{delega}/annulla', [DelegaF24Controller::class, 'annulla'])
        ->name('f24.annulla');

    // --- DETTAGLIO SCRITTURA CONTABILE (v1.9.1-beta.7) ---
    Route::get('/scritture/{scrittura}', [ScritturaContabileController::class, 'show'])
        ->name('scritture.show');
});
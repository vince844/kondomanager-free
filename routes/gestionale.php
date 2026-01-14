<?php

use App\Http\Controllers\Gestionale\Casse\CassaController;
use App\Http\Controllers\Gestionale\Dashboard\DashboardController;
use App\Http\Controllers\Gestionale\Esercizi\EsercizioController;
use App\Http\Controllers\Gestionale\Gestioni\GestioneController;
use App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController;
use App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController;
use App\Http\Controllers\Gestionale\Immobili\ImmobileController;
use App\Http\Controllers\Gestionale\Movimenti\IncassoRateController;
use App\Http\Controllers\Gestionale\Movimenti\MovimentiController;
use App\Http\Controllers\Gestionale\Movimenti\SituazioneDebitoriaController;
use App\Http\Controllers\Gestionale\Palazzine\PalazzinaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\AssociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\DissociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\FetchCapitoliContiController;
use App\Http\Controllers\Gestionale\PianiConti\PianoContiController;
use App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController;
use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;
use App\Http\Controllers\Gestionale\PianiRate\FetchCapitoliPerGestioneController;
use App\Http\Controllers\Gestionale\PianiRate\PianoRateController;
use App\Http\Controllers\Gestionale\PianiRate\PianoRateGenerationController;
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
    
    Route::get('/struttura', [StrutturaController::class, 'index'])
        ->name('struttura.index');

    Route::get('/fetch-tabelle', FetchTabelleController::class)
        ->name('fetch-tabelle');

    Route::get('/fetch-capitoli-conti', FetchCapitoliContiController::class)
        ->name('fetch-capitoli-conti');

    Route::get('fetch-capitoli-gestione', FetchCapitoliPerGestioneController::class)
        ->name('fetch-capitoli-gestione');
    
    Route::resource('palazzine', PalazzinaController::class)
        ->parameters(['palazzine' => 'palazzina']);
    
    Route::resource('scale', ScalaController::class)
        ->parameters(['scale' => 'scala']);
    
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
    
    // --- CASSE ---
    Route::resource('casse', CassaController::class)
        ->parameters(['casse' => 'cassa']);
    
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
    
    Route::resource('esercizi.piani-conti.conti', ContoController::class)
        ->parameters([
            'esercizi'    => 'esercizio',
            'piani-conti' => 'pianoConto',
            'conti'       => 'conto'
        ]); 
    
    Route::post('esercizi/{esercizio}/piani-conti/{pianoConto}/conti/{conto}/associa-tabella', AssociaTabellaController::class)
        ->name('esercizi.piani-conti.conti.associa-tabella');

    Route::delete('esercizi/{esercizio}/piani-conti/{pianoConto}/conti/{conto}/dissocia-tabella/{tabella}', DissociaTabellaController::class)
        ->name('esercizi.piani-conti.conti.dissocia-tabella');

    Route::resource('esercizi.piani-rate', PianoRateController::class)
        ->parameters([
            'esercizi'   => 'esercizio',
            'piani-rate' => 'pianoRate',
        ]);

    Route::put('/esercizi/{esercizio}/piani-rate/{pianoRate}/stato', [PianoRateController::class, 'updateStato'])
    ->name('piani-rate.update-stato');

    // Emissione Rate (Massiva)
    Route::post('/piani-rate/{pianoRate}/emetti', [EmissioneRateController::class, 'store'])
        ->name('piani-rate.emetti');

    // Annulla Emissione Singola Rata
    Route::delete('/piani-rate/{pianoRate}/rate/{rata}/annulla-emissione', [EmissioneRateController::class, 'destroy'])
        ->name('piani-rate.annulla-emissione');
    
    // Rotta per vedere l'estratto conto (accessibile dal piano rate)
    Route::get('/anagrafiche/{anagrafica}/estratto-conto', [EstrattoContoAnagraficaController::class, 'show'])
        ->name('anagrafiche.estratto-conto');
    
    Route::post('/esercizi/{esercizio}/piani-rate/{pianoRate}/regenerate', PianoRateGenerationController::class)
    ->name('esercizi.piani-rate.regenerate');
    
    Route::get('situazione-debitoria', SituazioneDebitoriaController::class)
        ->name('situazione-debitoria');
    
    Route::resource('movimenti-rate', IncassoRateController::class)
        ->parameters(['movimenti-rate' => 'scrittura']);
    
    Route::get('/movimenti', [MovimentiController::class, 'index'])
        ->name('movimenti.index');
});
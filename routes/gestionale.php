<?php

use App\Http\Controllers\Gestionale\Dashboard\DashboardController;
use App\Http\Controllers\Gestionale\Esercizi\EsercizioController;
use App\Http\Controllers\Gestionale\Gestioni\GestioneController;
use App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController;
use App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController;
use App\Http\Controllers\Gestionale\Immobili\ImmobileController;
use App\Http\Controllers\Gestionale\Palazzine\PalazzinaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\AssociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\DissociaTabellaController;
use App\Http\Controllers\Gestionale\PianiConti\Conti\FetchCapitoliContiController;
use App\Http\Controllers\Gestionale\PianiConti\PianoContiController;
use App\Http\Controllers\Gestionale\Scale\ScalaController;
use App\Http\Controllers\Gestionale\Struttura\StrutturaController;
use App\Http\Controllers\Gestionale\Tabelle\FetchTabelleController;
use App\Http\Controllers\Gestionale\Tabelle\Quote\TabellaQuotaController;
use App\Http\Controllers\Gestionale\Tabelle\TabellaController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use Illuminate\Support\Facades\Route;

// All gestionale routes with consistent middleware
Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([EnsureCondominioHasEsercizio::class])
    ->group(function () {
    
    Route::get('/', DashboardController::class)->name('index');
    
    Route::get('/struttura', [StrutturaController::class, 'index'])->name('struttura.index');

    Route::get('/fetch-tabelle', FetchTabelleController::class)->name('fetch-tabelle');

    Route::get('/fetch-capitoli-conti', FetchCapitoliContiController::class)->name('fetch-capitoli-conti');
    
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
    

});
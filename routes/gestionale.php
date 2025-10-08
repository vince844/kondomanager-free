<?php

use App\Http\Controllers\Gestionale\Dashboard\DashboardController;
use App\Http\Controllers\Gestionale\Esercizi\EsercizioController;
use App\Http\Controllers\Gestionale\Gestioni\GestioneController;
use App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController;
use App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController;
use App\Http\Controllers\Gestionale\Immobili\ImmobileController;
use App\Http\Controllers\Gestionale\Palazzine\PalazzinaController;
use App\Http\Controllers\Gestionale\Scale\ScalaController;
use App\Http\Controllers\Gestionale\Struttura\StrutturaController;
use App\Http\Controllers\Gestionale\Tabelle\Quote\TabellaQuotaController;
use App\Http\Controllers\Gestionale\Tabelle\TabellaController;
use Illuminate\Support\Facades\Route;

Route::get('/gestionale/{condominio}', DashboardController::class)
    ->name('gestionale.index'); 

Route::prefix('/gestionale/{condominio}')->name('gestionale.')->group(function () {

    Route::get('/struttura', [StrutturaController::class, 'index'])
        ->name('struttura.index');
    
    Route::resource('palazzine', PalazzinaController::class)->parameters([
        'palazzine' => 'palazzina'
    ]);

    Route::resource('scale', ScalaController::class)->parameters([
        'scale' => 'scala'
    ]);

    Route::resource('immobili', ImmobileController::class)->parameters([
        'immobili' => 'immobile'
    ]);

    Route::resource('immobili.anagrafiche', ImmobileAnagraficaController::class)
        ->parameters([
            'immobili'    => 'immobile',
            'anagrafiche' => 'anagrafica'
    ]);

    Route::resource('immobili.documenti', ImmobileDocumentoController::class)
        ->parameters([
            'documenti' =>'documento',
            'immobili'  => 'immobile',
    ]);

    Route::resource('tabelle', TabellaController::class)->parameters([
        'tabelle' => 'tabella'
    ]);

    Route::get('tabelle/{tabella}/quote', [TabellaQuotaController::class, 'index'])
        ->name('tabelle.quote.index');

    Route::put('tabelle/{tabella}/quote', [TabellaQuotaController::class, 'update'])
        ->name('tabelle.quote.update');

    Route::resource('esercizi', EsercizioController::class)->parameters([
        'esercizi' => 'esercizio'
    ]);

    Route::resource('esercizi.gestioni', GestioneController::class)
    ->parameters([
        'esercizi'    => 'esercizio',
        'gestioni' => 'gestione'
    ]);

/*     Route::resource('gestioni', GestioneController::class)->parameters([
        'gestioni' => 'gestione'
    ]); */

});
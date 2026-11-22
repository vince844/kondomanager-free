<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Http\Controllers\Documenti\VersioniDelDocumentoController;
use App\Http\Controllers\RegistroModifiche\ConservazioneController;
use App\Http\Controllers\RegistroModifiche\OscuramentoController;
use App\Http\Controllers\RegistroModifiche\SospensioneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Il registro delle modifiche: i casi speciali (1.12.0-beta.13, Coda 679)
|--------------------------------------------------------------------------
| Le versioni di prima dei documenti (§9), l'oscuramento dei dati di una persona (§10), la durata delle righe e la sospensione della cancellazione per
| condominio (§11, decisione 550). Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello.
*/

// Lo scaricamento di una versione di prima di un documento dell'archivio: con il permesso di vedere il documento (il controllo è nel controller).
Route::get('documenti/{documento}/versioni/{versione}/download', [VersioniDelDocumentoController::class, 'download'])
    ->name('documenti.versioni.download');
// Le stesse versioni dalle schede della persona e del fornitore (decisione 559), con le loro guardie.
Route::get('anagrafiche/{anagrafica}/documenti/{documento}/versioni/{versione}/download', [VersioniDelDocumentoController::class, 'dallaPersona'])
    ->name('anagrafiche.documenti.versioni.download');
Route::get('fornitori/{fornitore}/documenti/{documento}/versioni/{versione}/download', [VersioniDelDocumentoController::class, 'dalFornitore'])
    ->name('fornitori.documenti.versioni.download');
// E dalla scheda dell'unità, nel gestionale: l'unità, il documento e la versione cercati uno dentro l'altro (`scopeBindings`).
Route::prefix('gestionale/{condominio}')->name('gestionale.')->scopeBindings()->group(function () {
    Route::get('immobili/{immobile}/documenti/{documento}/versioni/{versione}/download', [VersioniDelDocumentoController::class, 'dallUnita'])
        ->name('immobili.documenti.versioni.download');
});

// L'oscuramento dei dati di una persona (§10): l'anteprima per la finestra e l'oscuramento, solo l'amministratore.
Route::middleware('role:'.Role::AMMINISTRATORE->value)->group(function () {
    Route::get('registro-modifiche/oscuramento/{soggetto_tipo}/{soggetto_id}', [OscuramentoController::class, 'anteprima'])
        ->whereIn('soggetto_tipo', ['anagrafica', 'fornitore'])->whereNumber('soggetto_id')
        ->name('registro-modifiche.oscuramento.anteprima');
    Route::post('registro-modifiche/oscuramento/{soggetto_tipo}/{soggetto_id}', [OscuramentoController::class, 'oscura'])
        ->whereIn('soggetto_tipo', ['anagrafica', 'fornitore'])->whereNumber('soggetto_id')
        ->name('registro-modifiche.oscuramento.oscura');
});

// Per quanto tempo si tengono le righe (§11): lo stato lo legge chi vede il registro (il controllo è nel controller), la durata la cambia l'amministratore.
Route::get('registro-modifiche/conservazione', [ConservazioneController::class, 'mostra'])
    ->name('registro-modifiche.conservazione.mostra');
Route::put('registro-modifiche/conservazione', [ConservazioneController::class, 'cambia'])
    ->middleware('role:'.Role::AMMINISTRATORE->value)
    ->name('registro-modifiche.conservazione.cambia');

// Le sospensioni dei condomini eliminati, dal registro dello studio (solo l'amministratore): l'elenco e la fine per numero, perché il condominio non c'è
// più e la rotta non lo può legare.
Route::middleware('role:'.Role::AMMINISTRATORE->value)->group(function () {
    Route::get('registro-modifiche/sospensioni/condomini-eliminati', [SospensioneController::class, 'eliminati'])
        ->name('registro-modifiche.sospensioni.eliminati');
    Route::delete('registro-modifiche/sospensioni/{condominio_id}', [SospensioneController::class, 'togliPerNumero'])
        ->whereNumber('condominio_id')
        ->name('registro-modifiche.sospensioni.togli');
});

// «Sospendi la cancellazione» per condominio (decisione 550): lo stato lo legge chi vede il registro, sospendere e togliere la sospensione sono
// dell'amministratore. Con il vincolo delle rotte del gestionale (`scopeBindings`), come ogni rotta sotto `gestionale/{condominio}`.
Route::prefix('gestionale/{condominio}/registro-modifiche/sospensione')
    ->name('gestionale.registro-modifiche.sospensione.')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', [SospensioneController::class, 'mostra'])->name('mostra');
        Route::post('/', [SospensioneController::class, 'sospendi'])->middleware('role:'.Role::AMMINISTRATORE->value)->name('sospendi');
        Route::delete('/', [SospensioneController::class, 'togli'])->middleware('role:'.Role::AMMINISTRATORE->value)->name('togli');
    });

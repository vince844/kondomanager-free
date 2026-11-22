<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\Sala\DelegheController;
use App\Http\Controllers\Assemblee\Sala\ImpostazioniDellaSalaController;
use App\Http\Controllers\Assemblee\Sala\SalaController;
use App\Http\Controllers\Assemblee\Sala\SenzaReteController;
use App\Http\Controllers\Assemblee\Sala\StampeDellaSalaController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Le assemblee: la sala, le deleghe ricevute, le teste del condominio
|--------------------------------------------------------------------------
| Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello. Il gruppo è quello del gestionale: gli
| stessi middleware e `scopeBindings()`. I permessi si controllano nei controller (`AccessoAlleAssemblee`): la sala si legge con «Vede le assemblee», i
| gesti e le deleghe con «Gestisce le assemblee». I gesti sono richieste JSON con il loro numero, non visite (la tenuta senza rete).
*/

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,
        EnsureCondominioHasPianoConti::class,
    ])
    ->scopeBindings()
    ->group(function () {
        Route::get('assemblee/{assemblea}/sala', [SalaController::class, 'show'])->whereNumber('assemblea')->name('assemblee.sala');
        Route::get('assemblee/{assemblea}/sala/stato', [SalaController::class, 'stato'])->whereNumber('assemblea')->name('assemblee.sala.stato');
        Route::post('assemblee/{assemblea}/sala/gesti', [SalaController::class, 'gesti'])->whereNumber('assemblea')->name('assemblee.sala.gesti');

        // Le due stampe della sala: il foglio (la sala sulla carta) e le presenze e i voti (il prospetto che il verbale richiama).
        Route::get('assemblee/{assemblea}/sala/foglio', [StampeDellaSalaController::class, 'foglio'])->whereNumber('assemblea')->name('assemblee.sala.foglio');
        Route::get('assemblee/{assemblea}/sala/prospetto', [StampeDellaSalaController::class, 'prospetto'])->whereNumber('assemblea')->name('assemblee.sala.prospetto');

        // L'operaio del browser che tiene la sala e il verbale per aprirli senza rete: vive nella cartella dell'assemblea.
        Route::get('assemblee/{assemblea}/sala/senza-rete', [SenzaReteController::class, 'operaio'])->whereNumber('assemblea')->name('assemblee.sala.senza_rete');

        // «Deleghe ricevute» nel passo «In sala», prima dell'assemblea e fino alla chiusura.
        Route::post('assemblee/{assemblea}/deleghe', [DelegheController::class, 'store'])->whereNumber('assemblea')->name('assemblee.deleghe.store');
        Route::post('assemblee/{assemblea}/deleghe/{delegaRicevuta}/revoca', [DelegheController::class, 'revoca'])->whereNumber(['assemblea', 'delegaRicevuta'])
            ->name('assemblee.deleghe.revoca');
        // La scansione di una delega ricevuta, da aprire nel browser: sta fra i documenti dell'assemblea, fuori dall'archivio e dal portale.
        Route::get('assemblee/{assemblea}/deleghe/{delegaRicevuta}/scansione', [DelegheController::class, 'scansione'])->whereNumber(['assemblea', 'delegaRicevuta'])
            ->name('assemblee.deleghe.scansione');

        // Le teste nel caso misto: una scelta del condominio.
        Route::patch('assemblee-teste', [ImpostazioniDellaSalaController::class, 'teste'])->name('assemblee.teste');
    });

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\Seguito\PianoStraordinarioController;
use App\Http\Controllers\Assemblee\Seguito\SeguitoController;
use App\Http\Controllers\Assemblee\Seguito\StatoDiEsecuzioneController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Le assemblee: il seguito delle delibere
|--------------------------------------------------------------------------
| Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello; gli stessi middleware e `scopeBindings()`
| delle altre rotte delle assemblee. I permessi si controllano nel controller (`AccessoAlleAssemblee`): tutto con «Gestisce le assemblee», il documento di
| «Segna eseguita» con «Vede». Ogni id è dell'assemblea e del condominio dell'indirizzo.
*/

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,
        EnsureCondominioHasPianoConti::class,
    ])
    ->scopeBindings()
    ->group(function () {
        // Il seguito delle delibere (decisioni 500 (3), 522, 523): la conferma di «Che cosa cambia nel gestionale», «Applica» di una riga tolta, le cose da
        // fare, la risposta a una respinta, i segni. Il riepilogo e il passo 6 arrivano con la pagina dell'assemblea (la prop `seguito`).
        Route::post('/assemblee/{assemblea}/seguito/conferma', [SeguitoController::class, 'conferma'])->whereNumber('assemblea')
            ->name('assemblee.seguito.conferma');
        Route::post('/assemblee/{assemblea}/seguito/applica', [SeguitoController::class, 'applica'])->whereNumber('assemblea')->name('assemblee.seguito.applica');
        Route::post('/assemblee/{assemblea}/delibere/{delibera}/cose', [SeguitoController::class, 'nuovaCosa'])->whereNumber(['assemblea', 'delibera'])
            ->name('assemblee.seguito.cose.store');
        $cosa = '/assemblee/{assemblea}/cose-da-fare/{cosa}';
        Route::post($cosa . '/eseguita', [SeguitoController::class, 'eseguita'])->whereNumber(['assemblea', 'cosa'])->name('assemblee.seguito.cose.eseguita');
        Route::post($cosa . '/non-serve', [SeguitoController::class, 'nonServe'])->whereNumber(['assemblea', 'cosa'])->name('assemblee.seguito.cose.non-serve');
        Route::post($cosa . '/ricordamelo', [SeguitoController::class, 'ricordamelo'])->whereNumber(['assemblea', 'cosa'])
            ->name('assemblee.seguito.cose.ricordamelo');
        Route::post($cosa . '/riproponi', [SeguitoController::class, 'riproponi'])->whereNumber(['assemblea', 'cosa'])->name('assemblee.seguito.cose.riproponi');
        Route::post($cosa . '/chiudi', [SeguitoController::class, 'chiudi'])->whereNumber(['assemblea', 'cosa'])->name('assemblee.seguito.cose.chiudi');
        Route::get($cosa . '/documento', [SeguitoController::class, 'documento'])->whereNumber(['assemblea', 'cosa'])->name('assemblee.seguito.cose.documento');
        Route::post('/assemblee/{assemblea}/delibere/{delibera}/annullata', [SeguitoController::class, 'annullata'])->whereNumber(['assemblea', 'delibera'])
            ->name('assemblee.delibere.annullata');
        Route::post('/assemblee/{assemblea}/delibere/{delibera}/revocata', [SeguitoController::class, 'revocata'])->whereNumber(['assemblea', 'delibera'])
            ->name('assemblee.delibere.revocata');
        Route::get('/assemblee/{assemblea}/delibere/{delibera}/revoche', [SeguitoController::class, 'revoche'])->whereNumber(['assemblea', 'delibera'])
            ->name('assemblee.delibere.revoche');
        // Gli esiti delle delibere sulle schede di arrivo (respinta, rinviata, i segni), per il componente che ogni scheda monta; con «Vede le assemblee».
        Route::get('/esiti-nelle-schede/{tipo}/{id}', \App\Http\Controllers\Assemblee\EsitiNelleSchedeController::class)->whereNumber('id')
            ->name('esiti-nelle-schede');
        // «Apri il piano», «Apri»: la pagina di una scheda del seguito dal suo tipo e dal suo id.
        Route::get('/schede/{tipo}/{id}', \App\Http\Controllers\Assemblee\ApriLaSchedaController::class)->whereNumber('id')->name('schede.apri');
        // «Crea il piano straordinario» dalla cosa da fare (decisione 622 (1)): la proposta della finestra, presa dalla delibera, e la finestra confermata (la
        // gestione, il suo piano dei conti e la voce, poi il nuovo piano compilato). La stampa «Lo stato di esecuzione delle delibere», per il fascicolo.
        Route::get($cosa . '/piano-straordinario', [PianoStraordinarioController::class, 'proposta'])->whereNumber(['assemblea', 'cosa'])
            ->name('assemblee.seguito.piano-straordinario.proposta');
        Route::post($cosa . '/piano-straordinario', [PianoStraordinarioController::class, 'prepara'])->whereNumber(['assemblea', 'cosa'])
            ->name('assemblee.seguito.piano-straordinario');
        Route::get('/assemblee/{assemblea}/stato-di-esecuzione', [StatoDiEsecuzioneController::class, 'stampa'])->whereNumber('assemblea')
            ->name('assemblee.seguito.stampa');
    });

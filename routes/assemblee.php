<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\AssembleeController;
use App\Http\Controllers\Assemblee\DeliberaDelPianoController;
use App\Http\Controllers\Assemblee\DelibereController;
use App\Http\Controllers\Assemblee\MemoController;
use App\Http\Controllers\Assemblee\PuntiController;
use App\Http\Controllers\Assemblee\RaccoltaController;
use App\Http\Controllers\Assemblee\RatificaController;
use App\Http\Controllers\Assemblee\RiunioneAManoController;
use App\Http\Controllers\Assemblee\TabellaDiProprietaController;
use App\Http\Controllers\Assemblee\TabellaDiUnGruppoController;
use App\Http\Controllers\Assemblee\TenutaFuoriController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Le assemblee: le pagine dell'assemblea, del memo, di «Scegli la delibera», della delibera a mano, della raccolta delle date e della tabella di proprietà (§3, P1–P12 del progetto della tappa 2)
|--------------------------------------------------------------------------
| Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello. Il gruppo è quello del gestionale
| (`routes/gestionale.php`): gli stessi middleware e `scopeBindings()`, così una rotta annidata nasce protetta (`ScopingDelleRotteAnnidateTest`). I
| permessi si controllano nei controller (`AccessoAlleAssemblee`), come il registro. I nomi: admin.gestionale.assemblee.*, admin.gestionale.delibere.*, admin.gestionale.memo.*, admin.gestionale.tabella-di-proprieta, admin.gestionale.tabella-di-un-gruppo, admin.gestionale.fatture.ratifica.
*/

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,
        EnsureCondominioHasPianoConti::class,
    ])
    ->scopeBindings()
    ->group(function () {
        // L'archivio, la preparazione e la pagina dell'assemblea (P1, P2, P6). Le date cambiano con `sedute`, una per una.
        Route::get('/assemblee', [AssembleeController::class, 'index'])->name('assemblee.index');
        Route::get('/assemblee/create', [AssembleeController::class, 'create'])->name('assemblee.create');
        Route::post('/assemblee', [AssembleeController::class, 'store'])->name('assemblee.store');
        Route::get('/assemblee/{assemblea}', [AssembleeController::class, 'show'])->whereNumber('assemblea')->name('assemblee.show');
        Route::patch('/assemblee/{assemblea}', [AssembleeController::class, 'update'])->whereNumber('assemblea')->name('assemblee.update');
        Route::patch('/assemblee/{assemblea}/sedute', [AssembleeController::class, 'sedute'])->whereNumber('assemblea')->name('assemblee.sedute');
        Route::post('/assemblee/{assemblea}/documenti-visti', [AssembleeController::class, 'documentiVisti'])->whereNumber('assemblea')->name('assemblee.documenti-visti');
        Route::delete('/assemblee/{assemblea}', [AssembleeController::class, 'destroy'])->whereNumber('assemblea')->name('assemblee.destroy');

        // «Tenuta fuori dal programma» (R16).
        Route::post('/assemblee/{assemblea}/tenuta-fuori', TenutaFuoriController::class)->whereNumber('assemblea')->name('assemblee.tenuta-fuori');

        // L'ordine del giorno: aggiungere, riscrivere, togliere, riordinare, le proposte; la delibera sul punto di un'assemblea tenuta fuori.
        Route::post('/assemblee/{assemblea}/punti', [PuntiController::class, 'store'])->whereNumber('assemblea')->name('assemblee.punti.store');
        Route::post('/assemblee/{assemblea}/punti/riordina', [PuntiController::class, 'riordina'])->whereNumber('assemblea')->name('assemblee.punti.riordina');
        Route::post('/assemblee/{assemblea}/punti/dalle-proposte', [PuntiController::class, 'dalleProposte'])->whereNumber('assemblea')
            ->name('assemblee.punti.dalle-proposte');
        Route::patch('/assemblee/{assemblea}/punti/{punto}', [PuntiController::class, 'update'])->whereNumber(['assemblea', 'punto'])
            ->name('assemblee.punti.update');
        Route::delete('/assemblee/{assemblea}/punti/{punto}', [PuntiController::class, 'destroy'])->whereNumber(['assemblea', 'punto'])
            ->name('assemblee.punti.destroy');
        Route::post('/assemblee/{assemblea}/punti/{punto}/delibera', [PuntiController::class, 'delibera'])->whereNumber(['assemblea', 'punto'])
            ->name('assemblee.punti.delibera');

        // La riunione registrata a mano: «Correggi la data» e il verbale sottoscritto (caricare, scaricare, togliere).
        Route::post('/assemblee/{assemblea}/correggi-data', [RiunioneAManoController::class, 'correggiData'])->whereNumber('assemblea')
            ->name('assemblee.correggi-data');
        Route::post('/assemblee/{assemblea}/verbale', [RiunioneAManoController::class, 'caricaVerbale'])->whereNumber('assemblea')
            ->name('assemblee.verbale.store');
        Route::get('/assemblee/{assemblea}/verbale', [RiunioneAManoController::class, 'scaricaVerbale'])->whereNumber('assemblea')
            ->name('assemblee.verbale.show');
        Route::delete('/assemblee/{assemblea}/verbale', [RiunioneAManoController::class, 'togliVerbale'])->whereNumber('assemblea')
            ->name('assemblee.verbale.destroy');

        // «Scegli la delibera» (un JSON: lo stesso componente in tredici posti), «Registra una delibera a mano», sposta ed elimina; la raccolta.
        Route::get('/delibere/scegli', [DelibereController::class, 'scegli'])->name('delibere.scegli');
        Route::get('/delibere/a-mano/destinazione', [DelibereController::class, 'destinazione'])->name('delibere.a-mano.destinazione');
        Route::post('/delibere/a-mano', [DelibereController::class, 'aMano'])->name('delibere.a-mano');
        Route::get('/delibere/raccolta', [RaccoltaController::class, 'index'])->name('delibere.raccolta');
        Route::post('/delibere/raccolta', [RaccoltaController::class, 'store'])->name('delibere.raccolta.store');
        Route::post('/delibere/{delibera}/sposta', [DelibereController::class, 'sposta'])->whereNumber('delibera')->name('delibere.sposta');
        Route::delete('/delibere/{delibera}', [DelibereController::class, 'destroy'])->whereNumber('delibera')->name('delibere.destroy');

        // Il memo «Da portare in assemblea» (un JSON: la finestra si apre da quindici posti).
        Route::get('/memo/proposta', [MemoController::class, 'proposta'])->name('memo.proposta');
        Route::post('/memo', [MemoController::class, 'store'])->name('memo.store');
        Route::patch('/memo/{memo}', [MemoController::class, 'update'])->whereNumber('memo')->name('memo.update');
        Route::post('/memo/{memo}/non-serve', [MemoController::class, 'nonServe'])->whereNumber('memo')->name('memo.non-serve');
        Route::post('/memo/{memo}/nell-ordine', [MemoController::class, 'nellOrdine'])->whereNumber('memo')->name('memo.nell-ordine');

        // La tabella di proprietà generale del condominio, di una palazzina, di una scala (P12).
        Route::post('/tabella-di-proprieta', TabellaDiProprietaController::class)->name('tabella-di-proprieta');
        // Il segno della tabella di un gruppo (Coda 734): sui suoi punti votano solo i condòmini delle sue unità.
        Route::post('/tabella-di-un-gruppo', TabellaDiUnGruppoController::class)->name('tabella-di-un-gruppo');

        // La ratifica dello sforo con la delibera (R12, decisione 561): il pagamento per l'urgenza resta `fatture.approva-sforo`.
        Route::post('/fatture/{fattura}/ratifica', RatificaController::class)->whereNumber('fattura')->name('fatture.ratifica');

        // La delibera del piano già emesso e il riferimento del piano d'urgenza (R1 (c)): il collegamento, la data del piano non cambia.
        Route::post('/esercizi/{esercizio}/piani-rate/{pianoRate}/delibera', DeliberaDelPianoController::class)->whereNumber(['esercizio', 'pianoRate'])
            ->name('piani-rate.delibera');
    });

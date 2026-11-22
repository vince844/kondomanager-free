<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\Verbale\RegistroDeiVerbaliController;
use App\Http\Controllers\Assemblee\Verbale\TrasmissioneController;
use App\Http\Controllers\Assemblee\Verbale\VerbaleController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Le assemblee: il verbale, la trasmissione e il registro dei verbali
|--------------------------------------------------------------------------
| Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello; gli stessi middleware e `scopeBindings()`
| della sala. I percorsi `assemblee/{assemblea}/verbale` sono della riunione registrata a mano: il verbale del programma sta in `il-verbale`. I permessi si
| controllano nei controller (`AccessoAlleAssemblee`): leggere con «Vede le assemblee», scrivere, generare, stampare la bozza e trasmettere con «Gestisce le
| assemblee», registrare, sostituire la copia, annullare e il registro di nomina e revoca con «Registra il verbale sottoscritto». I salvataggi del testo sono
| richieste JSON con il loro numero, come i gesti della sala.
*/

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,
        EnsureCondominioHasPianoConti::class,
    ])
    ->scopeBindings()
    ->group(function () {
        // La pagina «Il verbale» (con `?verbale=` per una riunione che non è l'ultima), la sua lettura periodica e i salvataggi del testo.
        Route::get('assemblee/{assemblea}/il-verbale', [VerbaleController::class, 'show'])->whereNumber('assemblea')->name('assemblee.verbale.pagina');
        Route::get('assemblee/{assemblea}/il-verbale/stato', [VerbaleController::class, 'stato'])->whereNumber('assemblea')->name('assemblee.verbale.stato');
        Route::post('assemblee/{assemblea}/il-verbale/gesti', [VerbaleController::class, 'gesti'])->whereNumber('assemblea')->name('assemblee.verbale.gesti');
        Route::post('assemblee/{assemblea}/il-verbale/genera', [VerbaleController::class, 'genera'])->whereNumber('assemblea')->name('assemblee.verbale.genera');

        $v = 'assemblee/{assemblea}/il-verbale/{verbaleDellAssemblea}';
        $numeri = ['assemblea', 'verbaleDellAssemblea'];
        Route::post($v . '/aggiorna-sala', [VerbaleController::class, 'aggiornaSala'])->whereNumber($numeri)->name('assemblee.verbale.aggiorna_sala');
        Route::get($v . '/versioni/{versione}', [VerbaleController::class, 'versione'])->whereNumber([...$numeri, 'versione'])->name('assemblee.verbale.versione');
        Route::post($v . '/versioni/{versione}/ripristina', [VerbaleController::class, 'ripristina'])->whereNumber([...$numeri, 'versione'])
            ->name('assemblee.verbale.ripristina');
        Route::post($v . '/versioni/{versione}/scegli', [VerbaleController::class, 'scegli'])->whereNumber([...$numeri, 'versione'])->name('assemblee.verbale.scegli');
        Route::post($v . '/scrivo', [VerbaleController::class, 'scrivo'])->whereNumber($numeri)->name('assemblee.verbale.scrivo');
        Route::post($v . '/chiedi', [VerbaleController::class, 'chiedi'])->whereNumber($numeri)->name('assemblee.verbale.chiedi');
        Route::post($v . '/rispondi', [VerbaleController::class, 'rispondi'])->whereNumber($numeri)->name('assemblee.verbale.rispondi');
        Route::post($v . '/bozza', [VerbaleController::class, 'bozza'])->whereNumber($numeri)->name('assemblee.verbale.bozza');
        Route::post($v . '/registra', [VerbaleController::class, 'registra'])->whereNumber($numeri)->name('assemblee.verbale.registra');
        Route::get($v . '/copia', [VerbaleController::class, 'scaricaLaCopia'])->whereNumber($numeri)->name('assemblee.verbale.copia.show');
        Route::post($v . '/copia', [VerbaleController::class, 'sostituisciLaCopia'])->whereNumber($numeri)->name('assemblee.verbale.copia');
        Route::post($v . '/annulla', [VerbaleController::class, 'annulla'])->whereNumber($numeri)->name('assemblee.verbale.annulla');
        Route::get($v . '/libro', [VerbaleController::class, 'paginaDelLibro'])->whereNumber($numeri)->name('assemblee.verbale.libro.pagina');
        Route::post($v . '/libro', [VerbaleController::class, 'libro'])->whereNumber($numeri)->name('assemblee.verbale.libro');

        // La trasmissione: chi lo riceve, le PEC, le lettere, la spedizione, la prova; la consegna e la ricevuta sono quelle degli invii dell'assemblea.
        Route::post($v . '/destinatari/togli', [TrasmissioneController::class, 'togli'])->whereNumber($numeri)->name('assemblee.verbale.destinatari.togli');
        Route::post($v . '/destinatari/aggiungi', [TrasmissioneController::class, 'aggiungi'])->whereNumber($numeri)->name('assemblee.verbale.destinatari.aggiungi');
        Route::post($v . '/pec', [TrasmissioneController::class, 'pec'])->whereNumber($numeri)->name('assemblee.verbale.pec');
        Route::post($v . '/lettere', [TrasmissioneController::class, 'lettere'])->whereNumber($numeri)->name('assemblee.verbale.lettere');
        Route::post($v . '/spedizione', [TrasmissioneController::class, 'spedizione'])->whereNumber($numeri)->name('assemblee.verbale.spedizione');
        Route::get($v . '/prova', [TrasmissioneController::class, 'prova'])->whereNumber($numeri)->name('assemblee.verbale.prova');
        Route::post('assemblee/{assemblea}/delibere/{delibera}/impugnata', [TrasmissioneController::class, 'impugnata'])->whereNumber(['assemblea', 'delibera'])
            ->name('assemblee.delibere.impugnata');

        // Il registro dei verbali (un registro per perimetro: `?registro=scala:5`) e quello di nomina e revoca.
        Route::get('assemblee-registro', [RegistroDeiVerbaliController::class, 'index'])->name('assemblee.registro');
        Route::post('assemblee-registro/partenza', [RegistroDeiVerbaliController::class, 'partenza'])->name('assemblee.registro.partenza');
        Route::get('assemblee-registro/stampa', [RegistroDeiVerbaliController::class, 'stampa'])->name('assemblee.registro.stampa');
        Route::post('assemblee-registro/libro', [RegistroDeiVerbaliController::class, 'libro'])->name('assemblee.registro.libro');
        Route::get('assemblee-registro/annotazione/{seduta}', [RegistroDeiVerbaliController::class, 'annotazione'])->whereNumber('seduta')
            ->name('assemblee.registro.annotazione');
        Route::post('assemblee-registro/nomine', [RegistroDeiVerbaliController::class, 'nomina'])->name('assemblee.registro.nomine.store');
        Route::patch('assemblee-registro/nomine/{rigaDiNomina}', [RegistroDeiVerbaliController::class, 'correggi'])->whereNumber('rigaDiNomina')
            ->name('assemblee.registro.nomine.update');
        Route::post('assemblee-registro/nomine/{rigaDiNomina}/conferma', [RegistroDeiVerbaliController::class, 'conferma'])->whereNumber('rigaDiNomina')
            ->name('assemblee.registro.nomine.conferma');
        Route::post('assemblee-registro/nomine/{rigaDiNomina}/annulla', [RegistroDeiVerbaliController::class, 'annulla'])->whereNumber('rigaDiNomina')
            ->name('assemblee.registro.nomine.annulla');
    });

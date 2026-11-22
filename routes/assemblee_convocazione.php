<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\Convocazione\ConvocazioneController;
use App\Http\Controllers\Assemblee\Convocazione\DocumentiDeiPuntiController;
use App\Http\Controllers\Assemblee\Convocazione\FascicoloController;
use App\Http\Controllers\Assemblee\Convocazione\InviiDellaConvocazioneController;
use App\Http\Middleware\EnsureCondominioHasEsercizio;
use App\Http\Middleware\EnsureCondominioHasPianoConti;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Le assemblee: la convocazione, il fascicolo e le stampe dell'assemblea (§3 P13–P16 e §4 del progetto della tappa 2)
|--------------------------------------------------------------------------
| Caricato da `routes/admin.php` dentro il suo gruppo: prefisso `admin/`, nomi `admin.`, l'accesso al pannello. Il gruppo è quello del gestionale
| (`routes/gestionale.php`): gli stessi middleware e `scopeBindings()`, così una rotta annidata nasce protetta (`ScopingDelleRotteAnnidateTest`). I
| permessi si controllano nei controller (`AccessoAlleAssemblee`), come il registro. I nomi: quelli del §3 P13 del progetto della tappa 2.
*/

Route::prefix('/gestionale/{condominio}')
    ->name('gestionale.')
    ->middleware([
        EnsureCondominioHasEsercizio::class,
        EnsureCondominioHasPianoConti::class,
    ])
    ->scopeBindings()
    ->group(function () {
        // Il passo «Convocazione» (P13). Le stampe con un POST: scrivono il registro degli invii (come `routes/gestionale.php` per le ricevute e le lettere).
        Route::post('assemblee/{assemblea}/convocazione/anteprima', [ConvocazioneController::class, 'anteprima'])->name('assemblee.convocazione.anteprima');
        Route::post('assemblee/{assemblea}/convocazione/lettere', [ConvocazioneController::class, 'lettere'])->name('assemblee.convocazione.lettere');
        Route::post('assemblee/{assemblea}/convocazione/pec', [ConvocazioneController::class, 'pec'])->name('assemblee.convocazione.pec');
        Route::post('assemblee/{assemblea}/convocazione/spedizione', [ConvocazioneController::class, 'spedizione'])->name('assemblee.convocazione.spedizione');
        Route::patch('assemblee/{assemblea}/convocazione/mezzo', [ConvocazioneController::class, 'mezzo'])->name('assemblee.convocazione.mezzo');
        Route::post('assemblee/{assemblea}/convocazione/prova', [ConvocazioneController::class, 'prova'])->name('assemblee.convocazione.prova');
        Route::post('assemblee/{assemblea}/convocazione/foglio-a-mano', [ConvocazioneController::class, 'foglio'])->name('assemblee.convocazione.foglio');
        Route::post('assemblee/{assemblea}/convocazione/foglio-a-mano/firmato', [ConvocazioneController::class, 'foglioFirmato'])
            ->name('assemblee.convocazione.foglio-firmato');
        Route::post('assemblee/{assemblea}/convocazione/bacheca', [ConvocazioneController::class, 'bacheca'])->name('assemblee.convocazione.bacheca');
        Route::patch('assemblee/{assemblea}/convocazione/raccomandate-entro', [ConvocazioneController::class, 'raccomandateEntro'])
            ->name('assemblee.convocazione.raccomandate-entro');
        Route::post('assemblee/{assemblea}/riconvoca', [ConvocazioneController::class, 'riconvoca'])->name('assemblee.riconvoca');

        // Un invio della convocazione: la consegna, la prova, la ricevuta PEC letta dal file, il PDF conservato.
        Route::post('assemblee/{assemblea}/invii/{invio}/consegna', [InviiDellaConvocazioneController::class, 'consegna'])->name('assemblee.invii.consegna');
        Route::post('assemblee/{assemblea}/invii/{invio}/prova', [InviiDellaConvocazioneController::class, 'prova'])->name('assemblee.invii.prova');
        Route::post('assemblee/{assemblea}/invii/{invio}/ricevuta', [InviiDellaConvocazioneController::class, 'ricevuta'])->name('assemblee.invii.ricevuta');
        Route::get('assemblee/{assemblea}/invii/{invio}/prove/{documento}', [InviiDellaConvocazioneController::class, 'scaricaProva'])
            ->name('assemblee.invii.prove.scarica');
        Route::get('assemblee/{assemblea}/invii/{invio}/pdf', [InviiDellaConvocazioneController::class, 'pdf'])->name('assemblee.invii.pdf');

        // Il passo «Documenti», con il fascicolo (P14).
        Route::post('assemblee/{assemblea}/fascicolo/preventivo', [FascicoloController::class, 'fermaIlPreventivo'])->name('assemblee.fascicolo.preventivo');
        Route::post('assemblee/{assemblea}/fascicolo/componi', [FascicoloController::class, 'componi'])->name('assemblee.fascicolo.componi');
        Route::post('assemblee/{assemblea}/fascicolo/per-persona', [FascicoloController::class, 'perPersona'])->name('assemblee.fascicolo.per-persona');
        // I documenti di un punto: la finestra «Aggiungi un documento» (JSON), «Aggiungi» e «Togli». Il documento è un id: lo rilegge il servizio.
        Route::get('assemblee/{assemblea}/fascicolo/punti/{punto}/documenti', [DocumentiDeiPuntiController::class, 'index'])->whereNumber(['assemblea', 'punto'])
            ->name('assemblee.fascicolo.documenti');
        Route::post('assemblee/{assemblea}/fascicolo/punti/{punto}/documenti', [DocumentiDeiPuntiController::class, 'store'])->whereNumber(['assemblea', 'punto'])
            ->name('assemblee.fascicolo.documenti.store');
        Route::delete('assemblee/{assemblea}/fascicolo/punti/{punto}/documenti/{documento}', [DocumentiDeiPuntiController::class, 'destroy'])
            ->whereNumber(['assemblea', 'punto', 'documento'])->name('assemblee.fascicolo.documenti.destroy');
    });

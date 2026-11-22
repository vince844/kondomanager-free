<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, art. 1117-ter c.c., legge §2): la modifica della destinazione d'uso dalla pagina.
 *
 * - La finestra del punto scrive le parti comuni che cambiano e la nuova destinazione (`assemblee_punti.dati`), senza togliere gli altri dati del punto; la
 *   pagina dell'assemblea scrive le due date dell'affissione.
 * - L'affissione conta i giorni prima della prima convocazione: trenta consecutivi prima di quel giorno.
 * - Solo raccomandata e PEC per chi riceve il punto della destinazione d'uso: il conduttore convocato per un altro punto non blocca la spedizione.
 * - «Registra la spedizione» con il canale dell'avviso: una raccomandata non diventa un fax.
 *
 * Che cosa non copre: se l'affissione debba durare fino al giorno dell'assemblea (domanda nel rapporto: il testo dice solo «trenta giorni consecutivi»).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('dalle rotte si scrivono le parti comuni, la nuova destinazione e l\'affissione; il conduttore di un altro punto non blocca', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), [
        'testo' => 'Il locale delle biciclette diventa deposito dei passeggini', 'materia' => 'destinazione_uso',
        'dati' => ['parti_comuni' => 'il locale delle biciclette', 'nuova_destinazione' => 'deposito dei passeggini'],
    ])->assertSessionHasNoErrors();
    $this->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['affissione_dal' => '2026-11-10', 'affissione_al' => '2026-12-14'])
        ->assertSessionHasNoErrors();
    $p = PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->where('materia', 'destinazione_uso')->first();

    expect($p->dati)->toBe(['parti_comuni' => 'il locale delle biciclette', 'nuova_destinazione' => 'deposito dei passeggini'])
        ->and($a->refresh()->affissione_dal->format('Y-m-d'))->toBe('2026-11-10')
        ->and($a->affissione_al->format('Y-m-d'))->toBe('2026-12-14');

    // Ferri Luca è convocato per il solo punto 5: non riceve il punto della destinazione d'uso, e la consegna a mano per lui vale.
    $r = $this->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf']);
    $r->assertStatus(422);
    expect($r->json('errors.convocazione.0'))->toBe('La spedizione non parte: con un punto sulla destinazione d\'uso valgono solo la raccomandata e la PEC, e '
        . '2 persone sono senza (Bianchi Anna e Russo Anna).');
});

it('riscrivere il punto della destinazione d\'uso dalla rotta non toglie gli altri dati del punto', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s, [], [
        ['testo' => 'Il locale delle biciclette diventa deposito dei passeggini', 'materia' => 'destinazione_uso'],
    ]);
    $p = $a->punti()->first();
    $p->forceFill(['dati' => ['motivazione' => 'Serve spazio', 'motivazione_di' => 'sforo']])->save();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $p]), [
        'dati' => ['parti_comuni' => 'il locale delle biciclette', 'nuova_destinazione' => 'deposito dei passeggini'],
    ])->assertSessionHasNoErrors();

    expect($p->refresh()->dati)->toBe(['motivazione' => 'Serve spazio', 'motivazione_di' => 'sforo', 'parti_comuni' => 'il locale delle biciclette',
        'nuova_destinazione' => 'deposito dei passeggini']);
});

it('l\'affissione conta i giorni prima della prima convocazione: dal 10/12 al 10/01 non bastano, dal 01/11 al 30/11 sì', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s, [], [
        ['testo' => 'Il locale delle biciclette diventa deposito dei passeggini', 'materia' => 'destinazione_uso',
            'dati' => ['parti_comuni' => 'il locale delle biciclette', 'nuova_destinazione' => 'deposito dei passeggini']],
    ]);
    $a->punti()->first()->forceFill(['dati' => ['parti_comuni' => 'il locale delle biciclette', 'nuova_destinazione' => 'deposito dei passeggini']])->save();
    $termini = app(TerminiDellaConvocazione::class);

    $a->forceFill(['affissione_dal' => '2026-12-10', 'affissione_al' => '2027-01-10'])->save();
    expect($termini->perLaScheda($a->refresh(), [], false)['destinazione_uso']['mancano'])
        ->toBe(['un\'affissione di almeno trenta giorni consecutivi prima della prima convocazione del 14/12: prima di quel giorno sono 4']);

    $a->forceFill(['affissione_dal' => '2026-11-01', 'affissione_al' => '2026-11-30'])->save();
    expect($termini->perLaScheda($a->refresh(), [], false)['destinazione_uso']['mancano'])->toBe([]);
});

it('«Registra la spedizione» vuole il canale dell\'avviso: una raccomandata stampata non si registra come fax', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $marco = ConvocazioneDiViaRoma12::invio($a, $s, 'marco');

    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$marco->id], 'canale' => 'fax', 'data' => '2026-11-26'])
        ->assertSessionHasErrors(['invii' => 'L\'avviso di Russo Marco è per raccomandata: registralo come raccomandata.']);
    expect($marco->refresh()->stato)->toBe('stampata');
});

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il riallineo degli interessi registrati quando la riga non ha giorni nuovi. Con un accordo di rientro in regola la proposta lascia fuori i giorni
 * dell'accordo: registrati i giorni prima dell'accordo (decisione 438), la riga non ha più niente da proporre finché l'accordo regge. Se poi un versamento
 * registrato dopo, con una data di prima, cambia quei giorni già chiesti, la riga resta nella proposta a zero, senza casella, con la frase del riallineo:
 * sono interessi chiesti in più, e l'amministratore deve poterlo leggere.
 *
 * Tasso legale 2026 1,60 %; in centesimi capitale × 16.000 × giorni / 365.000.000; un versamento conta dal giorno dopo. Il caso: Neri, rata 1 di € 300,00
 * scaduta il 31/03/2026, € 100,00 versati il 30/04; l'accordo del 15/07 su € 200,00, in regola.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('i giorni prima dell\'accordo registrati e poi cambiati da un versamento con una data di prima: la riga a zero resta, con la frase del riallineo, e non si registra', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '200,00']],
        aclCalendario(['100,00', '100,00'], ['2026-07-31', '2026-08-31']))->assertCreated();

    // Il 20/07 si registrano i giorni prima dell'accordo: 39,452055 (01/04–30/04) + 65,753425 (01/05–14/07) = 105,205480 → € 1,05.
    $this->travelTo('2026-07-20 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'], '2026-07-20')->json())->assertCreated();

    // Il 25/07 si registra un versamento di € 50,00 del 30/06. Quei giorni con il giornale di oggi: 39,452055 + 20.000 × 61 giorni (01/05–30/06) 53,479452
    // + 15.000 × 14 giorni (01/07–14/07) 9,205479 = 102,136986 → € 1,02.
    $this->travelTo('2026-07-25 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 5000], '2026-06-30');

    $proposta = accProposta($this, $s, ['Neri'], '2026-07-25')->assertOk()->json();
    $neri = accDi($proposta, $s, 'Neri');
    $riga = collect($neri['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));
    expect($riga)->not->toBeNull()
        ->and([$riga['interessi'], $riga['fermo'], $neri['totale'], $proposta['totale']])->toBe([0, null, 0, 0])
        ->and($riga['riallineo'])->toBe('Gli interessi registrati al 20/07/2026 erano € 1,05; con il giornale di oggi quei giorni darebbero € 1,02: per cambiarli, annulla '
            . 'l\'addebito e rifai la proposta.');

    // La riga a zero non si registra: chi la manda trova la frase di sempre, e niente si scrive.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), [
        'alla' => '2026-07-25', 'righe' => [$riga['chiave']], 'atteso' => 0, 'voci' => [$riga['gestione_id'] => $s['interessi']->id],
    ])->assertStatus(422);
});

it('senza un riallineo, una riga a zero non entra nella proposta', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '200,00']],
        aclCalendario(['100,00', '100,00'], ['2026-07-31', '2026-08-31']))->assertCreated();
    $this->travelTo('2026-07-20 10:00:00');
    accRegistra($this, $s, accProposta($this, $s, ['Neri'], '2026-07-20')->json())->assertCreated();

    $this->travelTo('2026-07-25 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'], '2026-07-25')->assertOk()->json(), $s, 'Neri');
    expect(collect($neri['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1)))->toBeNull();
});

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un accordo di rientro su una parte della rata (decisione 450). Di serie l'accordo protegge solo il debito che copre (decisione 389 (2)): sulla parte
 * della rata fuori dall'accordo gli interessi continuano nella proposta. L'amministratore può scegliere, accordo per accordo, nella finestra dell'accordo,
 * che restino sospesi finché l'accordo regge: allora dal giorno dell'accordo resta fuori tutta la rata, come prima. Gli accordi registrati prima prendono
 * il di serie.
 *
 * Il caso: Neri, rata 1 di € 300,00 scaduta il 31/03/2026 (`AccessoriScenario.php`), € 100,00 versati il 30/04; accordo del 15/07/2026 su € 150,00 dei
 * € 200,00 che restano, calendario € 75,00 il 31/07 e € 75,00 il 31/08. Fuori dall'accordo restano € 50,00. Tasso legale 2026: 1,60 %.
 *
 * Le cifre, rifatte a mano (in centesimi: capitale × 16.000 × giorni / (1.000.000 × 365); un versamento conta dal giorno dopo):
 * - i giorni prima dell'accordo: 30.000 × 30 giorni (dal 01/04 al 30/04) = 39,452055 + 20.000 × 75 giorni (dal 01/05 al 14/07) = 65,753425;
 * - con gli interessi che continuano, al 20/07: 5.000 × 16.000 × 6 / 365.000.000 = 1,315068 (dal 15/07 al 20/07, sui € 50,00 fuori dall'accordo);
 *   in tutto 106,520548 → € 1,07. Con gli interessi sospesi: 105,205480 → € 1,05.
 */

use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Il caso fino all'accordo del 15/07 su € 150,00 dei € 200,00, con la scelta data (null: la finestra non la manda). [scenario, accordo, quota della rata 1] */
function ipfNeri($test, ?string $parteFuori): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $test->travelTo('2026-07-15 10:00:00');
    $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), array_filter([
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '150,00']],
        'scadenze' => aclCalendario(['75,00', '75,00'], ['2026-07-31', '2026-08-31']), 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
        'interessi_parte_fuori' => $parteFuori,
    ], fn ($v) => $v !== null))->assertCreated();

    return [$s, AccordoRientro::latest('id')->firstOrFail(), lcdQuota($s, 'Neri', 1)];
}

/** La riga della rata 1 di Neri alla data: [interessi, tratti come dal-al-capitale-causa, al, accordo_dal, in_accordo della persona]. */
function ipfRata1($test, array $s, string $alla): array
{
    $neri = accDi(accProposta($test, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri');
    $riga = collect($neri['righe'])->firstWhere('rata_id', lcdRata($s, 1));

    return [$riga['interessi'], array_map(fn ($t) => [$t['dal'], $t['al'], $t['capitale'], $t['causa']], $riga['tratti']), $riga['al'], $riga['accordo_dal'], $neri['in_accordo']];
}

it('di serie, sulla parte della rata fuori dall\'accordo gli interessi continuano: € 1,07 al 20/07, con il tratto dei € 50,00 dal giorno dell\'accordo', function () {
    [$s, $accordo] = ipfNeri($this, null);
    expect($accordo->interessi_parte_fuori)->toBe('continuano');

    $this->travelTo('2026-07-20 10:00:00');
    expect(ipfRata1($this, $s, '2026-07-20'))->toBe([107, [
        ['2026-04-01', '2026-04-30', 30000, 'scadenza'],
        ['2026-05-01', '2026-07-14', 20000, 'versamento'],
        ['2026-07-15', '2026-07-20', 5000, 'accordo'],
    ], '2026-07-20', '2026-07-15', 15000]);
});

it('con «restano sospesi finché l\'accordo regge», dal giorno dell\'accordo resta fuori tutta la rata: € 1,05 al 20/07', function () {
    [$s, $accordo] = ipfNeri($this, 'sospesi');
    expect($accordo->interessi_parte_fuori)->toBe('sospesi');

    $this->travelTo('2026-07-20 10:00:00');
    expect(ipfRata1($this, $s, '2026-07-20'))->toBe([105, [
        ['2026-04-01', '2026-04-30', 30000, 'scadenza'],
        ['2026-05-01', '2026-07-14', 20000, 'versamento'],
    ], '2026-07-14', '2026-07-15', 15000]);
});

it('la parte fuori dall\'accordo segue la regola dell\'incasso: un versamento senza la scelta la abbassa, uno «dell\'accordo» no', function () {
    // Al 31/07, con un versamento di € 30,00 il 20/07.
    // Senza la scelta va prima alla parte fuori: dal 21/07 restano fuori € 20,00. 39,452055 + 65,753425 + 5.000 × 6 giorni (dal 15/07 al 20/07) 1,315068
    // + 2.000 × 16.000 × 11 / 365.000.000 = 0,964384 (dal 21/07 al 31/07) = 107,484932 → € 1,07.
    [$s, , $q1] = ipfNeri($this, null);
    aclVersa($this, $s, [$q1 => 3000], '2026-07-20', false);
    $this->travelTo('2026-07-31 10:00:00');
    expect(array_slice(ipfRata1($this, $s, '2026-07-31')[1], 2))->toBe([['2026-07-15', '2026-07-20', 5000, 'accordo'], ['2026-07-21', '2026-07-31', 2000, 'versamento']])
        ->and(ipfRata1($this, $s, '2026-07-31')[0])->toBe(107);

    // Con «Versamento dell'accordo» va prima alla parte nell'accordo: fuori restano € 50,00. 39,452055 + 65,753425 + 5.000 × 16.000 × 17 / 365.000.000
    // = 3,726027 (dal 15/07 al 31/07) = 108,931507 → € 1,09.
    [$s2, , $q2] = ipfNeri($this, null);
    aclVersa($this, $s2, [$q2 => 3000], '2026-07-20', true);
    $this->travelTo('2026-07-31 10:00:00');
    expect(array_slice(ipfRata1($this, $s2, '2026-07-31')[1], 2))->toBe([['2026-07-15', '2026-07-31', 5000, 'accordo']])
        ->and(ipfRata1($this, $s2, '2026-07-31')[0])->toBe(109);
});

it('la scelta sulla parte fuori dall\'accordo si salva con l\'accordo e la finestra la rilegge; un valore che non c\'è non si registra', function () {
    [$s, $accordo] = ipfNeri($this, 'sospesi');
    $letti = $this->actingAs($this->user)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $s['c']->id]) . '?anagrafica_id=' . $s['persone']['Neri']->id)
        ->assertOk()->json('accordi');
    expect(collect($letti)->firstWhere('id', $accordo->id)['interessi_parte_fuori'])->toBe('sospesi')
        ->and(\Illuminate\Support\Facades\DB::table('accordi_rientro')->where('id', $accordo->id)->value('interessi_parte_fuori'))->toBe('sospesi');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 2), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '10,00']],
        'scadenze' => aclCalendario(['10,00'], ['2026-07-31']), 'interessi_parte_fuori' => 'forse',
    ])->assertStatus(422)->assertJsonValidationErrors(['interessi_parte_fuori']);
});

it('Coda 678 (decisione 485): con «restano sospesi» la proposta dice quanto resta fuori per intero (€ 200,00, dei quali € 150,00 nell\'accordo); di serie, o con l\'accordo che copre tutta la rata, niente', function () {
    // Non copre: le cifre degli interessi (provate sopra, invariate) né la frase a video (la prova vitest della proposta).
    [$s] = ipfNeri($this, 'sospesi');
    $this->travelTo('2026-07-20 10:00:00');
    $neri = accDi(accProposta($this, $s, ['Neri'], '2026-07-20')->assertOk()->json(), $s, 'Neri');
    expect([$neri['in_accordo'], $neri['fuori_per_intero'], $neri['di_cui_nell_accordo']])->toBe([15000, 20000, 15000]);

    [$s2] = ipfNeri($this, null);
    $this->travelTo('2026-07-20 10:00:00');
    $neri2 = accDi(accProposta($this, $s2, ['Neri'], '2026-07-20')->assertOk()->json(), $s2, 'Neri');
    expect([$neri2['in_accordo'], $neri2['fuori_per_intero'], $neri2['di_cui_nell_accordo']])->toBe([15000, 0, 0]);
});

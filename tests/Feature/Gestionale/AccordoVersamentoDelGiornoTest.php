<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un versamento dello stesso giorno dell'accordo di rientro, registrato prima dell'accordo. Il 15/07 Neri porta € 60,00 al momento di firmare:
 * l'amministratore registra prima il versamento (data 15/07), poi l'accordo (data 15/07). La finestra propone il residuo del 15/07, che il versamento
 * ha già abbassato (€ 140,00: la lettura comune legge fino alla fine del giorno), e il residuo del giorno dell'accordo è € 140,00. Quel versamento non
 * è andato all'accordo: è già fuori dal residuo. Un versamento dello stesso giorno registrato dopo l'accordo, invece, è del giorno dell'accordo e conta.
 *
 * Tasso legale 2026 1,60 %; in centesimi capitale × 16.000 × giorni / 365.000.000; un versamento conta dal giorno dopo. Il caso di partenza: Neri, rata 1
 * di € 300,00 scaduta il 31/03/2026, € 100,00 versati il 30/04 (`AccessoriScenario.php`).
 */

use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'accordo del 15/07, registrato alle 12:00, sulla rata 1 di Neri per l'importo dato, con il calendario dato. */
function avgAccordo($test, array $s, string $importo, array $calendario, ?string $parteFuori = null): AccordoRientro
{
    $test->travelTo('2026-07-15 12:00:00');
    $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), array_filter([
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => $importo]],
        'scadenze' => aclCalendario($calendario, ['2026-07-31', '2026-08-31']), 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
        'interessi_parte_fuori' => $parteFuori,
    ], fn ($v) => $v !== null))->assertCreated();

    return AccordoRientro::latest('id')->firstOrFail();
}

/** Il caso di partenza: € 100,00 il 30/04; poi, il 15/07 alle 09:00, il versamento dato registrato prima dell'accordo. */
function avgPrimaDellAccordo($test, int $centesimi, int $parteInPiu = 0): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $test->travelTo('2026-07-15 09:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => $centesimi], '2026-07-15', $parteInPiu);

    return $s;
}

/** La riga della rata 1 di Neri nella proposta alla data: [interessi, tratti dal-al-capitale]. */
function avgRiga($test, array $s, string $alla): array
{
    $neri = accDi(accProposta($test, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri');
    $r = collect($neri['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));

    return [$r['interessi'], array_map(fn ($t) => [$t['dal'], $t['al'], $t['capitale']], $r['tratti'])];
}

it('un versamento del giorno dell\'accordo registrato prima dell\'accordo non è andato all\'accordo: riconosciuti € 140,00, oggi € 140,00, il calendario a zero, le lettere lasciano fuori € 140,00', function () {
    $s = avgPrimaDellAccordo($this, 6000);
    $accordo = avgAccordo($this, $s, '140,00', ['70,00', '70,00']);
    expect((int) $accordo->rate->first()->residuo_iniziale)->toBe(14000);

    $this->travelTo('2026-07-20 10:00:00');
    $risposta = aclLetto($this, $s);
    expect([$risposta['accordi'][0]['riconosciuti'], $risposta['accordi'][0]['oggi']])->toBe([14000, 14000])
        ->and(aclRate($risposta)[0][1])->toBe(0)
        ->and(aclRate($risposta)[0][3])->toBeFalse();
    $lettura = lcdLeggi($s, 'Neri', '2026-07-20')['rate'];
    expect(app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->fuoriDalleLettere((int) $s['c']->id, (int) $s['persone']['Neri']->id, $lettura, '2026-07-20'))
        ->toBe([lcdRata($s, 1) . '|' . $s['unita']['U1']->id => 14000]);
});

it('con l\'ordine inverso (l\'accordo alle 12:00, il versamento alle 13:00 dello stesso giorno) il versamento è dell\'accordo: le cifre di sempre', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $accordo = avgAccordo($this, $s, '140,00', ['70,00', '70,00']);
    expect((int) $accordo->rate->first()->residuo_iniziale)->toBe(20000);
    // L'accordo copre € 140,00 dei € 200,00: fuori € 60,00. Il versamento di € 60,00, senza la scelta, va alla parte fuori.
    $this->travelTo('2026-07-15 13:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 6000], '2026-07-15');

    $this->travelTo('2026-07-20 10:00:00');
    $risposta = aclLetto($this, $s);
    expect([$risposta['accordi'][0]['riconosciuti'], $risposta['accordi'][0]['oggi']])->toBe([14000, 14000])
        ->and(aclRate($risposta)[0][1])->toBe(0);

    // Con la scelta «Versamento dell'accordo» lo stesso versamento registrato dopo l'accordo va all'accordo: oggi € 80,00.
    aclVersa($this, $s, [lcdQuota($s, 'Neri', 1) => 6000], '2026-07-15');
    $this->travelTo('2026-07-20 10:00:00');
    expect(aclLetto($this, $s)['accordi'][0]['oggi'])->toBe(8000);
});

it('con la parte fuori che continua: accordo su € 100,00 dei € 140,00 dopo il versamento dello stesso giorno, gli interessi sui € 40,00 fuori (€ 1,14)', function () {
    $s = avgPrimaDellAccordo($this, 6000);
    avgAccordo($this, $s, '100,00', ['50,00', '50,00']);

    // Al 31/08: 39,452055 (01/04–30/04, € 300,00) + 65,753425 (01/05–14/07, € 200,00) + 10.000 × 1 giorno (il 15/07: il versamento conta dal 16/07,
    // € 200,00 di cui € 100,00 nell'accordo) 0,438356 + 4.000 × 47 giorni (16/07–31/08: € 140,00 di cui € 100,00 nell'accordo) 8,241096 = 113,884932
    // → € 1,14.
    $this->travelTo('2026-08-31 10:00:00');
    [$interessi, $tratti] = avgRiga($this, $s, '2026-08-31');
    expect($interessi)->toBe(114)
        ->and(array_slice($tratti, 2))->toBe([['2026-07-15', '2026-07-15', 10000], ['2026-07-16', '2026-08-31', 4000]]);
});

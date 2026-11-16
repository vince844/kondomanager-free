<?php

/**
 * Gli aiuti delle prove dell'accordo di rientro con il calendario, sul caso di Neri (`AccessoriScenario.php`, Via Verdi): la rata 1 di € 300,00 scaduta il
 * 31/03/2026, € 100,00 versati il 30/04; il 29/06 gli interessi di mora al 29/06 (€ 300,00 × 1,60 % × 30/365 + € 200,00 × 1,60 % × 60/365 =
 * 0,920548 → € 0,92) e le spese di sollecito di € 25,00 (senza fattura, sulla voce «Spese postali», base la delibera del 14/03/2026). L'accordo del
 * 15/07/2026 copre € 200,00 + € 0,92 + € 25,00 = € 225,92, in tre rate: 75,31 il 31/07, 75,31 il 31/08, 75,30 il 30/09; la regola di decadenza «2 rate
 * non versate, anche non consecutive» e «15 giorni dopo una rata non versata».
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Gestionale\AccordoRientro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

/**
 * Neri con il debito del caso al 29/06: [scenario, rata degli interessi, rata del sollecito] (id delle rate). Con `rata2` la rata 2 (30/06) è emessa il
 * 15/01 come la rata 1, e resta fuori dall'accordo: la rata corrente.
 */
function aclNeri($test, bool $rata2 = false): array
{
    $s = accViaVerdi($test);
    if ($rata2) {
        lcdEmetti($test, $s, [2], '2026-01-15');
    }
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $test->travelTo('2026-06-29 10:00:00');
    $interessi = (int) accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    $sollecito = (int) adbRegistra($test, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-06-29',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');

    return [$s, $interessi, $sollecito];
}

/** Le rate coperte del caso, come le manda la finestra: la rata 1 (€ 200,00), gli interessi (€ 0,92), il sollecito (€ 25,00), tutte su U1. */
function aclCoperte(array $s, int $interessi, int $sollecito, array $importi = ['200,00', '0,92', '25,00']): array
{
    $u1 = (int) $s['unita']['U1']->id;

    return [
        ['rata_id' => lcdRata($s, 1), 'immobile_id' => $u1, 'importo' => $importi[0]],
        ['rata_id' => $interessi, 'immobile_id' => $u1, 'importo' => $importi[1]],
        ['rata_id' => $sollecito, 'immobile_id' => $u1, 'importo' => $importi[2]],
    ];
}

/** Il calendario del caso, come lo manda la finestra (euro come li scrive chi lavora). */
function aclCalendario(array $importi = ['75,31', '75,31', '75,30'], array $date = ['2026-07-31', '2026-08-31', '2026-09-30']): array
{
    return array_map(fn ($d, $i) => ['data' => $d, 'importo' => $i], $date, $importi);
}

/** Registra l'accordo dalla rotta, il giorno dato: le rate coperte, il calendario e la regola. */
function aclRegistra($test, array $s, array $coperte, array $calendario, string $data = '2026-07-15', ?int $nRate = 2, ?int $giorni = 15, string $chi = 'Neri')
{
    $test->travelTo($data . ' 10:00:00');

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), array_filter([
        'anagrafica_id' => $s['persone'][$chi]->id, 'data_accordo' => $data, 'autorizzato_assemblea' => '0', 'rate' => $coperte, 'scadenze' => $calendario,
        'decadenza_rate' => $nRate, 'decadenza_giorni' => $giorni,
    ], fn ($v) => $v !== null));
}

/** Il caso fino all'accordo registrato: [scenario, interessi, sollecito, accordo]. */
function aclAccordo($test, bool $rata2 = false): array
{
    [$s, $interessi, $sollecito] = aclNeri($test, $rata2);
    aclRegistra($test, $s, aclCoperte($s, $interessi, $sollecito), aclCalendario())->assertCreated();

    return [$s, $interessi, $sollecito, AccordoRientro::latest('id')->firstOrFail()];
}

/** Un versamento dalla rotta della pagina: [id quota => centesimi]; con `$dellAccordo` la scelta «Versamento dell'accordo». */
function aclVersa($test, array $s, array $righe, string $data, bool $dellAccordo = true, string $chi = 'Neri'): int
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($righe);
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'versamento_accordo' => $dellAccordo ? '1' : '0',
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** L'accordo come lo dice la finestra di Neri, oggi. */
function aclLetto($test, array $s, string $chi = 'Neri'): array
{
    return $test->actingAs($test->user)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id]))
        ->assertOk()->json();
}

/** Le rate del calendario del primo accordo letto: [numero, versato, mancano, versata]. */
function aclRate(array $letto): array
{
    return array_map(fn ($r) => [$r['numero'], $r['versato'], $r['mancano'], $r['versata']], $letto['accordi'][0]['calendario']);
}

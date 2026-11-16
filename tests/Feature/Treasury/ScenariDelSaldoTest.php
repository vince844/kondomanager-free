<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I tre scenari del saldo previsto e i dati delle azioni consigliate, dalla risposta della tesoreria.
 *
 * - Ogni giorno porta le rate dei condòmini che scadono quel giorno (al netto del credito di ognuno nella stessa gestione, come le entrate attese); le rate
 *   già scadute sono una cifra a parte. Con il saldo senza le rate, le tre linee si ricostruiscono: «Con le rate in scadenza» è il saldo più le rate in scadenza
 *   fino a quel giorno, «Se pagano tutti» anche le scadute; e «Se pagano tutti» è il saldo con le entrate attese di prima.
 * - Ogni giorno porta le sue fatture, con l'`id`, il fornitore, il numero e la cifra.
 * - Le persone con le rate scadute per il recupero: le rate scadute più alte prima, finché la somma copre la cifra che basta perché il saldo con le rate in
 *   scadenza non vada sotto zero; per ognuna il nome, l'interno, la cifra e l'ultima lettera. Quando il recupero non basta, tutte.
 *
 * Lo scenario: quattro persone, 12 rate mensili dal 05/01/2026 (rossi € 400,00 a rata, bianchi € 300,00, verdi € 200,00, neri € 100,00), tutte emesse; oggi
 * il 20/05/2026: cinque rate scadute (€ 5.000,00 in tutto), la sesta scade il 05/06 (€ 1.000,00). Una fattura il 25/05 porta il saldo a −«deficit».
 */

use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function sdsTesoreria(array $s): array
{
    return app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
}

/** Lo scenario; con `$deficit` una fattura del 25/05 che porta il saldo a −deficit fino al 05/06. */
function sdsCondominio($test, ?int $deficit = null): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    lcdEmetti($test, $s, range(1, 12), '2026-01-02');
    $test->travelTo('2026-05-20 10:00:00');
    if ($deficit !== null) {
        $disponibile = sdsTesoreria($s)['liquiditaDisponibileCents'];
        $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Edil Rossi srl', 'created_at' => now(), 'updated_at' => now()]);
        $s['fattura'] = DB::table('fatture_passive')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore,
            'tipo_documento' => 'fattura', 'numero_documento' => '45/2026', 'netto_a_pagare' => $disponibile + $deficit, 'totale_documento' => $disponibile + $deficit,
            'importo_imponibile' => $disponibile + $deficit, 'importo_iva' => 0, 'data_scadenza' => '2026-05-25', 'stato_pagamento' => 'aperta',
            'stato_approvazione' => 'approvata', 'is_pregresso' => false, 'data_documento' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
        $s['importo'] = $disponibile + $deficit;
    }

    return $s;
}

function sdsGiorno(array $t, string $giorno): array
{
    return collect($t['saldoPrevisto']['giorni'])->firstWhere('giorno', $giorno);
}

it('ogni giorno porta le rate che scadono quel giorno; le scadute sono a parte; le tre linee si ricostruiscono, e «Se pagano tutti» è il saldo con le entrate attese', function () {
    $s = sdsCondominio($this, 150000);
    $t = sdsTesoreria($s);
    $sp = $t['saldoPrevisto'];

    expect($sp['rateScaduteCents'])->toBe(500000)
        ->and($t['incassiScadutiCents'])->toBe(500000)
        ->and(sdsGiorno($t, '2026-06-05'))->toMatchArray(['rate_in_scadenza' => 100000, 'quante_rate_in_scadenza' => 4])
        ->and(sdsGiorno($t, '2026-05-20'))->toMatchArray(['rate_in_scadenza' => 0, 'quante_rate_in_scadenza' => 0])
        ->and(collect($sp['giorni'])->sum('rate_in_scadenza'))->toBe($t['incassiInScadenzaCents']);

    $cumulate = 0;
    foreach ($sp['giorni'] as $g) {
        $cumulate += $g['rate_in_scadenza'];
        // «Se pagano tutti» = il saldo + le scadute oggi + le rate in scadenza al loro giorno: lo stesso saldo con le entrate attese di prima.
        expect($g['saldo'] + $sp['rateScaduteCents'] + $cumulate)->toBe($g['con_entrate']);
    }
    // «Con le rate in scadenza» il 25/05 è a −€ 1.500,00, il 05/06 torna sopra con le rate.
    expect(sdsGiorno($t, '2026-05-25')['saldo'])->toBe(-150000)
        ->and(sdsGiorno($t, '2026-06-05')['saldo'] + 100000)->toBe(-50000);
});

it('ogni giorno porta le sue fatture, con l\'id, il fornitore, il numero e la cifra', function () {
    $s = sdsCondominio($this, 150000);
    $t = sdsTesoreria($s);

    expect(sdsGiorno($t, '2026-05-25')['fatture'])->toBe([['id' => $s['fattura'], 'fornitore' => 'Edil Rossi srl', 'numero' => '45/2026', 'importo' => $s['importo']]])
        ->and(sdsGiorno($t, '2026-05-24')['fatture'])->toBe([]);
});

it('una persona sola basta: le rate scadute più alte prima, finché coprono quanto basta; con il nome, l\'interno, la cifra e l\'ultima lettera', function () {
    $s = sdsCondominio($this, 150000);
    $t = sdsTesoreria($s);
    $r = $t['recupero'];

    expect($r['quante'])->toBe(4)
        ->and($r['cifra'])->toBe(500000)
        ->and($r['servono'])->toBe(1)
        ->and($r['servonoCents'])->toBe(200000)
        ->and($r['persone'])->toHaveCount(1)
        ->and($r['persone'][0])->toMatchArray(['anagraficaId' => $s['persone']['rossi']->id, 'nome' => 'Rossi', 'interno' => 'Int. A', 'cifra' => 200000, 'lettera' => null]);
});

it('servono tre persone: la terza copre quello che manca; l\'ultima lettera di chi l\'ha avuta', function () {
    $s = sdsCondominio($this, 420000);
    smaLettera($s, 'bianchi', 'sollecito', 'spedita', '2026-04-10', [1, 2]);
    smaLettera($s, 'bianchi', 'promemoria', 'stampata', '2026-03-01', [1]);
    $t = sdsTesoreria($s);
    $r = $t['recupero'];

    expect($r['servono'])->toBe(3)
        ->and($r['servonoCents'])->toBe(450000)
        ->and(array_column($r['persone'], 'nome'))->toBe(['Rossi', 'Bianchi', 'Verdi'])
        ->and(array_column($r['persone'], 'cifra'))->toBe([200000, 150000, 100000])
        ->and($r['persone'][1]['lettera'])->toBe(['tipo' => 'sollecito', 'data' => '2026-04-10'])
        ->and($r['persone'][0]['lettera'])->toBeNull();
});

it('quando il recupero non basta servono tutte le persone, e la cifra è tutto lo scaduto', function () {
    $s = sdsCondominio($this, 600000);
    $r = sdsTesoreria($s)['recupero'];

    expect($r['servono'])->toBe(4)
        ->and($r['servonoCents'])->toBe(500000)
        ->and(array_column($r['persone'], 'nome'))->toBe(['Rossi', 'Bianchi', 'Verdi', 'Neri']);
});

it('il saldo con le rate in scadenza non va mai sotto zero: nessuna persona serve, e la cifra delle scadute resta per la simulazione', function () {
    $s = sdsCondominio($this);
    $r = sdsTesoreria($s)['recupero'];

    expect($r['servono'])->toBe(0)
        ->and($r['servonoCents'])->toBe(0)
        ->and($r['persone'])->toBe([])
        ->and($r['quante'])->toBe(4)
        ->and($r['cifra'])->toBe(500000);
});

it('il credito di una persona nella stessa gestione toglie dalle sue rate scadute: la sua cifra è al netto, come le entrate attese', function () {
    $s = sdsCondominio($this, 600000);
    // Rossi versa la prima rata e € 300,00 in più, a credito nella gestione: le sue rate scadute da versare sono le altre quattro, meno il credito.
    lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 1) => 40000], '2026-05-19', 30000);
    $t = sdsTesoreria($s);

    $credito = collect($t['morositaImpattanti'])->firstWhere('condomino', 'Rossi')['creditoCents'];
    $rossi = collect($t['recupero']['persone'])->firstWhere('nome', 'Rossi');
    expect($credito)->toBeGreaterThan(0)
        ->and($rossi['cifra'])->toBe(160000 - $credito)
        ->and(array_sum(array_column($t['recupero']['persone'], 'cifra')))->toBe($t['recupero']['cifra'])
        ->and($t['recupero']['cifra'])->toBe($t['incassiScadutiCents']);
});

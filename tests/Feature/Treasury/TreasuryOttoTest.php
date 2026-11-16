<?php

/**
 * La regola delle 8 nella tesoreria: fino a 8 righe tutte, mai «e altri 1»; oltre, le prime 5 e quante mancano con la loro
 * cifra. Vale per le persone con rate scadute e per le fatture in scadenza. Le persone mostrate più chi manca fanno «Scaduto in tutto»; le fatture hanno il loro
 * `id` e vanno in ordine di scadenza.
 */

use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** N persone, una rata ciascuna scaduta il 05/01/2026, con cifre diverse (i millesimi da 10 a 10·N); oggi il 01/03/2026. */
function ottPersone($test, int $quante): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, $quante) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 10 * $i];
    }
    $s = lcdScenario($unita, 1, 'prima_rata', [], 100 * 10 * $quante * ($quante + 1) / 2);
    lcdEmetti($test, $s, [1], '2026-01-02');
    $test->travelTo('2026-03-01 10:00:00');

    return $s;
}

function ottFatture(array $s, int $quante): void
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Edil Rossi srl', 'created_at' => now(), 'updated_at' => now()]);
    foreach (range(1, $quante) as $i) {
        // Le scadenze in ordine rovesciato rispetto all'inserimento, e due fatture lo stesso giorno: la cifra più alta prima.
        $scadenza = \Carbon\CarbonImmutable::parse('2026-03-25')->subDays($i)->format('Y-m-d');
        $importo = $i === 2 ? 5000 : 10000 + $i * 100;
        DB::table('fatture_passive')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura',
            'numero_documento' => "{$i}/2026", 'netto_a_pagare' => $importo, 'totale_documento' => $importo, 'importo_imponibile' => $importo, 'importo_iva' => 0,
            'data_scadenza' => $i === 3 ? \Carbon\CarbonImmutable::parse('2026-03-25')->subDays(2)->format('Y-m-d') : $scadenza, 'stato_pagamento' => 'aperta',
            'stato_approvazione' => 'approvata', 'is_pregresso' => false, 'data_documento' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);
    }
}

function ottTesoreria(array $s): array
{
    return app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
}

it('8 persone: tutte e 8, nessuna riga di fondo', function () {
    $s = ottPersone($this, 8);
    $t = ottTesoreria($s);

    expect($t['morositaImpattanti'])->toHaveCount(8)
        ->and($t['morositaAltri'])->toBe(0)
        ->and($t['morositaAltriCents'])->toBe(0)
        ->and($t['morositaPersone'])->toBe(8)
        ->and(array_sum(array_column($t['morositaImpattanti'], 'importoCents')))->toBe($t['scadutoInTutto']);
});

it('9 persone: le 5 con lo scaduto più alto; mancano 4, con la loro cifra; le mostrate più chi manca fanno «Scaduto in tutto»', function () {
    $s = ottPersone($this, 9);
    $t = ottTesoreria($s);
    $cifre = DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->orderByDesc('importo')->pluck('importo')->map(fn ($x) => (int) $x)->all();

    expect($t['morositaImpattanti'])->toHaveCount(5)
        ->and(array_column($t['morositaImpattanti'], 'condomino'))->toBe(['Persona9', 'Persona8', 'Persona7', 'Persona6', 'Persona5'])
        ->and(array_column($t['morositaImpattanti'], 'importoCents'))->toBe(array_slice($cifre, 0, 5))
        ->and($t['morositaAltri'])->toBe(4)
        ->and($t['morositaAltriCents'])->toBe(array_sum(array_slice($cifre, 5)))
        ->and($t['morositaPersone'])->toBe(9)
        ->and(array_sum(array_column($t['morositaImpattanti'], 'importoCents')) + $t['morositaAltriCents'])->toBe($t['scadutoInTutto'])
        ->and($t['scadutoInTutto'])->toBe(array_sum($cifre));
});

it('le fatture con il loro id, in ordine di scadenza (a pari giorno la cifra più alta), con la stessa regola: 8 tutte, 9 le prime 5 e quante mancano', function () {
    $s = ottPersone($this, 1);
    ottFatture($s, 8);
    $t = ottTesoreria($s);
    expect($t['fattureInScadenza'])->toHaveCount(8)
        ->and($t['fattureQuante'])->toBe(8)
        ->and($t['fattureMancano'])->toBeNull()
        ->and(collect($t['fattureInScadenza'])->every(fn ($f) => is_int($f['id']) && $f['id'] > 0))->toBeTrue();

    DB::table('fatture_passive')->delete();
    ottFatture($s, 9);
    $t = ottTesoreria($s);
    $giorni = array_column($t['fattureInScadenza'], 'dataScadenza');
    $ordinati = $giorni;
    sort($ordinati);
    // Il 23/03 hanno la scadenza la 2 (€ 50,00) e la 3 (€ 103,00): prima la 3.
    expect($t['fattureInScadenza'])->toHaveCount(5)
        ->and($giorni)->toBe($ordinati)
        ->and(array_column($t['fattureInScadenza'], 'numero'))->toBe(['9/2026', '8/2026', '7/2026', '6/2026', '5/2026'])
        ->and($t['fattureQuante'])->toBe(9)
        ->and($t['fattureMancano'])->toBe(['quante' => 4, 'cifra' => 10400 + 10300 + 5000 + 10100])
        ->and($t['fattureInScadenza'][0]['id'])->toBe((int) DB::table('fatture_passive')->where('numero_documento', '9/2026')->value('id'));
    // L'ordine fra le ultime: la 4 il 21/03, poi la 3 e la 2 il 23/03, la 1 il 24/03.
    $tutte = collect(DB::table('fatture_passive')->get())->sortBy(fn ($f) => [$f->data_scadenza, -$f->netto_a_pagare])->pluck('numero_documento')->values()->all();
    expect(array_slice($tutte, 5))->toBe(['4/2026', '3/2026', '2/2026', '1/2026']);
});

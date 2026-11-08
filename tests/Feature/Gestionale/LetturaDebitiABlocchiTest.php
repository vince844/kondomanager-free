<?php

/**
 * La lettura comune dei debiti con un condominio grande. Con il pregresso nella prima rata (il metodo di serie dei piani) ogni chiave con un residuo
 * ha le sue quote da guardare, e la lettura decodificava insieme le regole di calcolo di tutte: con mille persone e dodici rate scadute ciascuna era
 * il picco della lettura, e la pagina «Rate scadute» arrivava a un passo dai 128 MB. Ora le regole si leggono a blocchi, e di ogni quota si tiene
 * solo ciò che serve; la lettura di una persona, o di un gruppo di persone, legge solo le loro quote.
 *
 * La lettura a blocchi di persone (`perCondominioABlocchi`) è la stessa lettura, fatta per gruppi di persone: per le posizioni dei condòmini e i
 * crediti per anzianità del rendiconto, che leggono tutto il condominio e tengono solo ciò che mostrano.
 *
 * Ogni prova confronta con la lettura di sempre: stesse cifre, chiave per chiave.
 */

use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Quante regole di calcolo la lettura legge insieme: la prova lo abbassa per passare dai confini dei blocchi. */
function ldbBloccoRegole(?int $n): void
{
    (new ReflectionProperty(LetturaDebiti::class, 'bloccoRegole'))->setValue(null, $n ?? 500);
}

/** N persone, un'unità ciascuna, dodici rate con il pregresso nella prima rata, tutte emesse il 02/01 e scadute al 31/12. */
function ldbGrande($test, int $n): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, $n) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 1200 * $n * 100);
    lcdEmetti($test, $s, range(1, 12), '2026-01-02');
    $test->travelTo('2027-01-20 10:00:00');

    return $s;
}

/**
 * I tre modi del pregresso in un condominio solo: Ugo con la rata 0 di un debito dichiarato del 2025, Bice con la rata 0 senza origine, Carla e Dino
 * con il pregresso dentro la prima rata (Carla) e in tutte (Dino, un altro piano), Elsa con un credito riportato. Tutte le rate emesse.
 */
function ldbPregressi($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 250], 'B' => ['bice', 250], 'C' => ['carla', 250], 'E' => ['elsa', 250]], 4, 'rata_zero',
        [['ugo', 'A', 30000], ['bice', 'B', 20000], ['elsa', 'E', -5000]], 120000);
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->where('anagrafica_id', $s['persone']['ugo']->id)->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);
    lcdEmetti($test, $s, [0, 1, 2, 3, 4], '2026-01-02');
    $prima = lcdScenario(['C' => ['carla', 1000]], 3, 'prima_rata', [['carla', 'C', 12000]], 60000);
    lcdEmetti($test, $prima, [1, 2, 3], '2026-01-02');
    $tutte = lcdScenario(['D' => ['dino', 1000]], 3, 'tutte_rate', [['dino', 'D', 9000]], 60000);
    lcdEmetti($test, $tutte, [1, 2, 3], '2026-01-02');
    $test->travelTo('2026-02-20 10:00:00');
    lcdIncassa($test, $s, 'bice', [lcdQuota($s, 'bice', 1) => 10000], '2026-02-20');
    $test->travelTo('2026-06-10 10:00:00');

    return [$s, $prima, $tutte];
}

it('la lettura con il pregresso nella prima rata non decodifica insieme le regole di tutte le quote: 300 persone × 12 rate, il picco resta piccolo', function () {
    $s = ldbGrande($this, 300);
    $lettura = app(LetturaDebiti::class);
    // Le regole si leggono a blocchi di 500 quote: 3.600 quote, 8 letture, nessuna con più di 500.
    $blocchi = [];
    DB::listen(function ($q) use (&$blocchi) {
        if (str_contains($q->sql, 'select "regole_calcolo", "id" from "rate_quote"')) {
            $blocchi[] = count($q->bindings);
        }
    });
    gc_collect_cycles();
    $prima = memory_get_usage();
    memory_reset_peak_usage();
    $l = $lettura->perCondominio((int) $s['c']->id, '2026-12-31');
    $picco = (memory_get_peak_usage() - $prima) / 1048576;

    // Misurato in questo processo: con le 3.600 regole decodificate insieme il picco era di circa 17 MB; a blocchi circa 8.
    expect($l)->toHaveCount(300)
        ->and(array_sum(array_map(fn ($p) => count($p['rate']), $l)))->toBe(3600)
        ->and(count($blocchi))->toBe(8)->and(max($blocchi))->toBe(500)->and(array_sum($blocchi))->toBe(3600)
        ->and($picco)->toBeLessThan(12.0);
});

it('le regole lette a blocchi di una, di due o tutte insieme danno la stessa lettura, con ogni modo del pregresso', function () {
    [$s, $prima, $tutte] = ldbPregressi($this);
    $leggi = fn () => array_map(fn ($x) => app(LetturaDebiti::class)->perCondominio((int) $x['c']->id, '2026-06-10'), [$s, $prima, $tutte]);
    ldbBloccoRegole(100000);
    $insieme = $leggi();
    try {
        ldbBloccoRegole(1);
        $aUna = $leggi();
        ldbBloccoRegole(2);
        $aDue = $leggi();
    } finally {
        ldbBloccoRegole(null);
    }

    // Il caso ha davvero i tre modi: la rata 0 con l'origine dichiarata e senza, il pregresso dentro la prima rata e in ogni rata.
    $ugo = lcdRiga($insieme[0][$s['persone']['ugo']->id], $s, 'A', 0);
    $bice = lcdRiga($insieme[0][$s['persone']['bice']->id], $s, 'B', 0);
    $carla = lcdRiga($insieme[1][$prima['persone']['carla']->id], $prima, 'C', 1);
    $dino = collect($insieme[2][$tutte['persone']['dino']->id]['rate']);
    expect($ugo['pregresso']['origine'])->toBe('esercizio')
        ->and($bice['pregresso']['origine'])->toBe('non_dichiarata')
        ->and($carla['pregresso_nella_rata'])->toBeTrue()
        ->and($dino->where('pregresso_nella_rata', true)->count())->toBe(3)
        ->and($aUna)->toEqual($insieme)
        ->and($aDue)->toEqual($insieme);
});

it('la lettura per gruppi di persone è la lettura del condominio, persona per persona, con ogni modo del pregresso e il credito di oggi', function () {
    [$s] = ldbPregressi($this);
    $lettura = app(LetturaDebiti::class);
    foreach (['2026-06-10', '2026-01-31', null] as $alla) {
        $tutte = $lettura->perCondominio((int) $s['c']->id, $alla);
        foreach ([1, 2, 250] as $persone) {
            $aBlocchi = [];
            $blocchi = 0;
            foreach ($lettura->perCondominioABlocchi((int) $s['c']->id, $alla, $persone) as $blocco) {
                $blocchi++;
                expect(count($blocco))->toBeLessThanOrEqual($persone);
                $aBlocchi += $blocco;
            }
            ksort($aBlocchi);
            ksort($tutte);
            expect($aBlocchi)->toEqual($tutte)->and($blocchi)->toBe((int) ceil(count($tutte) / $persone));
        }
    }
});

it('la lettura di una persona non legge le quote delle altre persone: con 300 persone resta piccola', function () {
    $s = ldbGrande($this, 300);
    $persona = (int) $s['persone']['persona7']->id;
    $quote = 0;
    DB::listen(function ($q) use (&$quote) {
        if (str_contains($q->sql, 'from "rate_quote" as "q"')) {
            $quote++;
            expect($q->sql)->toContain('"q"."anagrafica_id" in');
        }
    });

    $l = app(LetturaDebiti::class)->perPersona((int) $s['c']->id, $persona, '2026-12-31');

    expect($quote)->toBe(1)->and($l['totali']['emesso_non_pagato'])->toBe(120000);
});

it('la lettura per gruppi, oggi, ha anche chi ha solo un credito disponibile fuori dal giornale, come la lettura del condominio', function () {
    $this->travelTo('2026-03-20 10:00:00');
    // Fabio ha un credito riportato sulla rata 0 in bozza e nessuna rata emessa: nessuna riga nel giornale, il credito di oggi sì.
    $s = lcdScenario(['A' => ['ugo', 500], 'F' => ['fabio', 500]], 12, 'rata_zero', [['fabio', 'F', -20000]]);
    $lettura = app(LetturaDebiti::class);
    $tutte = $lettura->perCondominio((int) $s['c']->id);
    $aBlocchi = [];
    foreach ($lettura->perCondominioABlocchi((int) $s['c']->id, null, 1) as $blocco) {
        $aBlocchi += $blocco;
    }
    ksort($tutte);
    ksort($aBlocchi);

    expect(DB::table('righe_scritture')->where('anagrafica_id', $s['persone']['fabio']->id)->count())->toBe(0)
        ->and($tutte[$s['persone']['fabio']->id]['credito_disponibile']['totale'] ?? 0)->toBeGreaterThan(0)
        ->and($aBlocchi)->toEqual($tutte);
});

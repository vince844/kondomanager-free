<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il peso della lista dello studio dei sei mesi per agire. Ogni condominio è una richiesta sua, in fila, dopo la pagina: qui la lista intera è la somma delle
 * richieste, fatte una dopo l'altra come le fa la pagina. Due studi: 50 condomini × 40 persone × 3 rate scadute, e 200 × 15 × 2; in ogni condominio una
 * dispensa e qualche risposta, che il riassunto legge.
 *
 * Le soglie: 50 × 40 × 3 in 3 s e 64 MB in più; 200 × 15 × 2 in 6 s e 64 MB in più, e a 200 condomini non più di 8 MB sopra il picco a 50 (la memoria non
 * cresce da un condominio all'altro: ogni richiesta pesa quanto il suo condominio).
 *
 * Le misure del 15/11/2026, Apple M1 Pro, SQLite in memoria: 50 × 40 × 3 in 0,35 s e 2,0 MB in più, le 50 richieste in fila 0,39 s; 200 × 15 × 2 in 1,06 s e
 * 0,2 MB, il picco dopo 50 condomini e dopo 200 uguale (0,2 MB).
 */

use App\Models\Condominio;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

const SMSM_SECONDI_50 = 3.0;
const SMSM_SECONDI_200 = 6.0;
const SMSM_MEGA = 64.0;
const SMSM_MEGA_IN_PIU_A_200 = 8.0;

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** Lo studio: N condomini × P persone × R rate scadute al 20/01/2027, con una dispensa e due risposte per condominio. */
function smsmStudio($test, int $condomini, int $persone, int $rate): array
{
    $ids = [];
    for ($i = 0; $i < $condomini; $i++) {
        $test->travelTo('2026-01-02 10:00:00');
        $unita = [];
        foreach (range(1, $persone) as $p) {
            $unita["U{$p}"] = ["s{$i}p{$p}", 1];
        }
        $s = lcdScenario($unita, $rate, 'prima_rata', [], 100 * $persone * 100);
        lcdEmetti($test, $s, range(1, $rate), '2026-01-02');
        smaDispensa($s, [$s['e']], array_slice(array_keys($s['persone']), 0, 2));
        $adesso = now()->format('Y-m-d H:i:s');
        $chi = $s['persone']["s{$i}p3"]->id;
        $chiavi = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id')->map(fn ($r) => $r . '|' . $s['immobili']['U3']->id)->all();
        DB::table('risposte_promemoria')->insert([
            ['condominio_id' => $s['c']->id, 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e']->id, 'anagrafica_id' => null, 'giorno' => null, 'dati' => null,
                'chiave' => "sei_mesi|{$s['c']->id}|{$s['e']->id}|-", 'stato' => 'non_serve', 'nota' => 'assemblea informata', 'registro' => '[]', 'created_at' => $adesso, 'updated_at' => $adesso],
            ['condominio_id' => $s['c']->id, 'argomento' => 'sei_mesi', 'esercizio_id' => $s['e']->id, 'anagrafica_id' => $chi, 'giorno' => '2026-01-02',
                'dati' => json_encode(['chiavi' => $chiavi, 'cifra' => 10_000_000, 'registrato_il' => '2026-01-02']),
                'chiave' => "sei_mesi|{$s['c']->id}|{$s['e']->id}|{$chi}", 'stato' => 'agito', 'nota' => 'telefonata', 'registro' => '[]', 'created_at' => $adesso, 'updated_at' => $adesso],
        ]);
        $ids[] = $s['c']->id;
    }
    $test->travelTo('2027-01-20 10:00:00');

    return $ids;
}

/** La lista intera, un condominio dopo l'altro come le richieste della pagina: i secondi, il picco in più, e il picco dopo i primi 50. */
function smsmLista(array $ids): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $inizio = hrtime(true);
    $righe = 0;
    $picchi = [];
    foreach ($ids as $k => $id) {
        $esito = app(SeiMesiPerAgire::class)->perLoStudio(Condominio::find($id));
        $righe += count($esito['righe']);
        unset($esito);
        gc_collect_cycles();
        if ($k === 49 || $k === count($ids) - 1) {
            $picchi[$k + 1] = (memory_get_peak_usage() - $base) / 1048576;
        }
    }

    return [$righe, (hrtime(true) - $inizio) / 1e9, (memory_get_peak_usage() - $base) / 1048576, $picchi];
}

it('50 condomini × 40 persone × 3 rate: la lista intera in 3 s e 64 MB; le richieste della pagina, una per condominio, nello stesso tempo', function () {
    $ids = smsmStudio($this, 50, 40, 3);

    [$righe, $secondi, $mega] = smsmLista($ids);
    fwrite(STDERR, sprintf("\n[misura] lista dello studio 50 × 40 × 3: %.2f s, %.1f MB in più\n", $secondi, $mega));

    $inizio = hrtime(true);
    foreach ($ids as $id) {
        $this->actingAs($this->user)->getJson(route('admin.dashboard.sei-mesi', ['condominio' => $id]))->assertOk();
    }
    $richieste = (hrtime(true) - $inizio) / 1e9;
    fwrite(STDERR, sprintf("[misura] le 50 richieste in fila: %.2f s\n", $richieste));

    // Una riga per condominio: la dispensa chiude due persone, una ha agito; il «Non serve» spegne il compito, la riga resta.
    expect($righe)->toBe(50)
        ->and($secondi)->toBeLessThan(SMSM_SECONDI_50)
        ->and($mega)->toBeLessThan(SMSM_MEGA)
        ->and($richieste)->toBeLessThan(SMSM_SECONDI_50);
});

it('200 condomini × 15 persone × 2 rate: la lista intera in 6 s e 64 MB, e la memoria non cresce da un condominio all\'altro', function () {
    $ids = smsmStudio($this, 200, 15, 2);

    [$righe, $secondi, $mega, $picchi] = smsmLista($ids);
    fwrite(STDERR, sprintf("\n[misura] lista dello studio 200 × 15 × 2: %.2f s, %.1f MB in più; picco dopo 50: %.1f MB, dopo 200: %.1f MB\n", $secondi, $mega, $picchi[50], $picchi[200]));

    expect($righe)->toBe(200)
        ->and($secondi)->toBeLessThan(SMSM_SECONDI_200)
        ->and($mega)->toBeLessThan(SMSM_MEGA)
        ->and($picchi[200] - $picchi[50])->toBeLessThan(SMSM_MEGA_IN_PIU_A_200);
});

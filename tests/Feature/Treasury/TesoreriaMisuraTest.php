<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il peso della tesoreria a 30 giorni. Lo scenario della misura dei sei mesi (N persone × 12 rate scadute, 3–5 lettere per persona, un accordo
 * ogni dieci persone con il calendario e un incasso dopo, due dispense), con una banca. La tesoreria legge le rate dei condòmini a blocchi di 250 persone e
 * tiene gli eventi del saldo previsto già sommati per giorno; la prossima azione la legge per le sole persone mostrate.
 *
 * Le misure (Apple M1 Pro, SQLite in memoria, il condominio fuori dalla misura, MB in più del picco) sono scritte accanto alle soglie;
 * le soglie sono scritte accanto. Con `TESORERIA_MISURA_GRANDE=1` la misura a 3.000 × 12, che non gira nella suite (lo scenario chiede un minuto e mezzo).
 */

use App\Models\Gestionale\AccordoRientro;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

/**
 * Misurato il 15/11/2026: 300 × 12 0,18 s e 7,8 MB; 1.000 × 12 0,54 s e 11,7 MB (con la lettura intera di prima 0,24 s e 8,7 MB, 0,85 s e 29,2 MB, a
 * 3.000 × 12 89,2 MB). Le soglie: a 300 × 12 sotto un secondo; a 1.000 × 12 sotto i 25 MB in più.
 *
 * La dashboard intera a 3.000 × 12, misurata il 16/11/2026: 26,2 MB in più e 5,70 s, con la copertura del bilancio che somma le quote nel database (prima
 * le caricava come modelli: 125,8 MB e 6,28 s). La soglia, che vale solo per la misura a mano: sotto i 110 MB in più.
 */
const TMI_SECONDI_300 = 1.0;
const TMI_MEGA_1000 = 25.0;
const TMI_MEGA_DASHBOARD_3000 = 110.0;

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** N persone × 12 rate scadute al 20/01/2027, con le lettere, gli accordi e le dispense. */
function tmiCondominio($test, int $persone): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, $persone) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 1200 * $persone * 100);
    lcdEmetti($test, $s, range(1, 12), '2026-01-02');
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id', 'numero_rata')->all();
    $adesso = now()->format('Y-m-d H:i:s');
    $tipi = ['promemoria', 'sollecito', 'diffida', 'sollecito', 'messa_in_mora'];
    $righe = [];
    foreach (array_values($s['persone']) as $k => $p) {
        $iid = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->value('immobile_id');
        foreach (range(0, 2 + ($k % 3)) as $l) {
            $giorno = \Carbon\CarbonImmutable::parse('2026-03-01')->addDays($l * 40 + ($k % 7));
            $dati = ['lettera' => "tmi-{$p->id}-{$l}", 'persona' => ['id' => $p->id, 'nome' => $p->nome], 'alla' => $giorno->format('Y-m-d'), 'modello' => str_repeat('Testo ', 40),
                'righe' => array_map(fn ($n) => ['rata_id' => $rate[$n], 'immobile_id' => $iid, 'numero_rata' => $n, 'residuo' => 10000], range(1, 12)), 'totale' => 120000,
                'termine' => 15, 'termine_forma' => 'giorni'];
            if ($l % 2 === 0) {
                $dati['spedizione'] = ['canale' => 'raccomandata', 'data' => $giorno->addDay()->format('Y-m-d'), 'numero' => 'RR'];
            }
            $righe[] = ['condominio_id' => $s['c']->id, 'anagrafica_id' => $p->id, 'tipo' => $tipi[$l], 'canale' => 'stampa', 'documentabile_type' => \App\Models\Anagrafica::class,
                'documentabile_id' => $p->id, 'dati' => json_encode($dati), 'stato' => $l % 2 === 0 ? 'spedita' : 'stampata', 'emesso_il' => $giorno->format('Y-m-d 09:00:00'),
                'created_at' => $adesso, 'updated_at' => $adesso];
            if (count($righe) >= 300) {
                DB::table('registro_invii')->insert($righe);
                $righe = [];
            }
        }
    }
    DB::table('registro_invii')->insert($righe);
    $test->travelTo('2026-06-01 10:00:00');
    foreach (array_values($s['persone']) as $k => $p) {
        if ($k % 10 !== 0) {
            continue;
        }
        $iid = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->value('immobile_id');
        $a = AccordoRientro::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $p->id, 'data_accordo' => '2026-05-01', 'fino_al' => '2027-04-30', 'autorizzato_assemblea' => false]);
        foreach ([1, 2, 3] as $n) {
            DB::table('accordo_rientro_rate')->insert(['accordo_rientro_id' => $a->id, 'rata_id' => $rate[$n], 'immobile_id' => $iid, 'importo_riconosciuto' => 10000, 'residuo_iniziale' => 10000,
                'created_at' => $adesso, 'updated_at' => $adesso]);
            DB::table('accordo_rientro_scadenze')->insert(['accordo_rientro_id' => $a->id, 'numero' => $n, 'data' => '2026-0' . (5 + $n) . '-30', 'importo' => 10000, 'created_at' => $adesso, 'updated_at' => $adesso]);
        }
        lcdIncassa($test, $s, array_search($p, $s['persone'], true), [(int) DB::table('rate_quote')->where('rata_id', $rate[1])->where('anagrafica_id', $p->id)->value('id') => 5000], '2026-06-01');
    }
    smaDispensa($s, [$s['e']], array_slice(array_keys($s['persone']), 0, 5));
    $test->travelTo('2027-01-20 10:00:00');

    return $s;
}

/**
 * Una rata 13 non ancora emessa (in bozza, mai a giornale) con la scadenza il 05/02/2027, dentro i 30 giorni dal 20/01/2027: € 100,00 per persona. È la
 * lettura delle rate da emettere delle azioni consigliate, con tutte le quote della rata.
 */
function tmiRataNonEmessa(array $s): int
{
    $adesso = now()->format('Y-m-d H:i:s');
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $s['piano']->id, 'numero_rata' => 13, 'data_scadenza' => '2027-02-05', 'descrizione' => 'Rata 13',
        'importo_totale' => 10000 * count($s['persone']), 'stato' => 'bozza', 'created_at' => $adesso, 'updated_at' => $adesso]);
    $righe = [];
    foreach (array_values($s['persone']) as $p) {
        $righe[] = ['rata_id' => $rata, 'anagrafica_id' => $p->id, 'immobile_id' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p->id)->value('immobile_id'),
            'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2027-02-05', 'created_at' => $adesso, 'updated_at' => $adesso];
        if (count($righe) >= 300) {
            DB::table('rate_quote')->insert($righe);
            $righe = [];
        }
    }
    DB::table('rate_quote')->insert($righe);

    return $rata;
}

function tmiMisura(callable $f): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $esito = $f();

    return [$esito, (hrtime(true) - $inizio) / 1e9, (memory_get_peak_usage() - $prima) / 1048576];
}

function tmiScrivi(string $riga): void
{
    fwrite(STDERR, "\n[misura] {$riga}\n");
}

it('a 300 × 12 la tesoreria sta sotto un secondo, con le cinque persone mostrate e la loro prossima azione', function () {
    $s = tmiCondominio($this, 300);
    [$t, $secondi, $mega] = tmiMisura(fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray());
    tmiScrivi(sprintf('tesoreria 300 × 12: %.2f s, %.1f MB in più', $secondi, $mega));

    expect($t['morositaPersone'])->toBe(300)
        ->and($t['morositaImpattanti'])->toHaveCount(5)
        ->and(collect($t['morositaImpattanti'])->every(fn ($m) => $m['passo'] !== null))->toBeTrue()
        ->and($secondi)->toBeLessThan(TMI_SECONDI_300);
});

it('a 1.000 × 12 la tesoreria resta sotto i 25 MB in più: la memoria è quella di un blocco di persone', function () {
    $s = tmiCondominio($this, 1000);
    [$t, $secondi, $mega] = tmiMisura(fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray());
    tmiScrivi(sprintf('tesoreria 1.000 × 12: %.2f s, %.1f MB in più', $secondi, $mega));

    expect($t['morositaPersone'])->toBe(1000)
        ->and($t['scadutoInTutto'])->toBeGreaterThan(0)
        // Le rate scadute per persona del recupero si tengono come cifre, una per persona: le persone con il nome e la lettera sono al più 8.
        ->and($t['recupero']['quante'])->toBe(1000)
        ->and($t['recupero']['cifra'])->toBe($t['incassiScadutiCents'])
        ->and(count($t['recupero']['persone']))->toBeLessThanOrEqual(8)
        ->and($t['saldoPrevisto']['rateScaduteCents'])->toBe($t['incassiScadutiCents'])
        ->and($mega)->toBeLessThan(TMI_MEGA_1000);

    // Con una rata non ancora emessa di tutte le 1.000 persone: la sua cifra si somma nel database, per persona, e la memoria resta quella.
    tmiRataNonEmessa($s);
    [$t, $secondi, $mega] = tmiMisura(fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray());
    tmiScrivi(sprintf('tesoreria 1.000 × 12 con una rata da emettere: %.2f s, %.1f MB in più', $secondi, $mega));
    expect($t['datiDelleAzioni']['rateDaEmettere'])->toHaveCount(1)
        ->and($t['datiDelleAzioni']['rateDaEmettere'][0]['rate'][0])->toMatchArray(['numero' => 13, 'scadenza' => '2027-02-05', 'cifra' => 1000 * 10000])
        ->and($mega)->toBeLessThan(TMI_MEGA_1000);
});

it('a 3.000 × 12 la tesoreria e la dashboard (solo con TESORERIA_MISURA_GRANDE=1)', function () {
    $s = tmiCondominio($this, 3000);
    [$t, $secondi, $mega] = tmiMisura(fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray());
    tmiScrivi(sprintf('tesoreria 3.000 × 12: %.2f s, %.1f MB in più', $secondi, $mega));
    [$r, $secondiPagina, $megaPagina] = tmiMisura(fn () => $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']))->assertOk()->status());
    tmiScrivi(sprintf('dashboard 3.000 × 12: %.2f s, %.1f MB in più', $secondiPagina, $megaPagina));

    tmiRataNonEmessa($s);
    [$t2, $secondiRata, $megaRata] = tmiMisura(fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray());
    tmiScrivi(sprintf('tesoreria 3.000 × 12 con una rata da emettere: %.2f s, %.1f MB in più', $secondiRata, $megaRata));

    expect($t['morositaPersone'])->toBe(3000)->and($r)->toBe(200)->and($megaPagina)->toBeLessThan(TMI_MEGA_DASHBOARD_3000)
        ->and($t2['datiDelleAzioni']['rateDaEmettere'][0]['cifra'])->toBe(3000 * 10000);
})->skip(fn () => getenv('TESORERIA_MISURA_GRANDE') !== '1', 'la misura a 3.000 × 12 si lancia a mano');

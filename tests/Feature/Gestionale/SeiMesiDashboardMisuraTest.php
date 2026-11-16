<?php

/**
 * Il peso del riquadro dei sei mesi nella dashboard del condominio. Lo scenario della misura dell'elenco «Rate scadute» (N persone × 12 rate scadute) con ciò che il riquadro legge in
 * più: 3–5 lettere per persona con le loro rate, metà spedite; un accordo ogni dieci persone, con il calendario e un incasso dopo; due dispense.
 *
 * Le misure del 15/11/2026, Apple M1 Pro, SQLite in memoria, il condominio fuori dalla misura (MB in più del picco):
 * - prima del riquadro, la dashboard: 300 × 12 0,45 s e 22,0 MB; 1.000 × 12 1,15 s e 70,3 MB; 3.000 × 12 3,54 s e 212,8 MB (la tesoreria e la
 *   copertura del bilancio, non il riquadro);
 * - il riquadro, con la riga dell'elenco «Rate scadute» in un posto solo e le date dei sei mesi calcolate una volta per rata di un piano, a blocchi di 250
 *   persone: 300 × 12 0,29 s e 13,9 MB; la dashboard con il riquadro a 1.000 × 12 2,21 s e 71,0 MB (prima del riquadro 1,15 s e 70,3 MB). In fila dopo la
 *   tesoreria il picco è quello della tesoreria.
 *
 * Le soglie: a 300 × 12 il riquadro non aggiunge più di 0,5 s; a 1.000 × 12 il picco della richiesta resta sotto 120 MB. Sopra la soglia delle quote scadute
 * ({@see SeiMesiPerAgire::$sogliaQuote}) il riquadro non calcola e lo dice: la frase nasce dal controllo, non dall'errore.
 */

use App\Models\Gestionale\AccordoRientro;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

const SDM_SECONDI_300 = 0.5;
const SDM_MEGA_1000 = 120.0;

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** N persone × 12 rate scadute al 20/01/2027, con le lettere, gli accordi e le dispense. */
function sdmCondominio($test, int $persone): array
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
            $dati = ['lettera' => "sdm-{$p->id}-{$l}", 'persona' => ['id' => $p->id, 'nome' => $p->nome], 'alla' => $giorno->format('Y-m-d'), 'modello' => str_repeat('Testo ', 40),
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

function sdmMisura(callable $f): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $esito = $f();

    return [$esito, (hrtime(true) - $inizio) / 1e9, (memory_get_peak_usage() - $prima) / 1048576];
}

it('a 300 × 12 il riquadro aggiunge al più 0,5 s alla dashboard', function () {
    $s = sdmCondominio($this, 300);
    [$r, $secondi, $mega] = sdmMisura(fn () => app(SeiMesiPerAgire::class)->perCondominio($s['c']));
    fwrite(STDERR, sprintf("\n[misura] riquadro 300 × 12: %.2f s, %.1f MB in più\n", $secondi, $mega));

    expect($r['righe'])->not->toBe([])
        ->and($secondi)->toBeLessThan(SDM_SECONDI_300);
});

it('a 1.000 × 12 la dashboard con il riquadro resta sotto 120 MB di picco', function () {
    $s = sdmCondominio($this, 1000);
    [$risposta, $secondi, $mega] = sdmMisura(fn () => $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c'])));
    fwrite(STDERR, sprintf("\n[misura] dashboard con il riquadro, 1.000 × 12: %.2f s, %.1f MB in più\n", $secondi, $mega));

    $risposta->assertOk();
    expect($risposta->viewData('page')['props']['seiMesi']['righe'])->not->toBe([])
        ->and($mega)->toBeLessThan(SDM_MEGA_1000);
});

it('sopra la soglia delle quote scadute il riquadro non calcola, e lo dice; i rendiconti restano', function () {
    $s = smaViaVerdi($this);
    $soglia = new ReflectionProperty(SeiMesiPerAgire::class, 'sogliaQuote');
    $prima = $soglia->getValue();
    $soglia->setValue(null, 10);
    try {
        $r = smaRiquadro($s);
    } finally {
        $soglia->setValue(null, $prima);
    }

    expect(SeiMesiPerAgire::quoteScadute((int) $s['c']->id, '2026-05-20'))->toBe(20)
        ->and($r['troppe'])->toBe(['quote' => 20, 'frase' => 'Questo condominio ha troppe rate scadute per calcolare qui i sei mesi: le date sono nell\'elenco «Rate scadute».'])
        ->and($r['righe'])->toBe([])
        ->and($r['rendiconti'])->not->toBe([])
        ->and($prima)->toBe(100000);
});

<?php

/**
 * Il consuntivo per persona su un condominio grande: 3.000 unità, 3.300 persone (300 unità passano di mano il 01/07), 30 voci.
 * Due forme: le 30 voci sulla stessa tabella, e una tabella per voce (30 tabelle da 3.000 righe, il caso peggiore per la memoria).
 *
 * Le soglie sono scritte qui: la memoria in più che la lettura chiede, sotto i 64 MB (il limite della richiesta è 128 MB, e il
 * resto serve al programma); il tempo, sotto le soglie di `CPMI_SECONDI_*`. Il risultato con i pesi riusati fra le voci è lo
 * stesso delle voci lette una per una.
 *
 * Fixture propria (`cpmi*`), scritta a blocchi perché 3.000 unità fatte con i modelli costerebbero più della misura.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoConto;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

const CPMI_UNITA = 3000;
const CPMI_VOCI = 30;
const CPMI_MEGA_IN_PIU = 64;
const CPMI_SECONDI_TABELLA_COMUNE = 4.0;
const CPMI_SECONDI_TABELLA_PER_VOCE = 25.0;

/** @return array{esercizio: Esercizio, gestione: Gestione, voci: list<int>, persone: int} */
function cpmiCondominio(bool $tabellaPerVoce): array
{
    $adesso = now()->format('Y-m-d H:i:s');
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $gestione = Gestione::create(['condominio_id' => $condominio->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'descrizione' => 'misura']);
    legaAEsercizio($esercizio, $gestione->id);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);

    $righe = [];
    for ($u = 1; $u <= CPMI_UNITA; $u++) {
        $righe[] = ['condominio_id' => $condominio->id, 'codice_immobile' => "CPMI-{$u}", 'nome' => "Unità {$u}", 'interno' => (string) $u, 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($righe, 500) as $blocco) {
        DB::table('immobili')->insert($blocco);
    }
    $unita = DB::table('immobili')->where('condominio_id', $condominio->id)->orderBy('id')->pluck('id')->all();

    $persone = [];
    $n = 0;
    foreach ($unita as $i => $iid) {
        $persone[] = ['iid' => $iid, 'dal' => '2020-01-01', 'al' => $i % 10 === 0 ? '2026-06-30' : null];
        if ($i % 10 === 0) {
            $persone[] = ['iid' => $iid, 'dal' => '2026-07-01', 'al' => null];
        }
    }
    $anagrafiche = [];
    foreach ($persone as $p) {
        $n++;
        $anagrafiche[] = ['nome' => "Persona {$n}", 'indirizzo' => 'Via Lunga 1', 'email' => "cpmi{$n}@test.it", 'codice_fiscale' => 'CPMI' . str_pad((string) $n, 12, '0', STR_PAD_LEFT), 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($anagrafiche, 500) as $blocco) {
        DB::table('anagrafiche')->insert($blocco);
    }
    $ids = DB::table('anagrafiche')->where('email', 'like', 'cpmi%')->orderBy('id')->pluck('id')->all();
    $titolarita = [];
    foreach ($persone as $k => $p) {
        $titolarita[] = ['anagrafica_id' => $ids[$k], 'immobile_id' => $p['iid'], 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => $p['dal'], 'data_fine' => $p['al'], 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($titolarita, 500) as $blocco) {
        DB::table('anagrafica_immobile')->insert($blocco);
    }

    $tabelle = [];
    foreach (range(1, $tabellaPerVoce ? CPMI_VOCI : 1) as $t) {
        $tid = DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => "Tabella {$t}", 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true, 'created_at' => $adesso, 'updated_at' => $adesso]);
        $quote = [];
        foreach ($unita as $i => $iid) {
            $quote[] = ['tabella_id' => $tid, 'immobile_id' => $iid, 'valore' => 1 + (($i * 7 + $t * 13) % 97) / 10, 'created_at' => $adesso, 'updated_at' => $adesso];
        }
        foreach (array_chunk($quote, 500) as $blocco) {
            DB::table('quote_tabella')->insert($blocco);
        }
        $tabelle[] = $tid;
    }

    $voci = [];
    foreach (range(1, CPMI_VOCI) as $v) {
        $cid = DB::table('conti')->insertGetId(['piano_conto_id' => $pianoConto->id, 'nome' => "Voce {$v}", 'tipo' => 'spesa', 'importo' => 0, 'created_at' => $adesso, 'updated_at' => $adesso]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $cid, 'tabella_id' => $tabelle[$tabellaPerVoce ? $v - 1 : 0], 'coefficiente' => 100, 'created_at' => $adesso, 'updated_at' => $adesso]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => $adesso, 'updated_at' => $adesso]);
        $voci[] = $cid;
    }

    return ['esercizio' => $esercizio, 'gestione' => $gestione, 'voci' => $voci, 'persone' => count($ids)];
}

/** @return array<int,int> */
function cpmiCifre(array $voci): array
{
    $cifre = [];
    foreach ($voci as $k => $cid) {
        $cifre[$cid] = 1234567 + $k * 98765;
    }

    return $cifre;
}

/** @return array{0: array, 1: float, 2: float} il risultato, i secondi, i MB in più */
function cpmiMisura(array $c, array $cifre): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $esito = (new CalcoloQuoteService())->consuntivoPerGestione($c['gestione']->fresh(), $c['esercizio'], $cifre);
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    fwrite(STDERR, sprintf("\n[misura] %d unità, %d voci: %.2f s, %.1f MB in più\n", CPMI_UNITA, count($cifre), $secondi, $mega));

    return [$esito, $secondi, $mega];
}

function cpmiControlla(array $c, array $cifre, array $esito): void
{
    $perVoce = [];
    $chiavi = 0;
    foreach ($esito['persone'] as $perUnita) {
        foreach ($perUnita as $voci) {
            $chiavi++;
            foreach ($voci as $cid => $centesimi) {
                $perVoce[$cid] = ($perVoce[$cid] ?? 0) + $centesimi;
            }
        }
    }
    ksort($perVoce);
    ksort($cifre);
    expect($perVoce)->toBe($cifre)->and($esito['non_attribuite'])->toBe([])->and($chiavi)->toBe($c['persone']);

    // Tre voci lette da sole (nessun peso riusato) danno le stesse cifre, persona per persona.
    foreach ([0, 14, 29] as $k) {
        $cid = $c['voci'][$k];
        $sola = (new CalcoloQuoteService())->consuntivoPerGestione($c['gestione']->fresh(), $c['esercizio'], [$cid => $cifre[$cid]]);
        foreach ($sola['persone'] as $aid => $perUnita) {
            foreach ($perUnita as $iid => $voci) {
                expect($esito['persone'][$aid][$iid][$cid] ?? null)->toBe($voci[$cid]);
            }
        }
    }
}

it('legge 3.000 unità e 30 voci sulla stessa tabella dentro la memoria e il tempo', function () {
    $c = cpmiCondominio(false);
    $cifre = cpmiCifre($c['voci']);

    [$esito, $secondi, $mega] = cpmiMisura($c, $cifre);

    expect($mega)->toBeLessThan(CPMI_MEGA_IN_PIU)->and($secondi)->toBeLessThan(CPMI_SECONDI_TABELLA_COMUNE);
    cpmiControlla($c, $cifre, $esito);
});

it('legge 3.000 unità e 30 voci con una tabella per voce dentro la memoria e il tempo', function () {
    $c = cpmiCondominio(true);
    $cifre = cpmiCifre($c['voci']);

    [$esito, $secondi, $mega] = cpmiMisura($c, $cifre);

    expect($mega)->toBeLessThan(CPMI_MEGA_IN_PIU)->and($secondi)->toBeLessThan(CPMI_SECONDI_TABELLA_PER_VOCE);
    cpmiControlla($c, $cifre, $esito);
});

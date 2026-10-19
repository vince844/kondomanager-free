<?php

/**
 * Cosa resta scoperto: il disegno dei componenti Vue (le frasi e i badge hanno i loro vitest: `columnsBeta52`, `PianiRateShowBeta52`,
 * `DashboardBeta52`, `totaliPerTipo`); la pagina di creazione del piano montata (il selettore manda `tipo` e il totale lo calcola
 * `totaliPerTipo`: lo prova `PianiRateNewTotali.test.ts`); le voci `uscita`; il piano straordinario da fatture montato con le sue fatture a più righe.
 *
 * I totali a video con le voci di entrata: la pagina del piano rate, l'elenco dei piani rate, la pagina del piano dei conti, l'elenco dei
 * piani dei conti e il selettore della creazione del piano.
 *
 * Le parole (decisione 87.3): nel piano dei conti «Preventivo» resta sulle sole spese, e le entrate hanno il loro totale; nel piano rate
 * e nell'elenco dei piani il netto si chiama «da ripartire». Le chiavi sono nuove (`totale_spese`, `totale_entrate`,
 * `totale_da_ripartire`) e `totale_capitoli`, che sommava le entrate come spese, sparisce: nessun codice può più usarlo con il vecchio
 * significato.
 *
 * Scenario: spese € 1.200,00 ed entrata € 240,00 (da ripartire € 960,00). Nel piano dei conti e nel piano rate i totali seguono il motore: ogni
 * voce per il valore assoluto del suo importo, dal lato del suo tipo (`Conto::partiDelTipo`), anche una spesa scritta con il meno da una versione vecchia.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Models\Gestionale\Conto;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

function b52tProps($test, string $rotta, array $parametri): array
{
    $r = $test->actingAs($test->user)->get(route($rotta, $parametri));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

it('T1.7a la pagina del piano rate dice spese, entrate e da ripartire, e ogni voce porta il suo tipo', function () {
    $s = b52tScenario($this);
    $piano = b52tPiano($this, $s);

    $p = b52tProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano])['pianoRate'];

    expect(collect($p)->only(['totale_spese', 'totale_entrate', 'totale_da_ripartire'])->all())->toEqual(['totale_spese' => 120000, 'totale_entrate' => 24000, 'totale_da_ripartire' => 96000]);
    expect($p)->not->toHaveKey('totale_capitoli');
    expect(collect($p['capitoli'])->mapWithKeys(fn ($c) => [$c['nome'] => $c['tipo'] ?? null])->all())->toBe(['Spese generali' => 'spesa', 'Proventi antenna' => 'entrata']);
});

it('T1.7b l\'elenco dei piani rate: gli stessi tre totali, senza il totale che sommava le entrate come spese', function () {
    $s = b52tScenario($this);
    b52tPiano($this, $s);

    $riga = b52tProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate'][0];

    expect(collect($riga)->only(['totale_spese', 'totale_entrate', 'totale_da_ripartire'])->all())->toEqual(['totale_spese' => 120000, 'totale_entrate' => 24000, 'totale_da_ripartire' => 96000]);
    expect($riga)->not->toHaveKey('totale_capitoli');
});

it('T1.7c un piano rate di sole spese: da ripartire è il preventivo di sempre, entrate zero', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPiano($this, $s);

    $p = b52tProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano])['pianoRate'];

    expect(collect($p)->only(['totale_spese', 'totale_entrate', 'totale_da_ripartire'])->all())->toEqual(['totale_spese' => 120000, 'totale_entrate' => 0, 'totale_da_ripartire' => 120000]);
});

it('T1.7d la pagina del piano dei conti: «Preventivo» sulle sole spese, e le entrate a parte', function () {
    $s = b52tScenario($this);

    $p = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect(collect($p)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire', 'totaleSopravvenienze', 'totaleConsuntivo'])->all())->toEqual([
        'totalePreventivo' => 120000, 'totaleEntrate' => 24000, 'totaleDaRipartire' => 96000, 'totaleSopravvenienze' => 0, 'totaleConsuntivo' => 0,
    ]);
});

it('T1.7e la pagina del piano dei conti con la sola entrata: «Preventivo» è zero, le entrate 24000 e da ripartire −24000', function () {
    $s = b52tScenario($this, soloEntrata: true);

    $p = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect(collect($p)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire'])->all())->toEqual(['totalePreventivo' => 0, 'totaleEntrate' => 24000, 'totaleDaRipartire' => -24000]);
});

it('T1.7h la pagina del piano dei conti con un capitolo di sottoconti misti: le voci si contano una per una, per tipo', function () {
    $s = b52tScenarioCapitoloMisto($this);

    $p = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect(collect($p)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire'])->all())->toEqual(['totalePreventivo' => 130000, 'totaleEntrate' => 50000, 'totaleDaRipartire' => 80000]);
});

it('T1.7f l\'elenco dei piani dei conti: il budget è delle sole spese, le entrate a parte, e le voci si contano per tipo', function () {
    $s = b52tScenario($this);

    $riga = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.index', [$s['c'], $s['e']])['pianiDeiConti'][0];

    expect(collect($riga)->only(['importo_totale', 'importo_entrate', 'capitoli_count', 'capitoli_entrate_count'])->all())->toEqual([
        'importo_totale' => 120000, 'importo_entrate' => 24000, 'capitoli_count' => 2, 'capitoli_entrate_count' => 1,
    ]);
});

it('T1.7g il selettore della creazione del piano manda il tipo di ogni voce, e il residuo resta positivo', function () {
    $s = b52tScenario($this);

    $r = $this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', $s['c']->id) . '?gestione_id=' . $s['g']->id . '&esercizio_id=' . $s['e']->id);
    $r->assertOk();

    expect(collect($r->json())->mapWithKeys(fn ($c) => [$c['nome'] => [$c['tipo'] ?? null, (float) $c['residuo']]])->all())->toBe([
        '[CAPITOLO] Spese generali' => ['spesa', 1200.0],
        '[CAPITOLO] Proventi antenna' => ['entrata', 240.0],
    ]);
    expect(Conto::where('piano_conto_id', $s['pc']->id)->pluck('tipo')->sort()->values()->all())->toBe(['entrata', 'spesa']);
});

it('T1.7i l\'elenco e la pagina del piano dei conti contano le entrate in valore assoluto, anche con un importo scritto negativo (dato di una versione vecchia)', function () {
    $s = b52tScenario($this);
    DB::table('conti')->where('id', $s['entrata']->id)->update(['importo' => -24000]);

    $riga = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.index', [$s['c'], $s['e']])['pianiDeiConti'][0];
    $pagina = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect(collect($riga)->only(['importo_totale', 'importo_entrate'])->all())->toEqual(['importo_totale' => 120000, 'importo_entrate' => 24000]);
    expect(collect($pagina)->only(['totalePreventivo', 'totaleEntrate', 'totaleDaRipartire'])->all())->toEqual(['totalePreventivo' => 120000, 'totaleEntrate' => 24000, 'totaleDaRipartire' => 96000]);
});

it('T1.7j il piano straordinario da fatture: spese e da ripartire sono la somma collegata, entrate zero, e l\'atteso del badge non cambia', function () {
    $s = b52tScenario($this, entrata: '0,00');
    $piano = b52tPianoStraordinarioDaFattura($s, 100000);

    $pagina = b52tProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano])['pianoRate'];
    $riga = collect(b52tProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate'])->firstWhere('id', $piano->id);

    expect(collect($pagina)->only(['totale_spese', 'totale_entrate', 'totale_da_ripartire'])->all())->toEqual(['totale_spese' => 100000, 'totale_entrate' => 0, 'totale_da_ripartire' => 100000]);
    expect(collect($riga)->only(['totale_spese', 'totale_entrate', 'totale_da_ripartire'])->all())->toEqual(['totale_spese' => 100000, 'totale_entrate' => 0, 'totale_da_ripartire' => 100000]);
    expect($pagina)->not->toHaveKey('totale_capitoli');
    expect(app(App\Services\PianoRateQuoteService::class)->totaleAttesoCents($piano->fresh()))->toBe(100000);
});

it('T1.7k un padre in pivot con una spesa e un\'entrata come sottoconti: la pagina del piano divide la pivot come il motore (spese 1.800,00, entrate 400,00, da ripartire 1.400,00)', function () {
    $s = b52tScenarioConPadreMisto($this);

    $props = b52tProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]);
    $p = $props['pianoRate'];
    $riga = collect(b52tProps($this, 'admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']])['pianiRate'])->firstWhere('id', $s['piano']->id);

    // Pulizia € 1.200,00 (spesa) più il padre da € 1.000,00, che il motore divide in € 600,00 di spesa e € 400,00 di entrata
    $attesi = ['totale_spese' => 180000, 'totale_entrate' => 40000, 'totale_da_ripartire' => 140000];
    expect(collect($p)->only(array_keys($attesi))->all())->toEqual($attesi);
    expect(collect($riga)->only(array_keys($attesi))->all())->toEqual($attesi);
    expect(b52tQuoteDelMotore($s['piano']))->toBe($attesi['totale_da_ripartire']);
    expect($props['disallineato'])->toBeFalse();
});

it('T1.7l il piano dei conti con una spesa scritta negativa (dato di una versione vecchia): la spesa conta in valore assoluto (240,00), come la addebita il motore', function () {
    // Come il piano rate e l\'atteso (T1.5e in `Beta52TotaliBadgeTest`): il motore addebita la spesa negativa in positivo, e un totale che la sommasse col
    // segno direbbe un preventivo diverso da quello che le quote chiedono.
    $s = b52tScenario($this);
    DB::table('conti')->where('id', $s['spesa']->id)->update(['importo' => -24000]);

    $riga = b52tProps($this, 'admin.gestionale.esercizi.piani-conti.index', [$s['c'], $s['e']])['pianiDeiConti'][0];

    expect(collect($riga)->only(['importo_totale', 'importo_entrate'])->all())->toEqual(['importo_totale' => 24000, 'importo_entrate' => 24000]);
});

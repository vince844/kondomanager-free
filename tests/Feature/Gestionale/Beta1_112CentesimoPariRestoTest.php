<?php

/**
 * Coda 301 (decisione 183.3), dalle rotte: a pari resto il centesimo va a chi è titolare da prima, poi a chi è registrato prima, e i resti
 * si confrontano in aritmetica intera.
 *
 * Il piano nasce dalla rotta di creazione con «genera subito», come dalla pagina, su un'unità sola che ha tutti i millesimi della tabella
 * «Proprietà»; la voce dal modale della nuova voce, al 100 % al proprietario. Il riparto principale passa da
 * `MoneyHelper::distribuisciPesiNormalizzati`, il saldo solidale dell'unità da `MoneyHelper::ripartisciPerQuote`
 * (`GenerateSaldiAction`). L'ordine dei titolari lo dà `Immobile::anagrafiche`.
 *
 * Ogni cifra viene dalla regola (le parti intere di importo × quota / 100, l'avanzo ai resti più grandi, a pari resto al primo), scritta
 * accanto all'atteso.
 *
 * Cosa resta scoperto: su sqlite la lettura della pivot senza ordine seguiva già l'indice per tipologia e data d'inizio, quindi fra
 * titolari dello stesso ruolo l'ordine di prima coincide con quello nuovo; su MySQL l'ordine senza `orderBy` non è provato. I lettori
 * che leggono la pivot da sé (`GenerateSaldiAction::risolviTitolari`, i passaggi) non passano da `Immobile::anagrafiche`.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Services\CondominioService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/**
 * Un condominio con l'unità A, sola sulla tabella «Proprietà» (1000 millesimi), e i suoi proprietari nell'ordine in cui si registrano:
 * `$titolari` è una lista di [nome, quota, data d'inizio]. La voce «Spese generali» di `$importo` dal modale.
 */
function b112cScenario($test, array $titolari, string $importo): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    $unita = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "B112C-{$s['seq']}-A", 'nome' => 'Interno A', 'interno' => 'A']);
    DB::table('quote_tabella')->insert(['tabella_id' => $s['tabella']->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($titolari as $i => [$nome, $quota, $dal]) {
        $persona = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'b112c-' . strtolower($nome) . "{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(str_pad(strtolower($nome), 6, 'x')) . str_pad((string) ($s['seq'] * 10 + $i), 10, '0', STR_PAD_LEFT)]);
        $persona->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => $quota,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        $s['persone'][$nome] = $persona;
    }
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $s['unita']['A'] = $unita;
    $s['spesa'] = b52tVoce($test, $s, 'Spese generali', 'spesa', $importo, $s['tabella']);

    return $s;
}

/** «Ricalcola» dalla rotta della pagina del piano. */
function b112cRicalcola($test, array $s, $piano): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano->fresh()]), [
        'orphan_ids' => [], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ])->assertSessionHasNoErrors();
}

it('Coda 301 — tre proprietari al 10, 30 e 60 % e una voce di € 1.000,04: Anna € 100,01, Bruno € 300,01, Carla € 600,02, e «Ricalcola» non le sposta', function () {
    $s = b112cScenario($this, [['Anna', 10, '2019-01-01'], ['Bruno', 30, '2019-01-01'], ['Carla', 60, '2019-01-01']], '1.000,04');
    $piano = b52tPiano($this, $s);

    // 100004 × 10/100 = 10000,4; × 30/100 = 30001,2; × 60/100 = 60002,4. Parti intere 100003, ne avanza 1: fra Anna e Carla, pari a 0,4,
    // ad Anna, che è registrata prima (stessa data d'inizio).
    $attese = ['Anna' => 10001, 'Bruno' => 30001, 'Carla' => 60002];
    expect(b52tSommeQuote($piano))->toBe($attese);

    b112cRicalcola($this, $s, $piano);
    expect(b52tSommeQuote($piano->fresh()))->toBe($attese);
});

it('Coda 301 — il saldo solidale di € 100,06 dell\'unità diviso fra i tre proprietari: Anna € 10,01, Bruno € 30,02, Carla € 60,03', function () {
    $s = b112cScenario($this, [['Anna', 10, '2019-01-01'], ['Bruno', 30, '2019-01-01'], ['Carla', 60, '2019-01-01']], '1.200,00');
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 10006, 'origine' => 'manuale', 'is_applicato' => false]);
    $piano = b52tPiano($this, $s);

    // La voce: 120000 × 10/100 = 12000, × 30/100 = 36000, × 60/100 = 72000, senza resti. Il saldo: 10006 × 10/100 = 1000,6; × 30/100 =
    // 3001,8; × 60/100 = 6003,6. Parti intere 10004, ne avanzano 2: il primo a Bruno (resto 0,8), il secondo fra Anna e Carla, pari a
    // 0,6, ad Anna, registrata prima.
    expect(b52tSommeQuote($piano))->toBe(['Anna' => 12000 + 1001, 'Bruno' => 36000 + 3002, 'Carla' => 72000 + 6003]);
});

it('Coda 301 — due proprietari al 50 % e € 1.000,01: il centesimo a chi è titolare da prima, anche se registrato dopo', function () {
    // Dora registrata per prima, proprietaria dal 2022; Anna registrata dopo, proprietaria dal 2019.
    $s = b112cScenario($this, [['Dora', 50, '2022-01-01'], ['Anna', 50, '2019-01-01']], '1.000,01');
    $piano = b52tPiano($this, $s);

    // 100001 × 50/100 = 50000,5 a testa: pari resto, il centesimo ad Anna, titolare da prima.
    expect(b52tSommeQuote($piano))->toBe(['Anna' => 50001, 'Dora' => 50000]);
});

it('Coda 301 — due proprietari al 50 % dallo stesso giorno e € 1.000,01: il centesimo a chi è registrato prima', function () {
    $s = b112cScenario($this, [['Dora', 50, '2019-01-01'], ['Anna', 50, '2019-01-01']], '1.000,01');
    $piano = b52tPiano($this, $s);

    // 50000,5 a testa: stessa data d'inizio, il centesimo a Dora, registrata per prima.
    expect(b52tSommeQuote($piano))->toBe(['Anna' => 50000, 'Dora' => 50001]);
});

it('Coda 301 — le righe di titolarità di un\'unità si leggono da chi è titolare da prima, qualunque sia il ruolo, poi da chi è registrato prima', function () {
    $s = b112cScenario($this, [['Pia', 50, '2019-01-01'], ['Olga', 50, '2017-03-01']], '100,00');
    $unita = $s['unita']['A'];
    $ivo = Anagrafica::forceCreate(['nome' => 'Ivo', 'email' => "b112c-ivo{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'IVOXXX' . str_pad((string) $s['seq'], 10, '9', STR_PAD_LEFT)]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ivo->id, 'immobile_id' => $unita->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2018-06-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    // Una seconda riga di Pia dallo stesso giorno della prima, registrata dopo Ivo.
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Pia']->id, 'immobile_id' => $unita->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'attivo' => false, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $attesi = [['Olga', 'proprietario'], ['Ivo', 'inquilino'], ['Pia', 'proprietario'], ['Pia', 'inquilino']];
    $leggi = fn ($titolari) => $titolari->map(fn ($a) => [$a->nome, $a->pivot->tipologia])->all();

    expect($leggi($unita->fresh()->anagrafiche))->toBe($attesi)
        // Il motore le carica con il resto delle unità (`with`), e l'ordine resta quello.
        ->and($leggi(Immobile::with('anagrafiche')->whereIn('id', [$unita->id])->get()->first()->anagrafiche))->toBe($attesi)
        ->and($unita->anagrafiche()->toSql())->toContain('order by "anagrafica_immobile"."data_inizio" asc, "anagrafica_immobile"."id" asc');
});

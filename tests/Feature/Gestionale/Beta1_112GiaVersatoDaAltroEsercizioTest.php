<?php

/**
 * Coda 438 — il già versato per esercizio, dalle pagine che non sono quella dell'esercizio del piano.
 *
 * Decisione 183.1: il già versato vale nei piani del suo esercizio (vuoto: in tutti i piani della voce); l'esercizio di un piano è quello con cui è
 * generato. Tre punti dove l'esercizio del piano, o quello che la pagina ne stima, dipendono da dove si parte:
 * - l'elenco dei piani rate di un esercizio mostra anche i piani delle gestioni agganciate che sono di un altro esercizio (la gestione usata in due anni):
 *   «Ricalcola» e «togli la voce» da lì non devono spostare il piano nell'esercizio della pagina, perché con lui cambierebbe il già versato che il piano toglie;
 * - la stima del modale dello sforo (la pagina della fattura) toglie il già versato che vale nell'esercizio della fattura: il server manda anche quello per esercizio;
 * - decisione 187: la migrazione non dà l'esercizio alle righe già salvate, e una gestione straordinaria con un esercizio solo che continua l'anno dopo
 *   toglie il già versato in tutti e due i piani, come prima.
 *
 * Scenario: Ugo (A) e Bice (C) a 500 millesimi; «Spese generali» € 1.200,00 e «Pulizie» € 200,00; € 300,00 già versati da Ugo per il 2026. Le cifre si rifanno
 * dalla regola accanto a ogni test: ogni piano chiede l'importo pieno delle voci ripartito a metà e toglie a Ugo il già versato che vale nel suo esercizio.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $this->user = be52Utente();
    b52tSqliteJson();
});

/** Il condominio con il 2026 e (se `$conIl2027`) il 2027, la gestione ordinaria agganciata a tutti e due come l'importatore. */
function g112eScenario($test, bool $conIl2027 = true, string $spesa = '1.200,00'): array
{
    static $seq = 700;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $es = [];
    $es['2026'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $es['2026']);
    if ($conIl2027) {
        $es['2027'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
        app(CondominioService::class)->createDefaultGestione($c, $es['2027']);
    }
    $s = ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $es['2026'], 'es' => $es, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', $spesa);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');

    return $s;
}

/** Il già versato di Ugo su una voce dalla pagina: `$valePer` l'anno o `tutti`. */
function g112eGiaVersato($test, array $s, string $voce, string $valePer, int $cents)
{
    return $test->actingAs($test->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s['c'], $s[$voce]]), [
        'natura' => 'fondo_vincolato',
        'vale_per' => $valePer === 'tutti' ? 'tutti' : $s['es'][$valePer]->id,
        'righe' => [
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => $cents],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => $s['persone']['bice']->id, 'gia_versato' => 0],
        ],
        'descrizione' => 'Accantonamento di prima',
        'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'cassa_id' => null,
    ]);
}

/** Crea un piano dalla pagina dell'esercizio `$anno` (genera subito), con le voci indicate: nome della voce => importo. */
function g112eCrea($test, array $s, string $anno, string $nome, ?array $capitoli, array $extra = [])
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 1,
    ] + $extra + ($capitoli !== null ? ['capitoli_config' => collect($capitoli)->map(fn ($euro, $voce) => ['id' => $s[$voce]->id, 'importo' => $euro])->values()->all()] : []));
}

/** Quanto chiede il piano a ognuno, in centesimi: persona => totale delle sue quote. */
function g112eChiesto(array $s, string $nome): array
{
    $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', $nome)->first();
    if ($piano === null) {
        return ['piano' => 'non c\'è'];
    }

    return collect($s['persone'])->map(fn ($a) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo'))->all();
}

function g112eErrore($r): ?string
{
    $m = $r->getSession()->get('message');

    return ($m['type'] ?? null) === 'error' ? ($m['message'] ?? null) : null;
}

// --- Il ricalcolo dalla pagina dell'altro esercizio ------------------------------------------------------------------------------------

it('Coda 438 — il piano del 2026 aperto dall\'elenco del 2027 e ricalcolato da lì resta del 2026, e toglie a Ugo i suoi € 300,00', function () {
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112eErrore(g112eCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112eErrore(g112eCrea($this, $s, '2027', 'P27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = PianoRate::where('nome', 'P26')->first();
    // L'elenco dei piani del 2027 mostra anche P26 (la gestione è agganciata al 2027) e lo collega con l'esercizio del 2027.
    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['es']['2027']]));
    expect(collect($elenco->viewData('page')['props']['pianiRate'])->pluck('nome')->all())->toContain('P26');

    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2027'], $p26]));
    // A mano (decisione 183.1): P26 è il piano del 2026, e il già versato del 2026 vale lì: Ugo 600 − 300 + 100 = 400, Bice 600 + 100 = 700.
    expect(g112eErrore($r))->toBeNull()
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112eChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112eChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 438 — il piano del 2027 aperto dall\'elenco del 2026 e ricalcolato da lì resta del 2027 e si ricalcola (700 e 700), senza il rifiuto della voce superata', function () {
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112eErrore(g112eCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    expect(g112eErrore(g112eCrea($this, $s, '2027', 'P27', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p27 = PianoRate::where('nome', 'P27')->first();
    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2026'], $p27]));
    // A mano: il già versato del 2026 non vale nel 2027: 600 + 100 = 700 a testa.
    expect(g112eErrore($r))->toBeNull()
        ->and(PianoRate::find($p27->id)->esercizio_id)->toBe($s['es']['2027']->id)
        ->and(g112eChiesto($s, 'P27'))->toBe(['ugo' => 70000, 'bice' => 70000]);
});

it('Coda 438 — «togli la voce» sul piano del 2026 dalla pagina del 2027: il piano resta del 2026 e toglie ancora il già versato', function () {
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112eErrore(g112eCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = PianoRate::where('nome', 'P26')->first();
    $r = $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['es']['2027'], $p26, $s['pulizie']]));
    // A mano: senza «Pulizie», Ugo 600 − 300 = 300, Bice 600.
    expect(g112eErrore($r))->toBeNull()
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(g112eChiesto($s, 'P26'))->toBe(['ugo' => 30000, 'bice' => 60000]);
});

it('Coda 438 — un piano senza esercizio (generato prima che il piano lo ricordasse) sulla gestione usata in due esercizi non si ricalcola dalla pagina del 2027: resta com\'era', function () {
    // Decisione 192.1: prima il ricalcolo gli dava l'esercizio della pagina (il 2027) e il già versato del 2026 non si toglieva più; ora si rifiuta, e
    // la strada (eliminarlo e crearlo dalla pagina del suo esercizio) la provano le prove di `Beta1_112GiaVersatoPianiDiPrimaTest`.
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    expect(g112eErrore(g112eCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = PianoRate::where('nome', 'P26')->first();
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null]);

    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2027'], $p26]));
    // A mano: com'era, generato dalla pagina del 2026: Ugo 600 − 300 + 100 = 400, Bice 700; l'esercizio resta nullo.
    expect(g112eErrore($r))->toStartWith('Impossibile ricalcolare: il piano rate «P26» non ha il suo esercizio salvato')
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBeNull()
        ->and(g112eChiesto($s, 'P26'))->toBe(['ugo' => 40000, 'bice' => 70000]);
});

it('Coda 438 — un piano non può ricordare un esercizio che non esiste (la chiave esterna lo impedisce): alla generazione l\'esercizio del piano si trova sempre', function () {
    $s = g112eScenario($this);
    expect(g112eErrore(g112eCrea($this, $s, '2026', 'P26', ['spesa' => '1.200,00', 'pulizie' => '200,00'])))->toBeNull();
    $p26 = PianoRate::where('nome', 'P26')->first();

    expect(fn () => DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => 999999]))->toThrow(\Illuminate\Database\QueryException::class)
        ->and(PianoRate::find($p26->id)->esercizio_id)->toBe($s['es']['2026']->id);
});

// --- La stima dello sforo nella pagina della fattura ----------------------------------------------------------------------------------

it('Coda 438 — la pagina della fattura manda il già versato della voce per esercizio, e «tutti» per quello senza esercizio', function () {
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    g112eGiaVersato($this, $s, 'pulizie', 'tutti', 10000)->assertSessionHasNoErrors();
    $conti = collect($this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', [$s['c']]))->assertOk()->viewData('page')['props']['conti'])->keyBy('id');

    expect((array) $conti[$s['spesa']->id]['gia_versato_per_esercizio'])->toBe([(string) $s['es']['2026']->id => 30000])
        ->and((array) $conti[$s['pulizie']->id]['gia_versato_per_esercizio'])->toBe(['tutti' => 10000])
        ->and($conti[$s['spesa']->id]['gia_versato_cents'])->toBe(30000);
});

it('Coda 438 — la voce con una riga del 2026 e una senza esercizio (scritta fuori dalla pagina): la pagina della fattura manda le due somme separate, e il totale resta la somma', function () {
    $s = g112eScenario($this);
    g112eGiaVersato($this, $s, 'spesa', '2026', 30000)->assertSessionHasNoErrors();
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita']['C']->id,
        'anagrafica_id' => $s['persone']['bice']->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione']);
    $conti = collect($this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', [$s['c']]))->assertOk()->viewData('page')['props']['conti'])->keyBy('id');

    expect((array) $conti[$s['spesa']->id]['gia_versato_per_esercizio'])->toEqual([(string) $s['es']['2026']->id => 30000, 'tutti' => 10000])
        ->and($conti[$s['spesa']->id]['gia_versato_cents'])->toBe(40000)
        ->and((array) $conti[$s['pulizie']->id]['gia_versato_per_esercizio'])->toBe([]);
});

// --- La gestione che continua nell'anno dopo, dopo la migrazione -----------------------------------------------------------------------

/**
 * Un fondo da € 2.000,00 su una gestione con il solo 2026 al momento dell'aggiornamento; il già versato di Ugo (€ 300,00) salvato prima della beta, senza
 * esercizio. La migrazione gira, poi la gestione si porta nel 2027 da «Modifica gestione» e si chiedono l'acconto del 2026 e il saldo del 2027.
 */
function g112eFondoSuDueAnni($test, string $tipoGestione): array
{
    $s = g112eScenario($test, false, '2.000,00');
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => $tipoGestione]);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita']['A']->id,
        'anagrafica_id' => $s['persone']['ugo']->id, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione']);
    $migration = require database_path('migrations/2026_11_04_100000_add_esercizio_id_to_contributi_versati_table.php');
    $migration->up();
    $s['esercizio_dopo_la_migrazione'] = DB::table('contributi_versati')->where('target_id', $s['spesa']->id)->value('esercizio_id');

    $s['es']['2026']->update(['stato' => 'chiuso']);
    $s['es']['2027'] = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.gestioni.update', [$s['c'], $s['es']['2026'], $s['g']]), [
        'nome' => $s['g']->nome, 'descrizione' => 'Fondo', 'data_inizio' => '2026-01-01', 'data_fine' => '2027-12-31', 'tipo' => $tipoGestione,
    ])->assertSessionHasNoErrors();
    $s['es']['2026']->update(['stato' => 'aperto']);
    expect(DB::table('esercizio_gestione')->where('gestione_id', $s['g']->id)->count())->toBe(2);

    $extra = $tipoGestione === 'straordinaria' ? ['data_delibera_assemblea' => '2026-01-10'] : [];
    expect(g112eErrore(g112eCrea($test, $s, '2026', 'Acconto', ['spesa' => '1.000,00'], $extra)))->toBeNull();
    expect(g112eErrore(g112eCrea($test, $s, '2027', 'Saldo', ['spesa' => '1.000,00'], $extra)))->toBeNull();

    return $s;
}

it('Coda 438, decisione 187 — il fondo di € 2.000,00 su una gestione con il solo 2026 che continua nel 2027: la migrazione non dà l\'esercizio al già versato, e acconto e saldo tolgono € 150,00 ciascuno', function (string $tipo) {
    $s = g112eFondoSuDueAnni($this, $tipo);
    // A mano: vuoto = tutti i piani della voce; quota 1.000 / 2.000 = 0,5, floor(300 × 0,5) = 150: Ugo 500 − 150 = 350 in tutti e due, in tutto 700 = 1.000 − 300.
    // Con l'esercizio dato per regola (2026) il saldo avrebbe chiesto 500: in tutto 850, € 150,00 in più del dovuto.
    expect($s['esercizio_dopo_la_migrazione'])->toBeNull()
        ->and(g112eChiesto($s, 'Acconto'))->toBe(['ugo' => 35000, 'bice' => 50000])
        ->and(g112eChiesto($s, 'Saldo'))->toBe(['ugo' => 35000, 'bice' => 50000]);
})->with(['straordinaria' => ['straordinaria'], 'ordinaria' => ['ordinaria']]);

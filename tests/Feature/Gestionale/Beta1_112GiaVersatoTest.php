<?php

/**
 * Coda 438 — il già versato sulla gestione usata in più esercizi.
 *
 * Decisione 183.1: un esercizio sul già versato, scelto dall'amministratore; vuoto vuol dire tutti i piani della voce (il fondo lavori su più anni).
 * Con Ugo e Bice a 500 millesimi, voci € 1.200,00 e € 200,00, € 300,00 già versati da Ugo per il 2026: piano 2026 Ugo € 400,00 e Bice € 700,00, piano
 * 2027 € 700,00 e € 700,00. Decisione 183.2: il piano creato senza scegliere le voci prende tutte le voci che non sono già in un piano dello stesso
 * esercizio (con «Varie» € 300,00: Ugo e Bice € 850,00 ciascuno). Il perimetro: il motore (la guardia del già versato, le voci impegnate, il netting), le
 * tre letture del già versato, il selettore delle voci, la copertura, l'adeguamento della voce alla registrazione di una fattura con la rata
 * integrativa, la pagina del già versato.
 *
 * Le cifre si rifanno dalla regola accanto a ogni test: ogni piano chiede l'importo pieno delle voci ripartito a metà, e toglie a Ugo il già versato
 * che vale per il suo esercizio (quello indicato, o tutti se non è indicato).
 *
 * Lo scenario si costruisce in tre modi: come l'importatore, con gli esercizi creati nei due ordini e la stessa gestione agganciata a tutti e due
 * (`CondominioService::createDefaultGestione`), e come l'amministratore, con la gestione del 2026 portata nel 2027 da «Modifica gestione».
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Tabella;
use App\Services\CondominioService;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Aprile 2027: il 2027 c'è già, e il piano del 2026 si crea o si ricalcola adesso.
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $this->user = be52Utente();
    b52tSqliteJson();
});

const G112_STRADE = [
    'importati il 2026 e poi il 2027' => ['importati', ['2026', '2027']],
    'importati il 2027 e poi il 2026' => ['importati', ['2027', '2026']],
    'creata nel 2026 e portata nel 2027 da «Modifica gestione»' => ['modifica', ['2026', '2027']],
];

/**
 * Un condominio con il 2026 e il 2027 e la gestione ordinaria agganciata a tutti e due. Ugo (A) e Bice (C) a 500 millesimi; «Spese generali»
 * € 1.200,00 e «Pulizie» € 200,00, create dalla rotta.
 */
function g112Scenario($test, string $strada, array $ordine): array
{
    static $seq = 400;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    $es = [];
    if ($strada === 'importati') {
        // Come `LivelloEsercizi::commit`: l'esercizio nasce aperto, poi la gestione ordinaria (la stessa, riusata) gli si aggancia.
        foreach ($ordine as $anno) {
            $es[$anno] = Esercizio::create(['condominio_id' => $c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
            $g = app(CondominioService::class)->createDefaultGestione($c, $es[$anno]);
        }
    } else {
        $es['2026'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
        $g = app(CondominioService::class)->createDefaultGestione($c, $es['2026']);
    }

    $s = ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $es['2026'], 'es' => $es, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');

    if ($strada === 'modifica') {
        // Il 2026 si chiude, il 2027 si apre, e la gestione si porta nel 2027 dalla sua pagina nel 2026, lasciando le date com'erano.
        $es['2026']->update(['stato' => 'chiuso']);
        $s['es']['2027'] = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
        $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.gestioni.update', [$c, $es['2026'], $g]), [
            'nome' => $g->nome, 'descrizione' => 'Gestione ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'tipo' => 'ordinaria',
        ])->assertSessionHasNoErrors();
        // Il 2026 si riapre per finire il consuntivo: le sue fatture si registrano solo in un esercizio aperto.
        $es['2026']->update(['stato' => 'aperto']);
    }
    expect(DB::table('esercizio_gestione')->where('gestione_id', $g->id)->count())->toBe(2);

    return $s;
}

/** Il già versato di Ugo su «Spese generali», dalla pagina del già versato: `$valePer` è l'anno, o `tutti`. */
function g112GiaVersato($test, array $s, string $valePer, int $cents = 30000)
{
    return $test->actingAs($test->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]), [
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

/**
 * Un piano ordinario dell'anno dalla rotta di creazione con «genera subito». Con `$capitoli` le voci scelte e il loro importo, come le manda la
 * pagina (`capitoli_config`); con `null` nessuna voce scelta, come la pagina con il campo vuoto.
 *
 * @param array<string, string>|null $capitoli chiave dello scenario della voce => importo nel piano, in euro come lo scrive il modulo
 */
function g112Crea($test, array $s, string $anno, string $nome, ?array $capitoli = ['spesa' => '1.200,00', 'pulizie' => '200,00'])
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 1,
    ] + ($capitoli !== null ? ['capitoli_config' => collect($capitoli)->map(fn ($euro, $voce) => ['id' => $s[$voce]->id, 'importo' => $euro])->values()->all()] : []));
}

function g112Piano(array $s, string $nome): ?PianoRate
{
    return PianoRate::where('condominio_id', $s['c']->id)->where('nome', $nome)->first();
}

/** Il messaggio d'errore della rotta (il flash della pagina), o null. */
function g112Errore($r): ?string
{
    $m = $r->getSession()->get('message');

    return ($m['type'] ?? null) === 'error' ? ($m['message'] ?? null) : null;
}

/** Quanto il piano chiede a ognuno, sommando tutte le rate. */
function g112Chiesto(?PianoRate $piano, array $s): array
{
    if ($piano === null) {
        return ['piano' => 'non c\'è'];
    }

    return collect($s['persone'])->map(fn ($a) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo'))->all();
}

function g112Ricalcola($test, array $s, PianoRate $piano, string $anno)
{
    return $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es'][$anno], $piano]));
}

/** Una fattura reale dell'esercizio, con una riga sulla voce, dalla rotta delle fatture; con `$sforo` la motivazione e la rata integrativa. */
function g112Fattura($test, array $s, string $anno, float $euro, bool $sforo = false): void
{
    \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $extra = ['esercizio_id' => $s['es'][$anno]->id, 'data_documento' => "{$anno}-03-10", 'data_scadenza' => "{$anno}-04-10"];
    if ($sforo) {
        $extra['dati_extra'] = ['fiscal' => [], 'competenza' => null, 'override_budget' => [
            'motivazione' => 'Sforamento della voce di prova', 'importo_sforo' => (int) round($euro * 100) - (int) Conto::find($s['spesa']->id)->importo, 'strategia_rientro' => 'rata_integrativa',
        ]];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $s['spesa']->id, $euro, $extra, "FT-{$anno}-".(int) round($euro * 100)))
        ->assertSessionHasNoErrors();
}

// --- Decisione 183.1: il già versato vale nei piani del suo esercizio -----------------------------------------------------------

it('Coda 438 — il già versato indicato per il 2026: il piano del 2026 lo toglie a Ugo e quello del 2027 sulla stessa gestione no (Ugo € 400,00 e Bice € 700,00, poi € 700,00 e € 700,00)', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();

    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    $r27 = g112Crea($this, $s, '2027', 'Preventivo 2027');

    // Dalla regola: ogni piano chiede € 1.200,00 + € 200,00, a metà € 700,00 a testa; nel 2026 a Ugo si tolgono i € 300,00 versati per il 2026 (€ 400,00),
    // nel 2027 no (decisione 183.1).
    expect(g112Errore($r27))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438 — il piano del 2027 generato prima di quello del 2026, con il già versato del 2026: stesse cifre', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();

    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438 — il già versato indicato per il 2027: lo toglie il piano del 2027 (Ugo € 400,00) e non quello del 2026 (Ugo € 700,00)', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2027')->assertSessionHasNoErrors();

    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438 — il già versato indicato per il 2026 dopo che il piano del 2027 lo aveva tolto: il ricalcolo del 2027 lo restituisce (Ugo € 700,00)', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    // Prima senza esercizio, come le righe di prima: il piano del 2027 (il solo) lo toglie.
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);

    // Poi l'amministratore indica il 2026: il piano del 2027 risulta disallineato (l'atteso non toglie più il già versato, € 1.400,00 contro € 1.100,00
    // nelle rate), e il ricalcolo chiede a Ugo l'intera metà, € 700,00.
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(app(PianoRateQuoteService::class)->eDisallineato(g112Piano($s, 'Preventivo 2027')))->toBeTrue()
        ->and(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Preventivo 2027'), '2027')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000])
        ->and(app(PianoRateQuoteService::class)->eDisallineato(g112Piano($s, 'Preventivo 2027')))->toBeFalse();
})->with(G112_STRADE);

it('Coda 438 — il già versato senza esercizio vale in tutti i piani della voce: il piano del 2027 sulla stessa voce si ferma, e il messaggio dice dove si indica l\'esercizio', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    // Dalla regola: «vuoto vuol dire tutti i piani della voce», e i due piani insieme chiedono € 2.400,00 sulla voce da € 1.200,00.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato che vale per tutti gli esercizi, ed è già nel piano «Preventivo 2026» dell\'esercizio «Esercizio 2026», attivo sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 2.400,00) supera il suo budget (€ 1.200,00), e il già versato verrebbe tolto in tutti e due. Se il già versato è di un esercizio solo, indicalo nella pagina «Già versato» della voce: varrà solo nei piani di quell\'esercizio.')
        ->and(g112Piano($s, 'Preventivo 2027'))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(G112_STRADE);

// --- Decisione 183.2 (P2): il piano senza voci scelte prende le voci libere nel suo esercizio --------------------------------------

it('Coda 438, P2 — il piano del 2027 creato senza scegliere le voci, con «Varie» € 300,00 accanto alle voci del piano del 2026 e il già versato del 2026: Ugo e Bice € 850,00', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    $s['varie'] = be52Voce($this, $s, $s['pc'], 'Varie', 'spesa', '300,00');

    // Dalla decisione 183.2: (€ 1.200,00 + € 200,00 + € 300,00) × 500/1000 = € 850,00 a testa; il già versato del 2026 non vale nel 2027.
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 85000, 'bice' => 85000])
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438, P2 — senza già versato: il piano del 2027 senza voci scelte prende le tre voci, € 850,00 a testa', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    $s['varie'] = be52Voce($this, $s, $s['pc'], 'Varie', 'spesa', '300,00');

    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 85000, 'bice' => 85000])
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', g112Piano($s, 'Preventivo 2027')->id)->orderBy('conto_id')->pluck('conto_id')->map(fn ($v) => (int) $v)->all())
        ->toBe([$s['spesa']->id, $s['pulizie']->id, $s['varie']->id]);
})->with(G112_STRADE);

it('Coda 438, P2 — con il già versato senza esercizio il piano del 2027 senza voci scelte si ferma sulla voce, con il messaggio della pagina del già versato', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    // Il già versato vale anche nel 2027: con le due voci del 2026 nel piano nuovo la somma sulla voce è € 2.400,00 (non € 550,00 a Ugo: sarebbe tolto due volte).
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toContain('vale per tutti gli esercizi')
        ->and(g112Piano($s, 'Preventivo 2027'))->toBeNull();
})->with(G112_STRADE);

it('Coda 438, AD3 — un integrativo senza voci scelte quando tutte le voci sono nel piano dello stesso esercizio: il rifiuto dice la causa, non le tre verifiche', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    expect(g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nel piano «Preventivo 2026», attivo nello stesso esercizio, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta nessuna voce da chiedere.')
        ->and(g112Piano($s, 'Integrativo 2026'))->toBeNull();
})->with(G112_STRADE);

it('Coda 438, AD3 — le voci in due piani dello stesso esercizio, una per piano: il rifiuto li nomina tutti e due', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2027', 'Spese 2027', ['spesa' => '1.200,00'])))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Pulizie 2027', ['pulizie' => '200,00'])))->toBeNull();

    expect(g112Errore(g112Crea($this, $s, '2027', 'Integrativo 2027', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nei piani «Spese 2027» e «Pulizie 2027», attivi nello stesso esercizio, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo dello stesso esercizio ha già: non resta nessuna voce da chiedere.');
})->with(G112_STRADE);

// --- AD2: il messaggio della guardia non propone di disattivare un piano ---------------------------------------------------------

it('Coda 438, AD2 — il già versato e due piani dello stesso esercizio che insieme superano la voce: il messaggio indica le strade che le pagine offrono, non «disattiva»', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    // Dalla regola: € 1.200,00 + € 1.200,00 = € 2.400,00 sulla voce da € 1.200,00, tutti e due del 2026.
    $messaggio = g112Errore(g112Crea($this, $s, '2026', 'Integrativo 2026', ['spesa' => '1.200,00']));
    expect($messaggio)->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato, ma la somma degli importi richiesti sulla voce dai piani rate attivi che hanno quel già versato (€ 2.400,00, con il piano «Preventivo 2026») supera il suo budget (€ 1.200,00): il già versato verrebbe tolto più volte e parte della spesa non verrebbe mai richiesta ai condòmini. Aggiorna prima l\'importo della voce al fabbisogno reale, oppure chiedi la voce con un importo più basso, o toglila dalla pagina di un piano che ha anche altre voci.')
        ->and($messaggio)->not->toContain('disattiva');
})->with(G112_STRADE);

it('Coda 438 — il già versato del 2026 e un piano del 2027 sulla voce: un integrativo del 2026 che sta nella voce si genera, il 2027 non conta', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Acconto 2026', ['spesa' => '600,00'])))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2026', 'Saldo 2026', ['spesa' => '600,00'])))->toBeNull();

    // Dalla regola: nel 2026 € 600,00 + € 600,00 = € 1.200,00, la voce; ognuno chiede metà voce e toglie metà del già versato (€ 150,00): Ugo € 300,00 −
    // € 150,00 = € 150,00 e Bice € 300,00 in ognuno. Il piano del 2027 non lo toglie: € 700,00 a testa.
    expect(g112Chiesto(g112Piano($s, 'Acconto 2026'), $s))->toBe(['ugo' => 15000, 'bice' => 30000])
        ->and(g112Chiesto(g112Piano($s, 'Saldo 2026'), $s))->toBe(['ugo' => 15000, 'bice' => 30000])
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(G112_STRADE);

// --- PA-1 e l'adeguamento della voce alla fattura: le fatture dell'esercizio del già versato ---------------------------------------

it('Coda 438, PA-1 — il già versato del 2026 e fatture di € 1.000,00 nel 2026 e nel 2027: il ricalcolo del 2026 dà Ugo € 400,00 e Bice € 700,00, e il piano del 2027 € 700,00 a testa', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    g112Fattura($this, $s, '2026', 1000.00);
    g112Fattura($this, $s, '2027', 1000.00);

    // Dalla regola: le fatture del 2027 non toccano il già versato del 2026; ognuna sta nella voce da € 1.200,00.
    expect(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Preventivo 2026'), '2026')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438, PA-1 — il già versato senza esercizio e le fatture di due esercizi che insieme superano la voce: il ricalcolo si ferma, e il messaggio dice dove si indica l\'esercizio', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    g112Fattura($this, $s, '2026', 1000.00);
    g112Fattura($this, $s, '2027', 700.00);

    expect(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Preventivo 2026'), '2026')))
        ->toBe('Errore durante il ricalcolo: Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato che vale per tutti gli esercizi, e le fatture reali registrate su questa voce sono di più esercizi: «Esercizio 2026» (€ 1.000,00) e «Esercizio 2027» (€ 700,00). La loro somma (€ 1.700,00) supera il budget della voce (€ 1.200,00). Se il già versato è di un esercizio solo, indicalo nella pagina «Già versato» della voce: conteranno solo le fatture di quell\'esercizio.')
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438 — la fattura del 2027 con lo sforo a rata integrativa, sulla voce col già versato del 2026: la voce non si alza, e il ricalcolo del 2026 toglie a Ugo tutti i € 300,00', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    g112Fattura($this, $s, '2027', 1500.00, true);

    // Il già versato del 2026 non vale nel 2027: la voce resta € 1.200,00, e il piano del 2026 (€ 1.200,00) ne è l'intero, quota 1.
    // Ugo € 600,00 − € 300,00 + € 100,00 = € 400,00 (con la voce a € 1.500,00 la quota sarebbe 0,8 e Ugo € 460,00).
    expect((int) Conto::find($s['spesa']->id)->importo)->toBe(120000)
        ->and(g112Errore(g112Ricalcola($this, $s, g112Piano($s, 'Preventivo 2026'), '2026')))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2026'), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(G112_STRADE);

it('Coda 438 — la fattura del 2026 con lo sforo a rata integrativa porta la voce alle sole fatture del 2026, non a quelle di tutti gli esercizi', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    g112Fattura($this, $s, '2027', 1000.00);
    g112Fattura($this, $s, '2026', 1500.00, true);

    // Dalla regola: le fatture dell'esercizio del già versato, € 1.500,00 (con il 2027 sarebbero € 2.500,00).
    expect((int) Conto::find($s['spesa']->id)->importo)->toBe(150000);
})->with(G112_STRADE);

// --- Il selettore delle voci e la copertura della pagina di creazione ------------------------------------------------------------

it('Coda 438 — la pagina di creazione del 2027 propone le voci del piano del 2026, con l\'importo pieno da finanziare', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    $voci = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', $s['c']->id).'?gestione_id='.$s['g']->id.'&esercizio_id='.$s['es']['2027']->id)
        ->assertOk()->json())->keyBy('id');

    expect([$voci[$s['spesa']->id]['disabled'], $voci[$s['spesa']->id]['residuo'], $voci[$s['spesa']->id]['note']])->toBe([false, 1200, 'Disp: € 1.200,00'])
        ->and([$voci[$s['pulizie']->id]['disabled'], $voci[$s['pulizie']->id]['residuo']])->toBe([false, 200]);

    // Nel 2026 le stesse voci sono del piano del 2026.
    $voci26 = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', $s['c']->id).'?gestione_id='.$s['g']->id.'&esercizio_id='.$s['es']['2026']->id)
        ->assertOk()->json())->keyBy('id');
    expect([$voci26[$s['spesa']->id]['disabled'], $voci26[$s['spesa']->id]['note']])->toBe([true, 'Interamente finanziato/Esaurito']);
})->with(G112_STRADE);

// --- Le letture del già versato fuori dal motore: il badge del piano ---------------------------------------------------------------

it('Coda 438 — il piano del 2027 con il già versato del 2026 non si accende «Disallineato»: l\'atteso non toglie il già versato di un altro esercizio', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull()
        ->and(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027')))->toBeNull();

    $servizio = app(PianoRateQuoteService::class);
    // Dalla regola: il 2027 chiede € 1.400,00 e non toglie niente; il 2026 € 1.400,00 − € 300,00 = € 1.100,00.
    expect($servizio->totaleAttesoCents(g112Piano($s, 'Preventivo 2027')))->toBe(140000)
        ->and($servizio->totaleAttesoCents(g112Piano($s, 'Preventivo 2026')))->toBe(110000)
        ->and($servizio->eDisallineato(g112Piano($s, 'Preventivo 2027')))->toBeFalse()
        ->and($servizio->eDisallineato(g112Piano($s, 'Preventivo 2026')))->toBeFalse();
})->with(G112_STRADE);

// --- La pagina del già versato: «Vale per» -----------------------------------------------------------------------------------------

/** Le props della pagina del già versato della voce, dalla rotta. */
function g112Pagina($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.contributi.edit', [$s['c'], $s['spesa']]))->assertOk()->viewData('page')['props'];
}

/** Il corpo che la pagina manda salvando senza toccare niente (`ContributiEdit.vue`, `eseguiSalvataggio`), dalle props che ha ricevuto. */
function g112CorpoDellaPagina(array $props): array
{
    return [
        'natura' => $props['natura'],
        'descrizione' => $props['descrizione'] ?: null,
        'vale_per' => $props['vale_per'],
        'righe' => collect($props['righe'])->map(fn ($r) => ['immobile_id' => $r['immobile_id'], 'anagrafica_id' => $r['anagrafica_id'], 'gia_versato' => $r['gia_versato']])->all(),
        'liquidita_stato' => $props['liquidita_stato'],
        'cassa_id' => $props['liquidita_stato'] === 'registrata_in_cassa' ? $props['cassa_id'] : null,
        'nota_acconto' => null,
    ];
}

/** Le righe del già versato della voce, come stanno a database. */
function g112Righe(array $s): array
{
    return DB::table('contributi_versati')->where('target_type', Conto::class)->where('target_id', $s['spesa']->id)->orderBy('immobile_id')->orderBy('anagrafica_id')
        ->get(['immobile_id', 'anagrafica_id', 'importo_cents', 'esercizio_id', 'natura', 'descrizione', 'liquidita_stato', 'cassa_id'])->map(fn ($r) => (array) $r)->all();
}

it('Coda 438 — la pagina del già versato offre gli esercizi della gestione della voce, in ordine di data, e salva quello scelto', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);

    $props = g112Pagina($this, $s);
    // Una voce senza già versato su una gestione di due esercizi: niente di scelto, la pagina chiede.
    expect($props['vale_per'])->toBeNull()
        ->and(collect($props['esercizi'])->map(fn ($e) => [$e['id'], $e['nome']])->all())
        ->toBe([[$s['es']['2026']->id, 'Esercizio 2026'], [$s['es']['2027']->id, 'Esercizio 2027']]);

    g112GiaVersato($this, $s, '2027')->assertSessionHasNoErrors();
    expect(collect(g112Righe($s))->pluck('esercizio_id')->unique()->values()->all())->toBe([$s['es']['2027']->id])
        ->and(g112Pagina($this, $s)['vale_per'])->toBe($s['es']['2027']->id);

    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    expect(collect(g112Righe($s))->pluck('esercizio_id')->unique()->values()->all())->toBe([null])
        ->and(g112Pagina($this, $s)['vale_per'])->toBe('tutti');
})->with(G112_STRADE);

it('Coda 438 — la pagina del già versato aperta e salvata senza toccare niente manda ciò che ha ricevuto, e le righe restano uguali', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    // Dati scomodi: il già versato del 2027, una riga di Ugo e una dell'unità A senza persona, la nota, lo stato della liquidità.
    $this->actingAs($this->user)->put(route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]), [
        'natura' => 'avanzo', 'vale_per' => $s['es']['2027']->id, 'descrizione' => 'Delibera del 12/05/2025',
        'righe' => [
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => 12345],
            ['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => null, 'gia_versato' => 678],
            ['immobile_id' => $s['unita']['C']->id, 'anagrafica_id' => $s['persone']['bice']->id, 'gia_versato' => 1],
        ],
        'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA, 'cassa_id' => null,
    ])->assertSessionHasNoErrors();
    $prima = g112Righe($s);

    $props = g112Pagina($this, $s);
    $corpo = g112CorpoDellaPagina($props);
    expect($corpo['vale_per'])->toBe($s['es']['2027']->id);
    $this->actingAs($this->user)->put(route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]), $corpo)->assertSessionHasNoErrors();

    expect(g112Righe($s))->toBe($prima);
})->with(G112_STRADE);

it('Coda 438 — la pagina del già versato rifiuta un esercizio di un altro condominio, uno a cui la gestione della voce non è agganciata e la scelta lasciata vuota', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $altro = Condominio::factory()->create();
    $suo = Esercizio::create(['condominio_id' => $altro->id, 'nome' => 'Esercizio altrui', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $staccato = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2028', 'data_inizio' => '2028-01-01', 'data_fine' => '2028-12-31', 'stato' => 'aperto']);

    $corpo = fn ($valePer) => ['natura' => 'fondo_vincolato', 'vale_per' => $valePer, 'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'righe' => [['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => 30000]]];
    $url = route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]);

    $this->actingAs($this->user)->from('/gia-versato')->put($url, $corpo($suo->id))->assertSessionHasErrors('vale_per');
    $this->actingAs($this->user)->from('/gia-versato')->put($url, $corpo($staccato->id))->assertSessionHasErrors(['vale_per' => 'La gestione di questa voce non è nell\'esercizio scelto: scegli uno degli esercizi della gestione, oppure «Tutti gli esercizi».']);
    $this->actingAs($this->user)->from('/gia-versato')->put($url, $corpo(null))->assertSessionHasErrors(['vale_per' => 'Scegli per quale esercizio vale il già versato.']);
    expect(g112Righe($s))->toBe([]);
});

it('Coda 438 — senza la scelta nella richiesta il già versato tiene l\'esercizio che aveva, e senza righe non ne prende nessuno (vale per tutti, com\'era)', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    $url = route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]);
    $corpo = ['natura' => 'fondo_vincolato', 'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'righe' => [['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => 30000]]];

    // Nessuna riga: nessun esercizio, com'era prima (vale per tutti).
    $this->actingAs($this->user)->put($url, $corpo)->assertSessionHasNoErrors();
    expect(collect(g112Righe($s))->pluck('esercizio_id')->all())->toBe([null]);

    // Le righe hanno il 2026: un salvataggio senza la scelta lo tiene.
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    $this->actingAs($this->user)->put($url, $corpo)->assertSessionHasNoErrors();
    expect(collect(g112Righe($s))->pluck('esercizio_id')->all())->toBe([$s['es']['2026']->id]);

    // Anche su una gestione di un esercizio solo le righe nuove non ne prendono uno: la pagina chiede.
    $u = g112ScenarioUnEsercizio($this);
    expect(g112Pagina($this, $u)['vale_per'])->toBeNull();
    $this->actingAs($this->user)->put(route('admin.gestionale.contributi.update', [$u['c'], $u['spesa']]), [
        'natura' => 'fondo_vincolato', 'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'righe' => [['immobile_id' => $u['unita']['A']->id, 'anagrafica_id' => $u['persone']['ugo']->id, 'gia_versato' => 30000]],
    ])->assertSessionHasNoErrors();
    expect(collect(g112Righe($u))->pluck('esercizio_id')->all())->toBe([null])
        ->and(g112Pagina($this, $u)['vale_per'])->toBe('tutti');
});

it('Coda 438 — righe di esercizi diversi e una richiesta senza la scelta: si rifiuta, non si indovina', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    foreach ([['A', 'ugo', $s['es']['2026']->id], ['C', 'bice', null]] as [$u, $chi, $es]) {
        ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita'][$u]->id,
            'anagrafica_id' => $s['persone'][$chi]->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $es]);
    }
    $prima = g112Righe($s);

    $this->actingAs($this->user)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s['c'], $s['spesa']]), [
        'natura' => 'fondo_vincolato', 'liquidita_stato' => ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
        'righe' => [['immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'gia_versato' => 30000]],
    ])->assertSessionHasErrors(['vale_per' => 'Le righe di questa voce valgono per esercizi diversi: scegli per quale esercizio vale il già versato.']);
    expect(g112Righe($s))->toBe($prima);
});

it('Coda 438 — le righe di una voce con esercizi diversi (scritte fuori dalla pagina): la pagina non sceglie, e lo dice', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    foreach ([['A', 'ugo', $s['es']['2026']->id], ['C', 'bice', null]] as [$u, $chi, $es]) {
        ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita'][$u]->id,
            'anagrafica_id' => $s['persone'][$chi]->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $es]);
    }

    $props = g112Pagina($this, $s);
    expect($props['vale_per'])->toBeNull()
        ->and($props['vale_per_diversi'])->toBe(['Esercizio 2026', 'Tutti gli esercizi']);
});

it('Coda 438 — l\'elenco del già versato dice per ogni voce per quale esercizio vale', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    g112GiaVersato($this, $s, '2026')->assertSessionHasNoErrors();
    \App\Models\Gestionale\Conto::whereKey($s['pulizie']->id)->update(['richiede_gia_versato' => true]);

    $voci = collect($this->actingAs($this->user)->get(route('admin.gestionale.contributi.index', [$s['c']]))->assertOk()->viewData('page')['props']['voci'])->keyBy('id');
    expect($voci[$s['spesa']->id]['vale_per'])->toBe('Esercizio 2026')
        ->and($voci[$s['pulizie']->id]['vale_per'])->toBeNull();

    g112GiaVersato($this, $s, 'tutti')->assertSessionHasNoErrors();
    $voci = collect($this->actingAs($this->user)->get(route('admin.gestionale.contributi.index', [$s['c']]))->assertOk()->viewData('page')['props']['voci'])->keyBy('id');
    expect($voci[$s['spesa']->id]['vale_per'])->toBe('Tutti gli esercizi');
});

/** Un condominio con un esercizio solo, il 2026, e lo stesso scenario di voci e unità. */
function g112ScenarioUnEsercizio($test): array
{
    static $seq = 900;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e);
    $s = ['c' => $c, 'tabella' => $tabella, 'e' => $e, 'es' => ['2026' => $e], 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');

    return $s;
}

// --- La migrazione: le righe già salvate restano vuote -------------------------------------------------------------------------------

it('Coda 438, decisione 187 — la migrazione non dà l\'esercizio alle righe già salvate: restano vuote (valgono per tutti i piani della voce), con la gestione di un esercizio solo come con quella di due, e non scrive niente nel registro', function () {
    $due = g112Scenario($this, 'importati', ['2026', '2027']);
    $uno = g112ScenarioUnEsercizio($this);
    $riga = fn (array $s, string $u, string $chi) => ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id,
        'immobile_id' => $s['unita'][$u]->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione'])->id;
    $suDue = $riga($due, 'A', 'ugo');
    $suUno = [$riga($uno, 'A', 'ugo'), $riga($uno, 'C', 'bice')];
    // Un target che non è una voce (la gestione), e una riga che ha già l'esercizio (scritta dalla pagina dopo la prima corsa): la migrazione non la tocca.
    $suGestione = ContributoVersato::create(['condominio_id' => $uno['c']->id, 'target_type' => Gestione::class, 'target_id' => $uno['g']->id,
        'immobile_id' => $uno['unita']['A']->id, 'importo_cents' => 5000, 'natura' => 'avanzo', 'origine' => 'migrazione'])->id;
    $conEsercizio = ContributoVersato::create(['condominio_id' => $due['c']->id, 'target_type' => Conto::class, 'target_id' => $due['spesa']->id,
        'immobile_id' => $due['unita']['C']->id, 'anagrafica_id' => $due['persone']['bice']->id, 'importo_cents' => 10000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione',
        'esercizio_id' => $due['es']['2027']->id])->id;
    $vuote = [$suDue, ...$suUno, $suGestione];

    \Illuminate\Support\Facades\Log::spy();
    $migration = require database_path('migrations/2026_11_04_100000_add_esercizio_id_to_contributi_versati_table.php');
    $migration->up();

    expect(DB::table('contributi_versati')->whereIn('id', $vuote)->pluck('esercizio_id')->all())->toBe([null, null, null, null])
        ->and(DB::table('contributi_versati')->where('id', $conEsercizio)->value('esercizio_id'))->toBe($due['es']['2027']->id);
    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info');

    // Rieseguita: le righe vuote restano vuote, quella con l'esercizio lo tiene.
    $migration->up();
    expect(DB::table('contributi_versati')->whereIn('id', $vuote)->pluck('esercizio_id')->all())->toBe([null, null, null, null])
        ->and(DB::table('contributi_versati')->where('id', $conEsercizio)->value('esercizio_id'))->toBe($due['es']['2027']->id);
});

// --- Varianti vicine ------------------------------------------------------------------------------------------------------------------

it('Coda 438, P2 — un piano del 2027 nato senza voci (la riga del piano vuota, com\'era prima di questa correzione): il ricalcolo è rifiutato, e creato di nuovo dalla pagina del 2027 prende le voci libere nel 2027', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es']['2027']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2027', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();
    $p27 = g112Piano($s, 'Preventivo 2027');
    // Così lo lasciava la creazione prima: le voci del 2026 erano «impegnate», e il piano nasceva senza.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->delete();

    // Decisione 192.1: con la riga vuota e il piano del 2026 sulla stessa gestione il ricalcolo si rifiuta (prima prendeva le voci senza impegnarle per i piani
    // creati dopo), e il piano resta senza voci e senza quote.
    expect(g112Errore(g112Ricalcola($this, $s, $p27, '2027')))->toStartWith('Impossibile ricalcolare: il piano rate «Preventivo 2027» non ha voci associate')
        ->and(g112Chiesto($p27, $s))->toBe(['ugo' => 0, 'bice' => 0])
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->exists())->toBeFalse();
    // La strada: eliminato e creato di nuovo dalla pagina del 2027 senza scegliere le voci. Dalla decisione 183.2: le due voci non sono in un piano del 2027,
    // € 700,00 a testa.
    expect(g112Errore($this->actingAs($this->user)->from('/elenco')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['es']['2027'], $p27]))))->toBeNull();
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toBeNull()
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(G112_STRADE);

it('controprova — AD3: un integrativo nato senza voci, e poi una voce nuova a € 0,00 non in altri piani dello stesso esercizio: il ricalcolo rifiuta come sempre', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    // Creato senza generarlo quando tutte le voci sono nel piano del 2026: nasce senza voci.
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es']['2026']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativo 2026', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();
    $integrativo = g112Piano($s, 'Integrativo 2026');
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $integrativo->id)->count())->toBe(0);
    be52Voce($this, $s, $s['pc'], 'Varie', 'spesa', '0,00');

    // «Varie» (€ 0,00) non è in nessun piano: non tutte le voci sono impegnate, e il rifiuto è quello delle tre verifiche.
    expect(g112Errore(g112Ricalcola($this, $s, $integrativo, '2026')))
        ->toBe('Errore durante il ricalcolo: '.'Impossibile generare il piano rate: nessuna quota calcolata. Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, (2) ogni immobile abbia almeno un condòmino attivo associato, (3) ogni voce di spesa sia collegata a una tabella millesimale.');
});

it('Coda 438 — righe della stessa voce con esercizi diversi (scritte fuori dalla pagina): il badge del piano del 2026 toglie solo quelle che valgono nel 2026', function () {
    $s = g112Scenario($this, 'importati', ['2026', '2027']);
    foreach ([['A', 'ugo', 30000, $s['es']['2026']->id], ['C', 'bice', 10000, $s['es']['2027']->id]] as [$u, $chi, $cents, $es]) {
        ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['spesa']->id, 'immobile_id' => $s['unita'][$u]->id,
            'anagrafica_id' => $s['persone'][$chi]->id, 'importo_cents' => $cents, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $es]);
    }
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();

    // Dalla regola: nel 2026 vale solo il versato di Ugo; Ugo € 400,00, Bice € 700,00, l'atteso € 1.400,00 − € 300,00 = € 1.100,00.
    $p26 = g112Piano($s, 'Preventivo 2026');
    expect(g112Chiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000])
        ->and(app(PianoRateQuoteService::class)->totaleAttesoCents($p26))->toBe(110000)
        ->and(app(PianoRateQuoteService::class)->eDisallineato($p26))->toBeFalse();
});

it('Coda 438, P2 — il piano del 2027 senza voci scelte, con una fattura del 2027 di € 1.500,00 sulla voce: chiede quello che manca nel 2027, € 1.500,00', function (string $strada, array $ordine) {
    $s = g112Scenario($this, $strada, $ordine);
    expect(g112Errore(g112Crea($this, $s, '2026', 'Preventivo 2026')))->toBeNull();
    g112Fattura($this, $s, '2027', 1500.00, true);

    // Il fabbisogno del 2027 è la fattura, € 1.500,00, e nel 2027 nessun piano lo chiede ancora: (€ 1.500,00 + € 200,00) × 500/1000 = € 850,00 a testa
    // (il piano del 2026 non lo scala: contato anche lui, mancherebbero € 300,00 e il piano chiederebbe € 250,00 a testa).
    expect(g112Errore(g112Crea($this, $s, '2027', 'Preventivo 2027', null)))->toBeNull()
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', g112Piano($s, 'Preventivo 2027')->id)->where('conto_id', $s['spesa']->id)->value('importo'))->toEqual(150000)
        ->and(g112Chiesto(g112Piano($s, 'Preventivo 2027'), $s))->toBe(['ugo' => 85000, 'bice' => 85000]);
})->with(G112_STRADE);

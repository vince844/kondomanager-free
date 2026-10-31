<?php

/**
 * Coda 75 — su una gestione agganciata a più esercizi, la pagina del piano, «Sposta spesa» e i collegamenti dei task leggono l'esercizio del piano.
 *
 * L'importatore aggancia la stessa «Gestione ordinaria» a ogni esercizio che importa (`CondominioService::createDefaultGestione`), e il pivot
 * `esercizio_gestione` resta con `attiva = 1` su tutte le righe: nessun codice lo spegne. Cinque letture prendevano «il primo esercizio attivo» del
 * pivot, senza ordine: il saldo iniziale della pagina del piano (per persona e per unità), lo speso che limita «Sposta spesa» e l'esercizio dei due
 * collegamenti dei task dell'Inbox (eccedenza del già versato, quote non assegnate). Leggono l'esercizio del piano, `CompetenzaDelPiano::esercizioDelPiano`
 * (`piani_rate.esercizio_id`, scritto a ogni generazione dalla 1.11.0-beta.31; per i piani più vecchi la deduzione per data di creazione).
 *
 * Fonte dell'atteso: la decisione 163.2 (D3, la strada B: i cinque punti leggono l'esercizio del piano) e la regola di «Sposta spesa»
 * (`BudgetMovementService::moveBudget`: disponibile = importo della voce nel piano − speso registrato); per i messaggi della guardia del già versato
 * e del piano senza voci, sulla gestione usata in più esercizi, le decisioni 166 e 168. Le cifre si rifanno a mano accanto a ogni test.
 *
 * Lo scenario lo costruisce come l'importatore (`LivelloEsercizi`): gli esercizi nascono aperti e a ognuno si aggancia la gestione ordinaria con
 * `createDefaultGestione`. Ogni test gira con i due ordini d'importazione, perché senza un ordine il «primo» del pivot dipende da quale riga c'è prima.
 *
 * Che cosa NON copre: MySQL; la stampa (non legge questi punti); i piani straordinari (stessa lettura).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\EventoTipo;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Si lavora ad aprile 2027: il 2027 c'è già, e il piano del 2026 (il consuntivo) si crea adesso. Le spese del 2027 non sono nel futuro.
    $this->travelTo(now()->setDate(2027, 4, 15)->setTime(10, 0));
    $this->user = be52Utente();
    // La pagina del piano legge `regole_calcolo` con `JSON_UNQUOTE`, che SQLite non ha.
    b52tSqliteJson();
});

const B60E_ORDINI = ['importati il 2026 e poi il 2027' => [['2026', '2027']], 'importati il 2027 e poi il 2026' => [['2027', '2026']]];

/**
 * Un condominio con due esercizi, 2026 e 2027, aperti come li crea l'importatore, e la gestione ordinaria agganciata a tutti e due con
 * `createDefaultGestione` (due righe del pivot con `attiva = 1`). Ugo (A) e Bice (C) a 500 millesimi; la voce «Spese generali» € 1.200,00 e
 * «Pulizie» € 200,00, create dalla rotta.
 *
 * @param list<string> $ordine l'ordine in cui l'importatore crea gli esercizi
 */
function b60eScenario($test, array $ordine, bool $conUnitaSenzaProprietario = false): array
{
    static $seq = 100;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    $es = [];
    $gestioni = [];
    foreach ($ordine as $anno) {
        // Come `LivelloEsercizi::commit`: l'esercizio nasce aperto, poi la gestione ordinaria (la stessa, riusata) gli si aggancia.
        $es[$anno] = Esercizio::create(['condominio_id' => $c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    expect(DB::table('esercizio_gestione')->where('gestione_id', $g->id)->where('attiva', true)->count())->toBe(2);

    $s = ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $es['2026'], 'es' => $es, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
    be52Unita($s, 'A', 'ugo', $tabella, 500.0);
    be52Unita($s, 'C', 'bice', $tabella, $conUnitaSenzaProprietario ? 400.0 : 500.0);
    if ($conUnitaSenzaProprietario) {
        // Un'unità con i millesimi e nessun titolare: la sua quota resta non assegnata.
        $s['unita']['D'] = \App\Models\Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "S60-{$seq}-D", 'nome' => 'Interno D', 'interno' => 'D']);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita']['D']->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s['pc'] = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '200,00');

    return $s;
}

/** Il piano ordinario dell'anno, dalla rotta di creazione con «genera subito», con le due voci all'importo pieno. */
function b60ePiano($test, array $s, string $anno, array $extra = []): PianoRate
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => "Preventivo {$anno}", 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 1,
        'capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '1.200,00'], ['id' => $s['pulizie']->id, 'importo' => '200,00']],
    ], $extra))->assertSessionHasNoErrors();

    return PianoRate::where('condominio_id', $s['c']->id)->where('nome', "Preventivo {$anno}")->firstOrFail();
}

/** Un saldo iniziale (positivo = debito) di una persona su un'unità, nell'esercizio dato della gestione, libero nel Wallet. */
function b60eSaldo(array $s, string $anno, string $chi, string $unita, int $cents): Saldo
{
    return Saldo::create(['esercizio_id' => $s['es'][$anno]->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$unita]->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'origine' => 'manuale', 'is_applicato' => false]);
}

/** Le props della pagina del piano, dalla rotta, dentro l'esercizio dato. */
function b60ePagina($test, array $s, PianoRate $piano, string $anno): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['es'][$anno], $piano]))->assertOk()->viewData('page')['props'];
}

/** Il saldo iniziale che la pagina mostra: per Ugo (scheda per persona) e per l'unità A (scheda per unità, debiti). */
function b60eSaldiMostrati(array $props, array $s): array
{
    return [
        'ugo' => collect($props['quotePerAnagrafica'])->firstWhere('anagrafica.id', $s['persone']['ugo']->id)['saldo_iniziale'],
        'A_debiti' => collect($props['quotePerImmobile'])->firstWhere('immobile.id', $s['unita']['A']->id)['totale_debiti'],
    ];
}

/** Quanto il piano chiede a ognuno, sommando tutte le rate. */
function b60eChiesto(PianoRate $piano, array $s): array
{
    return collect($s['persone'])->map(fn ($a) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo'))->all();
}

/** Lo speso di una voce in un esercizio, con una regolazione immediata dalla rotta. */
function b60eSpeso($test, array $s, string $anno, int $contoId, float $euro): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['es'][$anno]->id, 'conto_id' => $contoId, 'cassa_id' => $s['cassa']->id,
        'data_operazione' => "{$anno}-03-10", 'causale' => "Spesa del {$anno}", 'importo' => $euro,
    ])->assertSessionHasNoErrors();
}

function b60eSposta($test, array $s, PianoRate $piano, string $euro)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $piano]), [
        'source_id' => $s['spesa']->id, 'destination_id' => $s['pulizie']->id, 'amount' => $euro, 'reason' => 'Spostamento di prova',
    ]);
}

function b60ePivot(PianoRate $piano): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('conto_id')->pluck('importo', 'conto_id')->map(fn ($v) => (int) $v)->all();
}

/**
 * Il 2027 ha già il suo piano, che ha assorbito il saldo del 2027 (Ugo € 500,00 a debito); poi si registra il saldo del 2026 (Ugo € 300,00 a debito) e
 * si crea il piano del 2026, che assorbe solo quello (l'altro è già del piano del 2027).
 */
function b60eDuePianiConSaldi($test, array $ordine): array
{
    $s = b60eScenario($test, $ordine);
    b60eSaldo($s, '2027', 'ugo', 'A', 50000);
    $p27 = b60ePiano($test, $s, '2027');
    b60eSaldo($s, '2026', 'ugo', 'A', 30000);
    $p26 = b60ePiano($test, $s, '2026');

    return [$s, $p26, $p27];
}

// --- I punti 1 e 2: il saldo iniziale della pagina del piano -------------------------------------------------------------------

it('Coda 75 — la pagina del piano del 2026, su una gestione agganciata anche al 2027, mostra il saldo iniziale del 2026, per persona e per unità', function (array $ordine) {
    [$s, $p26] = b60eDuePianiConSaldi($this, $ordine);

    // Conto a mano: il piano del 2026 ha assorbito il saldo del 2026, € 300,00 a debito di Ugo su A; quote € 1.400,00 × 500/1000 = € 700,00 a testa.
    expect($p26->esercizio_id)->toBe($s['es']['2026']->id)
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 70000 + 30000, 'bice' => 70000])
        ->and(b60eSaldiMostrati(b60ePagina($this, $s, $p26, '2026'), $s))->toBe(['ugo' => 30000, 'A_debiti' => 30000]);
})->with(B60E_ORDINI);

it('Coda 75 — la pagina del piano del 2027 sulla stessa gestione mostra il saldo iniziale del 2027', function (array $ordine) {
    [$s, , $p27] = b60eDuePianiConSaldi($this, $ordine);

    // Conto a mano: il piano del 2027 ha assorbito il saldo del 2027, € 500,00 a debito di Ugo su A.
    expect($p27->esercizio_id)->toBe($s['es']['2027']->id)
        ->and(b60eChiesto($p27, $s))->toBe(['ugo' => 70000 + 50000, 'bice' => 70000])
        ->and(b60eSaldiMostrati(b60ePagina($this, $s, $p27, '2027'), $s))->toBe(['ugo' => 50000, 'A_debiti' => 50000]);
})->with(B60E_ORDINI);

it('Coda 75 — il saldo della pagina non dipende dall\'esercizio dell\'indirizzo: il piano del 2026 aperto dal 2027 mostra ancora il 2026', function (array $ordine) {
    [$s, $p26] = b60eDuePianiConSaldi($this, $ordine);

    // La rotta accetta il piano sotto ogni esercizio della sua gestione (`Esercizio::pianiRate`, per il pivot): il saldo resta quello del piano.
    expect(b60eSaldiMostrati(b60ePagina($this, $s, $p26, '2027'), $s))->toBe(['ugo' => 30000, 'A_debiti' => 30000]);
})->with(B60E_ORDINI);

it('Coda 75 — un piano generato prima che il piano ricordasse il suo esercizio legge quello che contiene la sua data di creazione', function (array $ordine) {
    [$s, $p26] = b60eDuePianiConSaldi($this, $ordine);
    // Il dato di prima della 1.11.0-beta.31: la colonna è vuota e il piano è stato creato nel 2026.
    DB::table('piani_rate')->where('id', $p26->id)->update(['esercizio_id' => null, 'created_at' => '2026-02-15 10:00:00']);

    expect(b60eSaldiMostrati(b60ePagina($this, $s, $p26->fresh(), '2026'), $s))->toBe(['ugo' => 30000, 'A_debiti' => 30000]);
})->with(B60E_ORDINI);

// --- Il punto 3: lo speso che limita «Sposta spesa» ---------------------------------------------------------------------------

it('Coda 75 — «Sposta spesa» sul piano del 2026 misura lo speso del 2026: con € 1.000,00 già spesi si spostano al massimo € 200,00', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    $p26 = b60ePiano($this, $s, '2026');
    b60eSpeso($this, $s, '2026', $s['spesa']->id, 1000.00);
    b60eSpeso($this, $s, '2027', $s['spesa']->id, 100.00);

    // Conto a mano: nel piano «Spese generali» vale € 1.200,00 e nel 2026 ne sono già spesi € 1.000,00: disponibili € 200,00, e € 500,00 non passano.
    $r = b60eSposta($this, $s, $p26, '500.00');
    $r->assertSessionHasErrors(['amount' => 'Questa voce ha già € 1.000,00 di fatture registrate. Puoi spostare al massimo € 200,00 senza lasciarla scoperta.']);
    expect(b60ePivot($p26))->toBe([$s['spesa']->id => 120000, $s['pulizie']->id => 20000]);

    // € 200,00 sì: la voce resta coperta (1.000,00 spesi su 1.000,00 nel piano).
    b60eSposta($this, $s, $p26, '200.00')->assertSessionHasNoErrors();
    expect(b60ePivot($p26))->toBe([$s['spesa']->id => 100000, $s['pulizie']->id => 40000]);
})->with(B60E_ORDINI);

it('Coda 75 — «Sposta spesa» sul piano del 2027 misura lo speso del 2027: con € 100,00 già spesi, € 500,00 si spostano', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    $p27 = b60ePiano($this, $s, '2027');
    b60eSpeso($this, $s, '2026', $s['spesa']->id, 1000.00);
    b60eSpeso($this, $s, '2027', $s['spesa']->id, 100.00);

    // Conto a mano: € 1.200,00 nel piano − € 100,00 spesi nel 2027 = € 1.100,00 disponibili: € 500,00 passano, e € 1.200,00 no.
    b60eSposta($this, $s, $p27, '500.00')->assertSessionHasNoErrors();
    expect(b60ePivot($p27))->toBe([$s['spesa']->id => 70000, $s['pulizie']->id => 70000]);
    b60eSposta($this, $s, $p27, '700.00')->assertSessionHasErrors(['amount' => 'Questa voce ha già € 100,00 di fatture registrate. Puoi spostare al massimo € 600,00 senza lasciarla scoperta.']);
})->with(B60E_ORDINI);

// --- I punti 4 e 5: i collegamenti dei task dell'Inbox ----------------------------------------------------------------------

it('Coda 75 — il task delle quote non assegnate porta al piano dentro il suo esercizio, per il 2026 e per il 2027', function (array $ordine) {
    $s = b60eScenario($this, $ordine, conUnitaSenzaProprietario: true);
    $accetta = ['accetta_scoperti' => 1, 'nota_scoperti' => 'Interno D senza proprietario: lo censisco dopo'];
    $p26 = b60ePiano($this, $s, '2026', $accetta);
    $p27 = b60ePiano($this, $s, '2027', $accetta);

    $link = fn (PianoRate $p) => Evento::where('meta->type', EventoTipo::SCOPERTO_DOCUMENTATO->value)->where('meta->context->piano_rate_id', $p->id)->firstOrFail()->meta['action_url'];
    expect($link($p26))->toBe("/gestionale/{$s['c']->id}/esercizi/{$s['es']['2026']->id}/piani-rate/{$p26->id}")
        ->and($link($p27))->toBe("/gestionale/{$s['c']->id}/esercizi/{$s['es']['2027']->id}/piani-rate/{$p27->id}");
})->with(B60E_ORDINI);

it('Coda 75 — il task dell\'eccedenza del già versato porta al piano dentro il suo esercizio, per il 2026 e per il 2027', function (array $ordine) {
    // Un condominio per anno, con un piano solo: due piani attivi sulla stessa voce con il già versato si fermano prima di generare
    // (`CalcoloQuoteService`, la guardia del già versato), e qui conta il collegamento, non quella guardia.
    $link = [];
    foreach (['2026', '2027'] as $anno) {
        $s = b60eScenario($this, $ordine);
        // Ugo ha già versato € 800,00 per «Spese generali»: la sua quota è € 1.200,00 × 500/1000 = € 600,00, l'eccedenza € 200,00 apre il task.
        ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s['spesa']->id,
            'immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'importo_cents' => 80000, 'natura' => 'fondo_vincolato',
            'origine' => 'migrazione', 'descrizione' => 'Accantonamento di prima']);
        $p = b60ePiano($this, $s, $anno);
        $evento = Evento::where('meta->type', EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA->value)->where('meta->context->piano_rate_id', $p->id)->firstOrFail();
        expect($evento->meta['context']['totale_cents'])->toBe(20000);
        $link[$anno] = [$evento->meta['action_url'], "/gestionale/{$s['c']->id}/esercizi/{$s['es'][$anno]->id}/piani-rate/{$p->id}"];
    }

    expect($link['2026'][0])->toBe($link['2026'][1])
        ->and($link['2027'][0])->toBe($link['2027'][1]);
})->with(B60E_ORDINI);

it('Coda 75 — «Sposta spesa» sul piano del 2026 creato ad aprile 2027 senza generarlo misura lo speso del 2026: il piano ricorda l\'esercizio della pagina da cui nasce', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    // Creato dalla pagina del 2026 senza «genera subito»: niente rate, e la data di creazione (15/04/2027) cade nel 2027.
    $p26 = b60ePiano($this, $s, '2026', ['genera_subito' => 0]);
    b60eSpeso($this, $s, '2026', $s['spesa']->id, 1000.00);
    b60eSpeso($this, $s, '2027', $s['spesa']->id, 100.00);

    // Conto a mano: € 1.200,00 nel piano − € 1.000,00 spesi nel 2026 = € 200,00 disponibili, e € 500,00 non passano (la data di creazione
    // avrebbe dato il 2027: € 1.200,00 − € 100,00 = € 1.100,00, e la voce scoperta di € 300,00).
    b60eSposta($this, $s, $p26, '500.00')
        ->assertSessionHasErrors(['amount' => 'Questa voce ha già € 1.000,00 di fatture registrate. Puoi spostare al massimo € 200,00 senza lasciarla scoperta.']);
    expect(b60ePivot($p26))->toBe([$s['spesa']->id => 120000, $s['pulizie']->id => 20000])
        ->and($p26->rate()->count())->toBe(0)
        ->and($p26->fresh()->esercizio_id)->toBe($s['es']['2026']->id);
})->with(B60E_ORDINI);

// --- Decisione 166: i messaggi sulla gestione usata in più esercizi -----------------------------------------------------------

/**
 * Un piano dell'esercizio dalla rotta di creazione con «genera subito»; con `$capitoli` le voci scelte e il loro importo nel piano, senza le voci
 * non scelte (il piano prende quelle che nessun altro piano attivo della gestione ha già). Restituisce la risposta, anche quando la rotta rifiuta.
 *
 * @param array<string, string>|null $capitoli chiave dello scenario della voce => importo nel piano, in euro come lo scrive il modulo
 */
function b60eCrea($test, array $s, string $anno, string $nome, ?array $capitoli)
{
    return $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es'][$anno]]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05", 'genera_subito' => 1,
    ] + ($capitoli !== null ? ['capitoli_config' => collect($capitoli)->map(fn ($euro, $voce) => ['id' => $s[$voce]->id, 'importo' => $euro])->values()->all()] : []));
}

/** Il messaggio d'errore della rotta (il flash della pagina). */
function b60eErrore($r): ?string
{
    return $r->getSession()->get('message')['message'] ?? null;
}

/** Ugo ha già versato € 300,00 per «Spese generali» (unità A). */
function b60eGiaVersato(array $s): void
{
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s['spesa']->id,
        'immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato',
        'origine' => 'migrazione', 'descrizione' => 'Accantonamento di prima']);
}

const B60E_NESSUNA_QUOTA = 'Impossibile generare il piano rate: nessuna quota calcolata. Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, (2) ogni immobile abbia almeno un condòmino attivo associato, (3) ogni voce di spesa sia collegata a una tabella millesimale.';

const B60E_VOCI_DEL_2026 = 'Impossibile generare il piano rate: tutte le voci di questa gestione sono già nel piano «Preventivo 2026» dell\'esercizio «Esercizio 2026», attivo sulla stessa gestione, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo della gestione ha già, di qualunque esercizio. Sulla gestione usata in più esercizi il programma non tiene ancora separati i piani di esercizi diversi.';

it('Coda 75, decisione 166 — il piano del 2027 creato senza scegliere le voci, quando sono tutte nel piano del 2026: il rifiuto dice la causa e nomina il piano, e nessuna cifra cambia', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    $p26 = b60ePiano($this, $s, '2026');

    $r = b60eCrea($this, $s, '2027', 'Preventivo 2027', null);
    expect(b60eErrore($r))->toBe(B60E_VOCI_DEL_2026)
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->count())->toBe(0)
        // Conto a mano: € 1.400,00 × 500/1000 = € 700,00 a testa, come prima del tentativo.
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 70000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — il piano del 2027 creato senza generarlo e poi generato dalla sua pagina: il ricalcolo dice la stessa causa', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60ePiano($this, $s, '2026');
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es']['2027']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2027', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();
    $p27 = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->firstOrFail();

    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2027'], $p27]));
    expect(b60eErrore($r))->toBe('Errore durante il ricalcolo: '.B60E_VOCI_DEL_2026)
        ->and($p27->rate()->count())->toBe(0);
})->with(B60E_ORDINI);

it('controprova — decisione 166: le voci tutte in un altro piano dello stesso esercizio: il rifiuto resta quello di prima', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60ePiano($this, $s, '2026');

    expect(b60eErrore(b60eCrea($this, $s, '2026', 'Integrativo 2026', null)))->toBe(B60E_NESSUNA_QUOTA);
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — il già versato e la stessa voce nel piano del 2026: il piano del 2027 si ferma, e il messaggio dice il fatto senza proporre di disattivare un piano né di alzare la voce; nessuna cifra cambia', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');

    $r = b60eCrea($this, $s, '2027', 'Preventivo 2027', ['spesa' => '1.200,00', 'pulizie' => '200,00']);
    // Conto a mano: € 1.200,00 del piano del 2026 + € 1.200,00 di questo = € 2.400,00 sulla voce da € 1.200,00.
    expect(b60eErrore($r))->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato ed è già nel piano «Preventivo 2026» dell\'esercizio «Esercizio 2026», attivo sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 2.400,00) supera il suo budget (€ 1.200,00), e il già versato verrebbe tolto in tutti e due. Sulla gestione usata in più esercizi il programma non divide ancora il già versato di una voce fra i piani di esercizi diversi.')
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->count())->toBe(0)
        // Conto a mano: Ugo € 600,00 − € 300,00 già versati + € 100,00 = € 400,00; Bice € 600,00 + € 100,00 = € 700,00.
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('controprova — decisione 166: il già versato e due piani dello stesso esercizio sulla stessa voce: il messaggio resta quello di prima', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    b60ePiano($this, $s, '2026');

    expect(b60eErrore(b60eCrea($this, $s, '2026', 'Integrativo 2026', ['spesa' => '1.200,00'])))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già-versato registrato, ma la somma degli importi richiesti dai piani rate attivi su questa voce (€ 2.400,00) supera il suo budget (€ 1.200,00). Aggiorna prima l\'importo della voce al fabbisogno reale, oppure disattiva uno dei piani rate in conflitto: altrimenti il già versato verrebbe conteggiato più volte e parte della spesa non verrebbe mai richiesta ai condòmini.');
})->with(B60E_ORDINI);

it('controprova — decisione 166: il già versato, un piano del 2026 e due del 2027 che già fra loro superano la voce: il messaggio resta quello di prima', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    // € 600,00 nel 2027 e € 600,00 nel 2026: insieme € 1.200,00, la voce basta e tutti e due si generano.
    b60eCrea($this, $s, '2027', 'Primo 2027', ['spesa' => '600,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2026', 'Preventivo 2026', ['spesa' => '600,00'])->assertSessionHasNoErrors();
    expect(PianoRate::where('condominio_id', $s['c']->id)->whereIn('nome', ['Primo 2027', 'Preventivo 2026'])->count())->toBe(2);

    // Conto a mano: nel solo 2027 € 600,00 + € 700,00 = € 1.300,00 superano già € 1.200,00; con il 2026 la somma è € 1.900,00.
    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Secondo 2027', ['spesa' => '700,00'])))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già-versato registrato, ma la somma degli importi richiesti dai piani rate attivi su questa voce (€ 1.900,00) supera il suo budget (€ 1.200,00). Aggiorna prima l\'importo della voce al fabbisogno reale, oppure disattiva uno dei piani rate in conflitto: altrimenti il già versato verrebbe conteggiato più volte e parte della spesa non verrebbe mai richiesta ai condòmini.');
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — le voci in due piani del 2026, una per piano: il rifiuto del piano del 2027 senza voci scelte li nomina tutti e due', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eCrea($this, $s, '2026', 'Spese 2026', ['spesa' => '1.200,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2026', 'Pulizie 2026', ['pulizie' => '200,00'])->assertSessionHasNoErrors();

    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Preventivo 2027', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nei piani «Spese 2026» dell\'esercizio «Esercizio 2026» e «Pulizie 2026» dell\'esercizio «Esercizio 2026», attivi sulla stessa gestione, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo della gestione ha già, di qualunque esercizio. Sulla gestione usata in più esercizi il programma non tiene ancora separati i piani di esercizi diversi.');
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — il già versato e la voce divisa in due piani del 2026: il piano del 2027 si ferma, e il messaggio li nomina tutti e due', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    // € 600,00 e € 600,00 nel 2026: insieme € 1.200,00, la voce basta.
    b60eCrea($this, $s, '2026', 'Acconto 2026', ['spesa' => '600,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2026', 'Saldo 2026', ['spesa' => '600,00'])->assertSessionHasNoErrors();

    // Conto a mano: € 600,00 + € 600,00 + € 600,00 = € 1.800,00 sulla voce da € 1.200,00; nel solo 2027 € 600,00 bastano.
    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Preventivo 2027', ['spesa' => '600,00'])))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato ed è già nei piani «Acconto 2026» dell\'esercizio «Esercizio 2026» e «Saldo 2026» dell\'esercizio «Esercizio 2026», attivi sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 1.800,00) supera il suo budget (€ 1.200,00), e il già versato verrebbe tolto in ognuno. Sulla gestione usata in più esercizi il programma non divide ancora il già versato di una voce fra i piani di esercizi diversi.');
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — le voci in un piano del 2026 e in uno del 2027: il rifiuto di un integrativo del 2027 senza voci scelte li nomina tutti e due e dice quale è dell\'esercizio di questo piano', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60ePiano($this, $s, '2026');
    b60ePiano($this, $s, '2027');

    // Le voci sono nel piano del 2026 e anche in quello del 2027, che è dell'esercizio dell'integrativo: la frase non lascia credere che basti il limite
    // sugli esercizi diversi.
    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Integrativo 2027', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nei piani «Preventivo 2026» dell\'esercizio «Esercizio 2026» e «Preventivo 2027» dell\'esercizio «Esercizio 2027», attivi sulla stessa gestione, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo della gestione ha già, di qualunque esercizio. Fra questi, «Preventivo 2027» è dello stesso esercizio di questo piano. Sulla gestione usata in più esercizi il programma non tiene ancora separati i piani di esercizi diversi.')
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Integrativo 2027')->count())->toBe(0);
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — le voci in due piani del 2027 e in uno del 2026: la frase dice quali piani sono dell\'esercizio di questo piano, al plurale', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eCrea($this, $s, '2027', 'Spese 2027', ['spesa' => '1.200,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2027', 'Pulizie 2027', ['pulizie' => '200,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2026', 'Spese 2026', ['spesa' => '600,00'])->assertSessionHasNoErrors();

    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Integrativo 2027', null)))
        ->toBe('Impossibile generare il piano rate: tutte le voci di questa gestione sono già nei piani «Spese 2027» dell\'esercizio «Esercizio 2027», «Pulizie 2027» dell\'esercizio «Esercizio 2027» e «Spese 2026» dell\'esercizio «Esercizio 2026», attivi sulla stessa gestione, e un piano creato senza scegliere le voci prende solo quelle che nessun altro piano attivo della gestione ha già, di qualunque esercizio. Fra questi, «Spese 2027» e «Pulizie 2027» sono dello stesso esercizio di questo piano. Sulla gestione usata in più esercizi il programma non tiene ancora separati i piani di esercizi diversi.');
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — il già versato, un acconto del 2027, un piano del 2026 e il saldo del 2027: il messaggio nomina i tre piani della voce, e il già versato viene tolto in ognuno', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    // € 600,00 nel 2027 e € 600,00 nel 2026: insieme € 1.200,00, la voce basta e tutti e due si generano.
    b60eCrea($this, $s, '2027', 'Acconto 2027', ['spesa' => '600,00'])->assertSessionHasNoErrors();
    b60eCrea($this, $s, '2026', 'Preventivo 2026', ['spesa' => '600,00'])->assertSessionHasNoErrors();

    // Conto a mano: € 600,00 + € 600,00 + € 600,00 = € 1.800,00 sulla voce da € 1.200,00. Nel solo 2027 € 600,00 + € 600,00 = € 1.200,00 bastano.
    // I piani che hanno la voce sono tre con questo, e il già versato si toglie in ognuno, non «in tutti e due».
    expect(b60eErrore(b60eCrea($this, $s, '2027', 'Saldo 2027', ['spesa' => '600,00'])))
        ->toBe('Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato ed è già nei piani «Acconto 2027» dell\'esercizio «Esercizio 2027» e «Preventivo 2026» dell\'esercizio «Esercizio 2026», attivi sulla stessa gestione: con questo piano la somma degli importi richiesti sulla voce (€ 1.800,00) supera il suo budget (€ 1.200,00), e il già versato verrebbe tolto in ognuno. Sulla gestione usata in più esercizi il programma non divide ancora il già versato di una voce fra i piani di esercizi diversi.')
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Saldo 2027')->count())->toBe(0);
})->with(B60E_ORDINI);

it('Coda 75, decisione 166 — il messaggio del già versato parla di un già versato solo, senza il trattino', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    b60ePiano($this, $s, '2026');

    $messaggio = b60eErrore(b60eCrea($this, $s, '2027', 'Preventivo 2027', ['spesa' => '1.200,00', 'pulizie' => '200,00']));
    expect($messaggio)->toContain('ha un già versato registrato')
        ->and($messaggio)->not->toContain('già-versato');
})->with(B60E_ORDINI);

/** Il «Preventivo 2027» creato dalla pagina del 2027 senza generarlo, quando le voci sono tutte nel piano del 2026: nasce senza voci. */
function b60eSenzaVoci2027($test, array $s): PianoRate
{
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['es']['2027']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2027', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();
    $p27 = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->firstOrFail();
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $p27->id)->count())->toBe(0);

    return $p27;
}

it('controprova — decisione 166: la stampa del riparto del piano del 2027 senza voci è un\'anteprima, e non si ferma', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60ePiano($this, $s, '2026');
    $p27 = b60eSenzaVoci2027($this, $s);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.print-riparto-tabelle', [$s['c'], $s['es']['2027'], $p27]))->assertOk();
})->with(B60E_ORDINI);

it('controprova — decisione 166: una voce nuova, non in altri piani, accanto alle voci del 2026: il ricalcolo del piano del 2027 senza voci fa ciò che faceva prima', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60ePiano($this, $s, '2026');
    $p27 = b60eSenzaVoci2027($this, $s);
    be52Voce($this, $s, $s['pc'], 'Varie', 'spesa', '0,00');

    // «Varie» (€ 0,00) non è in nessun piano: non tutte le voci sono impegnate, e il rifiuto è quello di sempre.
    $r = $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es']['2027'], $p27]));
    expect(b60eErrore($r))->toBe('Errore durante il ricalcolo: '.B60E_NESSUNA_QUOTA)
        ->and($p27->rate()->count())->toBe(0);
})->with(B60E_ORDINI);

// --- Decisione 168: il già versato e le fatture reali di più esercizi sulla stessa voce ------------------------------------------

/**
 * Una fattura reale dell'esercizio dato, con una riga sulla voce, dalla rotta delle fatture. Con `$sforo` porta la motivazione che la rotta chiede
 * quando la fattura supera da sola la voce. Il fornitore e la data stanno nell'esercizio, come le fatture dell'importatore.
 */
function b60eFattura($test, array $s, string $anno, string $voce, float $euro, bool $sforo = false): void
{
    // La notifica della scadenza non serve a questi test: senza il listener la rotta registra la fattura e basta.
    \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $extra = ['esercizio_id' => $s['es'][$anno]->id, 'data_documento' => "{$anno}-03-10", 'data_scadenza' => "{$anno}-04-10"];
    if ($sforo) {
        $extra['dati_extra'] = ['fiscal' => [], 'competenza' => null, 'override_budget' => [
            'motivazione' => 'Sforamento della voce di prova', 'importo_sforo' => (int) round($euro * 100) - 120000, 'strategia_rientro' => 'rata_integrativa',
        ]];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, be52Fornitore(), $s[$voce]->id, $euro, $extra, "FT-{$anno}-".(int) round($euro * 100)))
        ->assertSessionHasNoErrors();
}

/**
 * La voce al suo importo di prima. La rotta delle fatture porta l'importo di una voce a quello di una fattura che la supera, con la motivazione dello
 * sforamento; qui lo si rimette, perché la voce resti più bassa delle fatture già registrate: è lo stato che la guardia del già versato riconosce.
 */
function b60eVoceRimasta(array $s, string $voce, int $cents): void
{
    \App\Models\Gestionale\Conto::whereKey($s[$voce]->id)->update(['importo' => $cents]);
}

/** Il ricalcolo del piano dalla sua pagina, dentro l'esercizio del piano. */
function b60eRicalcola($test, array $s, PianoRate $piano, string $anno)
{
    return $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['es'][$anno], $piano]));
}

/** Il messaggio della guardia quando le fatture reali della voce sono di più esercizi; l'elenco è già scritto, ognuno con la sua somma: «Esercizio 2026» (€ 1.000,00) e … */
function b60eFattureDiPiuEsercizi(string $elenco, string $somma, string $budget = '1.200,00'): string
{
    return 'Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato, e le fatture reali registrate su questa voce sono di più esercizi: '.$elenco
        .'. La loro somma (€ '.$somma.') supera il budget della voce (€ '.$budget.'), e sulla gestione usata in più esercizi il programma non divide ancora il già versato di una voce fra gli esercizi.';
}

/** Il messaggio della guardia quando le fatture reali della voce sono di un esercizio solo, diverso da quello del piano: «Esercizio 2027». */
function b60eFattureDiUnAltroEsercizio(string $esercizio, string $somma, string $budget = '1.200,00'): string
{
    return 'Impossibile generare: la voce di spesa "Spese generali" ha un già versato registrato, e le fatture reali registrate su questa voce sono di un esercizio diverso da quello di questo piano, «'.$esercizio
        .'»: la loro somma (€ '.$somma.') supera il budget della voce (€ '.$budget.'), e sulla gestione usata in più esercizi il programma non divide ancora il già versato di una voce fra gli esercizi.';
}

/** Il messaggio di sempre, quando le fatture reali della voce sono tutte dell'esercizio del piano. */
function b60eFattureDelPiano(string $somma, string $budget = '1.200,00'): string
{
    return 'Impossibile generare: la voce di spesa "Spese generali" ha un già-versato registrato, ma risulta una fattura reale (€ '.$somma.') superiore al suo budget (€ '.$budget.'). '
        .'Aggiorna prima l\'importo della voce al costo reale della spesa: altrimenti il già versato viene applicato contro un budget sbagliato, e la quota calcolata — qualunque sia il piano rate — non corrisponde a quanto realmente dovuto.';
}

it('Coda 75, decisione 168 — il già versato e le fatture di due esercizi, ognuna dentro la voce: il ricalcolo del piano del 2026 si ferma, e il messaggio nomina i due esercizi e la somma senza mandare ad alzare la voce', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1000.00);
    b60eFattura($this, $s, '2027', 'spesa', 700.00);

    // Conto a mano: € 1.000,00 del 2026 + € 700,00 del 2027 = € 1.700,00 sulla voce da € 1.200,00; nessuna fattura supera da sola la voce.
    $messaggio = b60eErrore(b60eRicalcola($this, $s, $p26, '2026'));
    expect($messaggio)->toBe('Errore durante il ricalcolo: '.b60eFattureDiPiuEsercizi('«Esercizio 2026» (€ 1.000,00) e «Esercizio 2027» (€ 700,00)', '1.700,00'))
        ->and($messaggio)->not->toContain('Aggiorna')
        ->and($messaggio)->not->toContain('importo della voce')
        // Il rifiuto non tocca le quote: Ugo € 600,00 − € 300,00 già versati + € 100,00 = € 400,00; Bice € 600,00 + € 100,00 = € 700,00.
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — il piano del 2027 creato quando le fatture di due esercizi superano la voce: il messaggio è lo stesso e il piano non nasce', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1000.00);
    b60eFattura($this, $s, '2027', 'spesa', 700.00);

    $r = b60eCrea($this, $s, '2027', 'Preventivo 2027', ['spesa' => '1.200,00', 'pulizie' => '200,00']);
    expect(b60eErrore($r))->toBe(b60eFattureDiPiuEsercizi('«Esercizio 2026» (€ 1.000,00) e «Esercizio 2027» (€ 700,00)', '1.700,00'))
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->count())->toBe(0)
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — le fatture di tre esercizi: il messaggio li nomina in ordine di data, qualunque sia l\'ordine in cui sono stati creati', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    // Il 2025 nasce per ultimo: il suo id è il più alto, la sua data la più bassa.
    $s['es']['2025'] = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['es']['2025']);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2027', 'spesa', 600.00);
    b60eFattura($this, $s, '2025', 'spesa', 300.00);
    b60eFattura($this, $s, '2026', 'spesa', 500.00);

    // Conto a mano: € 300,00 + € 500,00 + € 600,00 = € 1.400,00 sulla voce da € 1.200,00.
    expect(b60eErrore(b60eRicalcola($this, $s, $p26, '2026')))
        ->toBe('Errore durante il ricalcolo: '.b60eFattureDiPiuEsercizi('«Esercizio 2025» (€ 300,00), «Esercizio 2026» (€ 500,00) e «Esercizio 2027» (€ 600,00)', '1.400,00'));
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — una fattura pregressa con una riga sulla voce non entra nella somma né nell\'elenco degli esercizi', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1000.00);
    b60eFattura($this, $s, '2027', 'spesa', 700.00);
    // Una pregressa del 2025 con una riga sulla voce (dato scomodo: le pregresse di solito non ne hanno): la guardia le esclude dalla somma.
    $fornitore = be52Fornitore();
    $es2025 = Esercizio::create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $id = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $es2025->id, 'fornitore_id' => $fornitore->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'PREG-2025',
        'data_documento' => '2025-03-10', 'data_scadenza' => '2025-04-10', 'is_pregresso' => true, 'importo_imponibile' => 40000, 'importo_iva' => 0, 'netto_a_pagare' => 40000,
        'totale_documento' => 40000, 'stato_approvazione' => 'approvata', 'stato_pagamento' => 'da_pagare', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $id, 'conto_id' => $s['spesa']->id, 'descrizione' => 'Spesa del 2025', 'importo_imponibile' => 40000,
        'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);

    // Conto a mano: la pregressa non conta; € 1.000,00 + € 700,00 = € 1.700,00, e il 2025 non compare.
    expect(b60eErrore(b60eRicalcola($this, $s, $p26, '2026')))
        ->toBe('Errore durante il ricalcolo: '.b60eFattureDiPiuEsercizi('«Esercizio 2026» (€ 1.000,00) e «Esercizio 2027» (€ 700,00)', '1.700,00'));
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — una fattura del 2026 che supera da sola la voce e una del 2027: le fatture sono di più esercizi, e il messaggio le nomina tutte e due', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1500.00, true);
    b60eFattura($this, $s, '2027', 'spesa', 300.00);
    b60eVoceRimasta($s, 'spesa', 120000);

    // Conto a mano: € 1.500,00 + € 300,00 = € 1.800,00 sulla voce da € 1.200,00.
    expect(b60eErrore(b60eRicalcola($this, $s, $p26, '2026')))
        ->toBe('Errore durante il ricalcolo: '.b60eFattureDiPiuEsercizi('«Esercizio 2026» (€ 1.500,00) e «Esercizio 2027» (€ 300,00)', '1.800,00'));
})->with(B60E_ORDINI);

it('controprova — decisione 168: le fatture tutte dell\'esercizio del piano che superano la voce: il messaggio resta quello di prima', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1500.00, true);
    b60eVoceRimasta($s, 'spesa', 120000);

    // Conto a mano: una fattura da € 1.500,00 sulla voce da € 1.200,00, e tutte le fatture sono dell'esercizio del piano: la voce è stantia per quell'esercizio.
    expect(b60eErrore(b60eRicalcola($this, $s, $p26, '2026')))->toBe('Errore durante il ricalcolo: '.b60eFattureDelPiano('1.500,00'))
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — le fatture di un esercizio solo, diverso da quello del piano: il ricalcolo si ferma, e il messaggio dice di quale esercizio sono senza mandare ad alzare la voce', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2027', 'spesa', 1500.00, true);
    b60eVoceRimasta($s, 'spesa', 120000);

    // Conto a mano: una fattura da € 1.500,00 sulla voce da € 1.200,00, tutta del 2027; il piano è del 2026, e alzare la voce per una fattura di un altro anno non è la strada.
    $messaggio = b60eErrore(b60eRicalcola($this, $s, $p26, '2026'));
    expect($messaggio)->toBe('Errore durante il ricalcolo: '.b60eFattureDiUnAltroEsercizio('Esercizio 2027', '1.500,00'))
        ->and($messaggio)->not->toContain('Aggiorna')
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('Coda 75, decisione 168 — il piano del 2027 creato quando le fatture sono tutte del 2026 e superano la voce: il messaggio nomina il 2026, e il piano non nasce', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 1500.00, true);
    b60eVoceRimasta($s, 'spesa', 120000);

    $r = b60eCrea($this, $s, '2027', 'Preventivo 2027', ['spesa' => '1.200,00', 'pulizie' => '200,00']);
    expect(b60eErrore($r))->toBe(b60eFattureDiUnAltroEsercizio('Esercizio 2026', '1.500,00'))
        ->and(PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Preventivo 2027')->count())->toBe(0)
        ->and(b60eChiesto($p26, $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

it('controprova — decisione 168: le fatture di due esercizi che sommate uguagliano la voce non fermano il ricalcolo, e le quote restano quelle del piano', function (array $ordine) {
    $s = b60eScenario($this, $ordine);
    b60eGiaVersato($s);
    $p26 = b60ePiano($this, $s, '2026');
    b60eFattura($this, $s, '2026', 'spesa', 700.00);
    b60eFattura($this, $s, '2027', 'spesa', 500.00);

    // Conto a mano: € 700,00 + € 500,00 = € 1.200,00 = la voce; la somma non la supera, e il rifiuto è per «supera».
    $r = b60eRicalcola($this, $s, $p26, '2026');
    expect($r->getSession()->get('message')['type'] ?? null)->not->toBe('error')
        ->and(b60eErrore($r))->not->toContain('Impossibile generare')
        ->and(b60eChiesto($p26->fresh(), $s))->toBe(['ugo' => 40000, 'bice' => 70000]);
})->with(B60E_ORDINI);

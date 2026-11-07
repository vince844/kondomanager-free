<?php

/**
 * La pagina «Rate scadute» e la sua stampa, dalle rotte del pannello: una riga per persona con lo scaduto lordo (il credito accanto, solo per
 * oggi), le fasce, il ritardo in parole e la data dei sei mesi; i filtri che lavorano sulla lettura; la vista «In scadenza nei prossimi N
 * giorni»; la data dell'elenco; la riga delle rate con la scadenza passata non ancora emesse e dei saldi non in un piano; la stampa con i filtri
 * o con le persone scelte; i filtri che non si ricordano, come nelle altre pagine (le righe per pagina sì). Un condòmino non apre l'elenco;
 * una persona di un altro condominio, nella stampa o nella porta, è un 404 per tutta la richiesta.
 *
 * Centesimi; le cifre sono scritte a mano dalla regola.
 */

use App\Models\Saldo;
use App\Models\User;
use App\Services\Gestionale\Debiti\ElencoRateScadute;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
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

/** La pagina: le proprietà che manda il controller. */
function rseApri($test, array $s, array $query = []): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

/**
 * Ugo (unità A, 600) e Bice (unità B, 400): 12 rate mensili dal 05/01/2026 da € 1.200,00 l'anno; emesse le rate 1–3 il 02/01. Bice paga la
 * rata 1 con € 20,00 in più (un credito). Oggi 20/03/2026: le rate 1 (74 giorni), 2 (43), 3 (15) sono scadute.
 */
function rseScenario($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    lcdEmetti($test, $s, [1, 2, 3, 4], '2026-01-02');
    $test->travelTo('2026-03-10 10:00:00');
    lcdIncassa($test, $s, 'bice', [lcdQuota($s, 'bice', 1) => 4000], '2026-03-10', 2000);
    $test->travelTo('2026-03-20 10:00:00');

    return $s;
}

it('una riga per persona: lo scaduto lordo, le fasce, il ritardo, la data dei sei mesi, il credito accanto; in ordine per scaduto', function () {
    $s = rseScenario($this);
    $p = rseApri($this, $s);

    // Ugo: tre rate da € 60,00 scadute (74, 43, 15 giorni). Bice: rate 2 e 3 da € 40,00 (43, 15 giorni), un credito di € 20,00.
    expect(collect($p['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        ->and($p['righe']['data'][0])->toMatchArray(['importo' => 18000, 'fasce' => ['1_30' => 6000, '31_90' => 12000, 'oltre_90' => 0],
            'ritardo_testo' => 'in ritardo di 74 giorni', 'credito' => 0, 'unita' => ['Int. A']])
        ->and($p['righe']['data'][0]['sei_mesi'])->toMatchArray(['data' => '2027-06-30', 'mancano' => 0])
        ->and($p['righe']['data'][1])->toMatchArray(['importo' => 8000, 'credito' => 2000, 'ritardo_testo' => 'in ritardo di 43 giorni'])
        ->and($p['totale'])->toBe(26000)
        ->and($p['persone_totali'])->toBe(2)
        ->and($p['oggi'])->toBe('2026-03-20')
        ->and($p['filtri']['alla'])->toBe('2026-03-20')
        // La rata 4 (05/04) non è scaduta: non c'è.
        ->and(collect($p['righe']['data'][0]['rate'])->pluck('numero_rata')->all())->toBe([1, 2, 3]);
});

it('i filtri: fascia («ha qualcosa in questa fascia»), importo minimo dello scaduto lordo, persona, data dei sei mesi entro N giorni, ordine', function () {
    $s = rseScenario($this);

    expect(collect(rseApri($this, $s, ['fascia' => 'oltre_90'])['righe']['data'])->pluck('nome')->all())->toBe([])
        ->and(collect(rseApri($this, $s, ['fascia' => '31_90'])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        // € 80,00 lordi per Bice: con il minimo di € 100,00 resta Ugo; il credito non si toglie.
        ->and(collect(rseApri($this, $s, ['minimo' => '100,00'])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo'])
        ->and(collect(rseApri($this, $s, ['minimo' => '80'])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        ->and(collect(rseApri($this, $s, ['cerca' => 'bi'])['righe']['data'])->pluck('nome')->all())->toBe(['Bice'])
        ->and(collect(rseApri($this, $s, ['anagrafica_id' => $s['persone']['ugo']->id])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo'])
        // Il 30/06/2027 è a 467 giorni dal 20/03/2026.
        ->and(collect(rseApri($this, $s, ['sei_mesi_entro' => 466])['righe']['data'])->all())->toBe([])
        ->and(collect(rseApri($this, $s, ['sei_mesi_entro' => 467])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        ->and(rseApri($this, $s, ['minimo' => '100,00'])['totale'])->toBe(18000);
});

it('un valore fuori posto nei filtri torna al suo valore di serie, e una data futura diventa oggi', function () {
    $s = rseScenario($this);
    $p = rseApri($this, $s, ['fascia' => 'xx', 'minimo' => 'n.d.', 'vista' => 'altro', 'alla' => '2026-04-30', 'ordine' => 'nome', 'giorni' => '-5']);

    expect($p['filtri'])->toMatchArray(['fascia' => null, 'minimo' => null, 'vista' => 'scadute', 'alla' => '2026-03-20', 'ordine' => 'scaduto', 'giorni' => 30])
        ->and($p['persone_totali'])->toBe(2);
});

it('l\'importo minimo si legge come lo scrive un italiano: «1.000» è mille euro, non un euro; la virgola è il decimale', function () {
    $minimo = fn ($v) => app(ElencoRateScadute::class)->filtriNormalizzati(['minimo' => $v])['minimo'];

    expect($minimo('1.000'))->toBe(100000)
        ->and($minimo('1.234'))->toBe(123400)
        ->and($minimo('1.234.567'))->toBe(123456700)
        ->and($minimo('1.000,50'))->toBe(100050)
        ->and($minimo('€ 1.000'))->toBe(100000)
        ->and($minimo('12,34'))->toBe(1234)
        // Con una o due cifre dopo il punto il punto resta il decimale, come prima.
        ->and($minimo('12.5'))->toBe(1250)
        ->and($minimo('12.50'))->toBe(1250)
        ->and($minimo('50'))->toBe(5000);
});

it('a una data passata: le fasce si ricalcolano, il credito non c\'è (la lettura non lo sa), e il piede delle stampe resta nel giorno dell\'amministratore', function () {
    $s = rseScenario($this);
    $p = rseApri($this, $s, ['alla' => '2026-02-10']);

    // Al 10/02: rate 1 (36 giorni) e 2 (5 giorni) scadute; il versamento di Bice è del 10/03, quindi Bice deve anche la rata 1.
    expect($p['filtri']['alla'])->toBe('2026-02-10')
        ->and(collect($p['righe']['data'])->pluck('importo', 'nome')->all())->toBe(['Ugo' => 12000, 'Bice' => 8000])
        ->and($p['righe']['data'][0]['credito'])->toBeNull()
        ->and($p['righe']['data'][0]['fasce'])->toBe(['1_30' => 6000, '31_90' => 6000, 'oltre_90' => 0]);
});

it('la vista «In scadenza nei prossimi N giorni»: le rate emesse che scadono fra oggi e N giorni, non ancora pagate', function () {
    $s = rseScenario($this);
    $p = rseApri($this, $s, ['vista' => 'in_scadenza', 'giorni' => 30]);

    // La rata 4 (05/04/2026, emessa) per tutti e due; la 5 non è emessa.
    expect($p['filtri']['vista'])->toBe('in_scadenza')
        ->and(collect($p['righe']['data'])->pluck('importo', 'nome')->all())->toBe(['Ugo' => 6000, 'Bice' => 4000])
        ->and($p['righe']['data'][0]['prima_scadenza'])->toBe('2026-04-05')
        ->and($p['non_emesse'])->toBeNull()
        ->and(collect(rseApri($this, $s, ['vista' => 'in_scadenza', 'giorni' => 10])['righe']['data'])->all())->toBe([]);
});

it('il giorno della scadenza non è scaduto: alle 00:30 del 06/01 a Roma la rata del 05/01 è scaduta da 1 giorno, il 05/01 a mezzogiorno no', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-01-02');

    $this->travelTo(Carbon::parse('2026-01-05 12:00:00', 'Europe/Rome'));
    expect(rseApri($this, $s)['righe']['data'])->toBe([])
        ->and(collect(rseApri($this, $s, ['vista' => 'in_scadenza', 'giorni' => 5])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo']);

    $this->travelTo(Carbon::parse('2026-01-06 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-01-05');
    expect(rseApri($this, $s)['righe']['data'][0])->toMatchArray(['ritardo_testo' => 'in ritardo di 1 giorno', 'importo' => 10000]);
});

it('le rate con la scadenza passata non ancora emesse e i saldi non in un piano: una riga con il conto, non nello scaduto', function () {
    // Ugo con € 500,00 nella rata 0 in bozza e la scadenza passata; Rossi con € 100,00 nella rata 1 ordinaria in bozza e la scadenza passata;
    // Bianchi con un saldo di € 50,00 non ancora in un piano approvato.
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 400], 'B' => ['rossi', 300], 'C' => ['bianchi', 300]], 12, 'rata_zero', [['ugo', 'A', 50000]], 120000, '2026-01-05');
    // Le quote ordinarie della rata 1 scritte a mano: Rossi € 100,00, Ugo e Bianchi nulla (un dato costruito, non un calcolo).
    DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 1))->update(['importo' => 10000]);
    DB::table('rate_quote')->whereIn('id', [lcdQuota($s, 'ugo', 1), lcdQuota($s, 'bianchi', 1)])->update(['importo' => 0]);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['bianchi']->id, 'immobile_id' => $s['immobili']['C']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    $this->travelTo('2026-01-20 10:00:00');

    $p = rseApri($this, $s);
    expect($p['righe']['data'])->toBe([])
        ->and($p['totale'])->toBe(0)
        ->and($p['non_emesse'])->toBe(['persone' => 2, 'importo' => 60000, 'saldi' => 5000]);

    // Il piano in bozza: le sue rate ordinarie non contano, perché la delibera non c'è ancora. Il pregresso che ha preso dal saldo di Ugo
    // invece è un debito degli anni prima: il saldo è fermo nel piano, ma il piano non è approvato, e resta fra i saldi non ancora in un
    // piano approvato (€ 500,00 di Ugo e € 50,00 di Bianchi). È il caso dopo un'importazione: il piano nasce in bozza.
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['stato' => 'bozza']);
    expect(Saldo::where('anagrafica_id', $s['persone']['ugo']->id)->value('is_applicato'))->toBeTruthy()
        ->and(rseApri($this, $s)['non_emesse'])->toBe(['persone' => 0, 'importo' => 0, 'saldi' => 55000]);
});

it('la stampa: un PDF con i filtri o con le persone scelte; una persona di un altro condominio fa 404 per tutta la richiesta', function () {
    $s = rseScenario($this);
    $altro = lcdScenario(['A' => ['zeno', 1000]], 1, 'prima_rata');
    $rotta = fn (array $q) => route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id] + $q);

    $tutte = $this->actingAs($this->user)->get($rotta(['tutte' => 1, 'fascia' => '31_90']));
    $tutte->assertOk();
    expect($tutte->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($tutte->getContent(), 0, 4))->toBe('%PDF')
        ->and($tutte->headers->get('Content-Disposition'))->toContain('rate-scadute-');

    $this->actingAs($this->user)->get($rotta(['persone' => [$s['persone']['bice']->id]]))->assertOk();
    $this->actingAs($this->user)->get($rotta(['persone' => [$s['persone']['bice']->id, $altro['persone']['zeno']->id]]))->assertNotFound();
});

it('la vista della stampa: titolo con la data, totale lordo, la riga delle rate non emesse, il credito solo oggi, la riga in calce; nessuna firma', function () {
    $s = rseScenario($this);
    $elenco = app(ElencoRateScadute::class);
    $filtri = $elenco->filtriNormalizzati([]);
    $html = View::make('pdf.gestionale.rate_scadute', [
        'condominio' => $s['c'], 'titolo' => 'Rate scadute al 20/03/2026', 'righe' => $elenco->righe($s['c'], $filtri), 'in_scadenza' => false, 'oggi' => true,
        'totale' => 26000, 'non_emesse' => ['persone' => 2, 'importo' => 60000, 'saldi' => 5000], 'selezione' => false,
    ])->render();

    expect($html)->toContain('Rate scadute al 20/03/2026')
        ->and($html)->toContain('€ 260,00</strong>, senza togliere il credito di nessuno')
        ->and($html)->toContain('2 persone hanno € 600,00 su rate con la scadenza passata e non ancora emesse (il pregresso compreso), e € 50,00 di saldi non ancora in un piano approvato')
        ->and($html)->toContain('Credito')
        ->and($html)->toContain('€ 20,00')
        ->and($html)->toContain('in ritardo di 74 giorni')
        ->and($html)->toContain('30/06/2027')
        ->and($html)->toContain('Non va esposto in bacheca')
        // Il festivo: una scelta prudente del programma, non una regola di legge.
        ->and($html)->toContain('Se la data cade in un giorno festivo il programma non la sposta: è una scelta prudente, la data più presto. Che la legge la sposti al giorno dopo non è accertato.')
        ->and($html)->not->toContain('L\'Amministratore');
});

it('la pagina riceve la frase del giorno festivo dal server, la stessa della stampa: una scelta prudente, non una regola di legge', function () {
    $s = rseScenario($this);

    expect(rseApri($this, $s)['frase_festivo'])->toBe(\App\Services\Gestionale\Debiti\SeiMesi::FRASE_FESTIVO)
        ->and(\App\Services\Gestionale\Debiti\SeiMesi::FRASE_FESTIVO)->toBe('Se la data cade in un giorno festivo il programma non la sposta: è una scelta prudente, la data più presto. Che la legge la sposti al giorno dopo non è accertato.');
});

it('aperta e rimandata senza toccare niente: i filtri della pagina, rimandati, danno le stesse righe', function () {
    $s = rseScenario($this);
    $p = rseApri($this, $s, ['minimo' => '12,34', 'fascia' => '1_30', 'ordine' => 'sei_mesi']);
    // Il minimo torna in euro come lo rimanda la pagina (cents / 100, con la virgola).
    $rimandati = $p['filtri'];
    $rimandati['minimo'] = str_replace('.', ',', (string) ($p['filtri']['minimo'] / 100));
    $q = array_filter($rimandati, fn ($v) => $v !== null && $v !== false);

    expect(rseApri($this, $s, $q)['righe']['data'])->toBe($p['righe']['data'])
        ->and(rseApri($this, $s, $q)['filtri'])->toBe($p['filtri']);
});

it('i filtri non si ricordano, come nelle altre pagine: chi rientra senza filtri nell\'indirizzo vede tutte le persone; si ricordano le righe per pagina', function () {
    $s = rseScenario($this);
    expect(collect(rseApri($this, $s, ['fascia' => '31_90', 'minimo' => '100', 'alla' => '2026-02-10'])['righe']['data'])->pluck('nome')->all())->toBe(['Ugo']);

    // Le righe per pagina si scelgono dentro il programma (una richiesta Inertia), e quella scelta resta.
    $this->actingAs($this->user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])
        ->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'fascia' => '31_90', 'per_page' => 20]))->assertOk();
    $this->flushHeaders();

    $dopo = rseApri($this, $s);
    expect($dopo['filtri'])->toMatchArray(['fascia' => null, 'minimo' => null, 'alla' => '2026-03-20'])
        ->and(collect($dopo['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        ->and($dopo['righe']['meta']['per_page'])->toBe(20)
        ->and($dopo)->not->toHaveKey('filtri_ricordati')
        ->and(collect(session()->all())->keys()->filter(fn ($k) => str_starts_with((string) $k, 'rate_scadute'))->values()->all())->toBe([]);
});

it('le porte aprono l\'elenco con i soli parametri che portano: la Tesoreria senza filtri, l\'estratto conto con la sola persona', function () {
    $s = rseScenario($this);
    rseApri($this, $s, ['fascia' => 'oltre_90', 'minimo' => '500']);

    $tesoreria = app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->elencoRateScadute;
    $daTesoreria = $this->actingAs($this->user)->get(route($tesoreria['route'], $tesoreria['routeParams']))->assertOk()->viewData('page')['props'];
    expect(collect($daTesoreria['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice'])
        ->and($daTesoreria['filtri'])->toMatchArray(['fascia' => null, 'minimo' => null, 'anagrafica_id' => null]);

    rseApri($this, $s, ['fascia' => 'oltre_90']);
    $dallEstratto = rseApri($this, $s, ['anagrafica_id' => $s['persone']['bice']->id]);
    expect(collect($dallEstratto['righe']['data'])->pluck('nome')->all())->toBe(['Bice'])
        ->and($dallEstratto['filtri'])->toMatchArray(['fascia' => null, 'anagrafica_id' => (int) $s['persone']['bice']->id])
        ->and($dallEstratto['persona_della_porta'])->toBe('Bice');
    // Tolta la persona, tutte: niente di quello che c'era prima è rimasto.
    expect(collect(rseApri($this, $s)['righe']['data'])->pluck('nome')->all())->toBe(['Ugo', 'Bice']);
});

it('la persona della porta di un altro condominio fa 404 per tutta la richiesta, nella pagina e nella stampa; una del condominio passa', function () {
    $s = rseScenario($this);
    $altro = lcdScenario(['A' => ['zeno', 1000]], 1, 'prima_rata');
    $zeno = $altro['persone']['zeno']->id;

    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'anagrafica_id' => $zeno]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1, 'anagrafica_id' => $zeno]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'anagrafica_id' => 999999]))->assertNotFound();
    expect(rseApri($this, $s, ['anagrafica_id' => $s['persone']['bice']->id])['persona_della_porta'])->toBe('Bice');
});

it('un condòmino non apre l\'elenco né la stampa: le rotte stanno solo nel pannello', function () {
    $s = rseScenario($this);
    $fuori = User::factory()->create();

    $this->actingAs($fuori)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertForbidden();
    $this->actingAs($fuori)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]))->assertForbidden();
    auth()->logout();
    $this->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertRedirect(route('login'));
    expect(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'rate-scadute'))
        ->map(fn ($r) => $r->uri())->values()->all())->each->toStartWith('admin/gestionale/{condominio}/');
});

it('le persone: chi ha appartamento e box ha una riga sola con le due unità; chi ha solo un credito non c\'è; il pregresso dentro la rata 1 resta una rata, e la riga lo dice', function () {
    // Ugo ha A (600) e il box G (200), Bice B (200). Il piano porta il pregresso nella prima rata: Bice ha € 30,00 di saldo.
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 200], 'B' => ['bice', 200]], 12, 'prima_rata', [['bice', 'B', 3000]], 120000);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-01-20 10:00:00');

    $p = rseApri($this, $s);
    $ugo = collect($p['righe']['data'])->firstWhere('nome', 'Ugo');
    $bice = collect($p['righe']['data'])->firstWhere('nome', 'Bice');
    // La rata 1 di Bice: € 20,00 dell'anno e € 30,00 di pregresso, nella stessa quota: 15 giorni, come la rata.
    expect($ugo['unita'])->toEqualCanonicalizing(['Int. A', 'Int. G'])
        ->and($ugo['importo'])->toBe(8000)
        ->and($bice['importo'])->toBe(5000)
        ->and($bice['rate'][0])->toMatchArray(['giorni_ritardo' => 15, 'pregresso' => null, 'pregresso_nella_rata' => true])
        ->and($bice['pregresso_nella_rata'])->toBeTrue()
        ->and($ugo['rate'][0]['pregresso_nella_rata'])->toBeFalse();
    // «Con pregresso» trova anche chi l'ha dentro la rata 1: Bice sì, Ugo no.
    expect(collect(rseApri($this, $s, ['con_pregresso' => 1])['righe']['data'])->pluck('nome')->all())->toBe(['Bice']);

    // Bice paga la rata e € 10,00 in più: resta con il solo credito, e non è nell'elenco.
    lcdIncassa($this, $s, 'bice', [lcdQuota($s, 'bice', 1) => 5000], '2026-01-20', 1000);
    expect(collect(rseApri($this, $s)['righe']['data'])->pluck('nome')->all())->toBe(['Ugo']);
});

<?php

/**
 * Il secondo momento nella scheda «Conguaglio» e nella stampa del conguaglio per persona: dopo l'approvazione e la scrittura del conguaglio i crediti verso i
 * condòmini sono i crediti di oggi (letti dalla situazione patrimoniale), meno le spese personali che il conguaglio chiede (oggi sono già fra i crediti, come
 * spese anticipate per singole unità), più i conguagli a debito; i debiti verso i condòmini per il conguaglio sono i conguagli a credito. Righe, non una
 * situazione patrimoniale; nessuna promessa che il programma lo scriva.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`) con il passaggio di U2 e il citofono di U3 (€ 80,00). Le cifre a mano nei commenti.
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

function smxCaso($test, int $pulizie = 330000): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    cpcSpese($s, $pulizie);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo('2027-01-10 10:00:00');

    return $s;
}

it('i conguagli a debito: crediti di oggi 380,00 − spese personali 80,00 + conguagli a debito 280,00 = 580,00; nessun debito', function () {
    $s = smxCaso($this);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $situazione = app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e']->fresh());

    // I crediti di oggi: Neri deve 300,00 (1.700,00 versati su 2.000,00) e il citofono di U3, 80,00, è una spesa anticipata: 380,00, la cifra della situazione.
    // I conguagli: Neri +100,00, Bianchi +29,75, Rossi +30,25, Verdi +120,00 (40,00 + 80,00 di citofono): a debito 280,00, a credito 0.
    expect($p['secondo_momento'])->toMatchArray(['crediti_oggi' => 38000, 'personali' => 8000, 'a_debito' => 28000, 'crediti_dopo' => 58000, 'a_credito' => 0])
        ->and($p['secondo_momento']['crediti_oggi'])->toBe($situazione['sintesi']['crediti_condomini'])
        ->and($p['secondo_momento']['frase'])->toBe('Dopo l\'approvazione e la scrittura del conguaglio: crediti verso i condòmini € 580,00 (oggi € 380,00, meno le spese personali che il conguaglio chiede € 80,00, più i conguagli a debito € 280,00); debiti verso i condòmini per i conguagli a credito € 0,00.');
});

it('i conguagli a credito: tutti sotto zero, i crediti restano quelli delle rate e i debiti sono la somma dei conguagli, 620,00', function () {
    $s = smxCaso($this, 240000);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    // Pulizie 2.400,00: Neri 1.200,00 + 450,00 − 2.000,00 = −350,00; U2 720,00 per giorni, Bianchi 72.000 × 181/365 = 35.704,11 → 357,04, Rossi 362,96;
    // Bianchi 357,04 + 133,89 − 595,07 = −104,14; Rossi 362,96 + 136,11 − 604,93 = −105,86; Verdi 480,00 + 180,00 + 80,00 − 800,00 = −60,00.
    // A credito 620,00 (= quote 4.000,00 − spese 3.300,00 − citofono 80,00); crediti dopo: 380,00 − 80,00 + 0 = 300,00, la rata di Neri.
    expect($p['secondo_momento'])->toMatchArray(['crediti_oggi' => 38000, 'personali' => 8000, 'a_debito' => 0, 'crediti_dopo' => 30000, 'a_credito' => 62000]);
});

it('la stampa del conguaglio per persona ha la riga del secondo momento, senza promettere che il programma scriva il conguaglio', function () {
    $s = smxCaso($this);
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.stampa', [$s['c'], $s['e']]))->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $servizio = app(ConguaglioDelRendiconto::class);
    $fuori = app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh())['fuori_capitoli'];
    $righe = $servizio->righe($s['c'], $s['e']->fresh(), $servizio->leggi($s['e']->fresh()), $fuori, true);
    $testo = vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render());

    // La stampa per i condòmini dice il secondo momento con le sue parole: le stesse cifre (€ 580,00 di crediti dopo, nessun debito).
    expect($testo)->toContain('Se l\'assemblea approva, i condòmini avranno da versare € 580,00, tra le rate già chieste e i conguagli a debito.')
        ->and($testo)->not->toMatch('/il programma (scriver|lo scrive)/u');
});

it('le righe non disponibili non contano: con U2 non disponibile restano Neri e Verdi, e la frase lo dice', function () {
    $s = smxCaso($this);
    // Una riga del riparto di U2 cambiata dopo il piano: le righe di Bianchi e Rossi su U2 non sono disponibili.
    $riga = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']['U2']->id)->where('tipo', 'riparto')->orderBy('id')->value('id');
    DB::table('righe_riparto')->where('id', $riga)->increment('importo', 1000);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    // A debito Neri 100,00 e Verdi 120,00 = 220,00; crediti dopo 380,00 − 80,00 + 220,00 = 520,00.
    expect($p['secondo_momento'])->toMatchArray(['crediti_oggi' => 38000, 'personali' => 8000, 'a_debito' => 22000, 'crediti_dopo' => 52000, 'a_credito' => 0])
        ->and($p['secondo_momento']['frase'])->toEndWith('debiti verso i condòmini per i conguagli a credito € 0,00. Le righe non disponibili (2) non sono contate.');
});

/** La somma dei saldi delle righe della scheda (le persone di tutte le gestioni e la riga da altre gestioni), senza i subtotali né le righe non disponibili. */
function smxSommaDeiSaldi(array $p): int
{
    $righe = array_merge($p['righe'], $p['da_altre_gestioni']['righe'] ?? []);

    return (int) array_sum(array_map(fn ($r) => (int) $r['saldo'], array_filter($righe, fn ($r) => ! $r['subtotale'] && $r['saldo'] !== null)));
}

it('con la rata 0 di Verdi non emessa il secondo momento conta i saldi non ancora a giornale: crediti dopo − debiti = la somma dei saldi, 530,00', function () {
    $s = cpcViaVerdi($this, ['metodo' => 'rata_zero']);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $this->travelTo('2027-01-10 10:00:00');
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    // Il credito di € 50,00 di Verdi è nella rata 0, non emessa: non è a giornale (i crediti di oggi restano 380,00) ma è nel suo saldo. Prima: 580,00 contro
    // la somma dei saldi 530,00.
    expect($p['secondo_momento'])->toMatchArray(['crediti_oggi' => 38000, 'fuori' => -5000, 'crediti_dopo' => 53000, 'a_credito' => 0])
        ->and($p['secondo_momento']['crediti_dopo'] - $p['secondo_momento']['a_credito'])->toBe(smxSommaDeiSaldi($p))
        ->and($p['secondo_momento']['frase'])->toContain('più i saldi non ancora a giornale € -50,00');
});

it('con un saldo scritto a mano non in un piano (Verdi −30,00): crediti dopo 550,00 = la somma dei saldi', function () {
    $s = smxCaso($this);
    DB::table('saldi')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'anagrafica_id' => $s['persone']['Verdi']->id,
        'immobile_id' => $s['unita']['U3']->id, 'saldo_iniziale' => -3000, 'is_applicato' => false, 'origine' => 'manuale', 'created_at' => now(), 'updated_at' => now()]);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    // 380,00 − 80,00 + 280,00 − 30,00 = 550,00; la somma dei saldi di Via Verdi (580,00) con i −30,00 di Verdi.
    expect($p['secondo_momento'])->toMatchArray(['fuori' => -3000, 'crediti_dopo' => 55000])
        ->and($p['secondo_momento']['crediti_dopo'] - $p['secondo_momento']['a_credito'])->toBe(smxSommaDeiSaldi($p));
});

it('i crediti di oggi sono quelli alla data della lettura, non di oggi: un incasso scritto dopo la fine dell\'esercizio non li cambia', function () {
    $s = smxCaso($this);
    // Il 05/01/2027 Neri versa i suoi € 300,00: sul giornale dopo il 31/12/2026, la data del rendiconto.
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2027-01-05',
        'data_competenza' => '2027-01-05', 'numero_protocollo' => 'SMX-1', 'causale' => 'incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => (int) $s['cassa']->conto_contabile_id, 'anagrafica_id' => null, 'tipo_riga' => 'dare', 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $sc, 'conto_contabile_id' => (int) $s['crediti']->id, 'anagrafica_id' => $s['persone']['Neri']->id, 'tipo_riga' => 'avere', 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    expect($p['secondo_momento']['crediti_oggi'])->toBe(38000);
});

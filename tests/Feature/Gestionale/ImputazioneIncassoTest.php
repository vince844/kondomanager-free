<?php

/**
 * L'imputazione dichiarata di un incasso (decisioni 217 e 218): l'ordine che il programma propone, per scadenza della rata, emesse o
 * no, a pari scadenza la rata 0 (il pregresso), poi le emesse, poi le bozze, poi l'id della rata; sulle quote a debito della persona
 * in tutte le gestioni e in tutte le sue unità. Il salvataggio rifà il calcolo e scrive che cosa ha visto: `ordine_scadenze`,
 * `indicata_dal_versante`, `scelta_amministratore` (con la nota), `fuori_ordine` (non dichiarata), `compensazione` (solo credito).
 * Non rifiuta un incasso fuori ordine. La rotta di sola lettura dice al modulo l'esito prima di salvare, con la rata più vecchia
 * che resta aperta.
 *
 * Le cifre vengono dalla regola: rate da € 100,00 (10000) con scadenze 05/01, 05/02, 05/03 del 2026, salvo dove il caso dice altro.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
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

/** Ugo, un appartamento, tre rate da 10000 (05/01, 05/02, 05/03), emesse quelle date. */
function oiScenario($test, array $emesse = [1, 2, 3], array $unita = ['A' => ['ugo', 1000]], string $metodo = 'prima_rata', array $saldi = []): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario($unita, 3, $metodo, $saldi, 30000, '2026-01-05');
    if ($emesse !== []) {
        lcdEmetti($test, $s, $emesse, '2026-01-01');
    }

    return $s;
}

/** Il corpo della richiesta come lo manda il modulo: [id quota => centesimi], la parte in più, i campi in più. */
function oiCorpo(array $s, string $chi, array $righe, int $parteInPiu = 0, array $extra = []): array
{
    $totale = max(0, array_sum($righe) + $parteInPiu);

    return $extra + [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-20',
        'importo_totale' => number_format($totale / 100, 2, '.', ''), 'descrizione' => 'Bonifico',
        'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''),
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ];
}

/** Registra l'incasso dalla rotta della pagina e restituisce la scrittura (o null se non è stata scritta). */
function oiIncassa($test, array $s, string $chi, array $righe, int $parteInPiu = 0, array $extra = []): ?object
{
    $prima = (int) DB::table('scritture_contabili')->max('id');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), oiCorpo($s, $chi, $righe, $parteInPiu, $extra));

    return DB::table('scritture_contabili')->where('id', '>', $prima)->where('tipo_movimento', 'incasso_rata')->orderByDesc('id')->first();
}

/** L'esito della rotta di sola lettura per lo stesso corpo. */
function oiControlla($test, array $s, string $chi, array $righe, int $parteInPiu = 0, array $extra = []): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$s['c']]), oiCorpo($s, $chi, $righe, $parteInPiu, $extra))
        ->assertOk()->json();
}

it('secondo l\'ordine: la rata 1 saldata e metà della 2; chi l\'ha registrato è scritto sull\'incasso', function () {
    $s = oiScenario($this);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000, lcdQuota($s, 'ugo', 2) => 5000]);
    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000, lcdQuota($s, 'ugo', 2) => 5000]);

    expect($esito)->toMatchArray(['esito' => 'ordine_scadenze', 'rata_aperta' => null, 'parte_in_piu' => false])
        ->and($incasso->imputazione)->toBe('ordine_scadenze')
        ->and($incasso->imputazione_nota)->toBeNull()
        ->and((int) $incasso->created_by)->toBe((int) $this->user->id);
});

it('fuori ordine senza scelta: l\'incasso si registra, segnato «fuori_ordine»; la rotta dice la rata più vecchia che resta aperta', function () {
    $s = oiScenario($this);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000]);
    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['rata_id' => lcdRata($s, 1), 'numero_rata' => 1, 'scadenza' => '2026-01-05', 'resta' => 10000, 'gestione' => 'Ordinaria 2026', 'unita' => 'Int. A'])
        ->and($incasso)->not->toBeNull()
        ->and($incasso->imputazione)->toBe('fuori_ordine')
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 2))->value('importo_pagato'))->toBe(10000);
});

it('fuori ordine indicata da chi ha versato, con la nota; scelta dall\'amministratore con la nota', function () {
    $s = oiScenario($this);

    $indicata = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000], 0, ['imputazione_scelta' => 'indicata_dal_versante', 'imputazione_nota' => 'Causale del bonifico: rata di febbraio']);
    $scelta = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 10000], 0, ['imputazione_scelta' => 'scelta_amministratore', 'imputazione_nota' => 'Accordo in assemblea']);

    expect([$indicata->imputazione, $indicata->imputazione_nota])->toBe(['indicata_dal_versante', 'Causale del bonifico: rata di febbraio'])
        ->and([$scelta->imputazione, $scelta->imputazione_nota])->toBe(['scelta_amministratore', 'Accordo in assemblea']);
});

it('la nota è facoltativa per tutte e due le scelte (decisione 219): «scelta dall\'amministratore» e «indicata da chi ha versato» senza nota si registrano', function () {
    $s = oiScenario($this);

    $scelta = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000], 0, ['imputazione_scelta' => 'scelta_amministratore', 'imputazione_nota' => '  ']);
    expect([$scelta->imputazione, $scelta->imputazione_nota])->toBe(['scelta_amministratore', null]);

    $indicata = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 10000], 0, ['imputazione_scelta' => 'indicata_dal_versante']);
    expect([$indicata->imputazione, $indicata->imputazione_nota])->toBe(['indicata_dal_versante', null]);
});

it('una scelta mandata con una distribuzione che segue l\'ordine non cambia il fatto: «ordine_scadenze», senza nota', function () {
    $s = oiScenario($this);

    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], 0, ['imputazione_scelta' => 'indicata_dal_versante', 'imputazione_nota' => 'Rata di gennaio']);

    expect([$incasso->imputazione, $incasso->imputazione_nota])->toBe(['ordine_scadenze', null]);
});

it('la parte in più che diventa credito con rate aperte dopo è fuori ordine; con tutte le rate saldate no', function () {
    $s = oiScenario($this);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], 5000);
    $conRate = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], 5000);
    $tutte = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000, lcdQuota($s, 'ugo', 3) => 10000], 2000);

    expect($esito)->toMatchArray(['esito' => 'fuori_ordine', 'parte_in_piu' => true])
        ->and($esito['rata_aperta']['numero_rata'])->toBe(2)
        ->and($conRate->imputazione)->toBe('fuori_ordine')
        // La parte in più del primo incasso resta come credito sulla rata 1 (l'ultima quota toccata); il secondo salda le rate 2 e 3,
        // le sole aperte, e i suoi 2000 in più non lasciano niente aperto dopo: secondo l'ordine.
        ->and($tutte->imputazione)->toBe('ordine_scadenze');
});

it('un incasso fatto solo di credito è «compensazione», non «secondo l\'ordine»', function () {
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 1000]], 'rata_zero', [['ugo', 'A', -5000]]);

    // Il credito riportato della rata 0 (in bozza) copre metà della rata 2, con la rata 1 aperta davanti: è sempre una compensazione.
    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 2) => 5000]);
    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 2) => 5000]);

    expect($incasso->imputazione)->toBe('compensazione')
        ->and($esito['esito'])->toBe('compensazione')
        ->and((int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'storno_credito')->value('created_by'))->toBe((int) $this->user->id);
});

it('la rata 1 in bozza, più vecchia della rata 2 emessa, viene prima: pagarla è secondo l\'ordine, pagare la 2 no', function () {
    $s = oiScenario($this, [2]);

    expect(oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000])['rata_aperta']['numero_rata'])->toBe(1);
    $prima = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000]);

    expect($prima->imputazione)->toBe('ordine_scadenze');
});

it('a pari scadenza la rata 0 viene prima, anche con le quote a «ordinaria» dei piani di prima; la gemella di passaggio sulla rata 3 segue la sua scadenza', function () {
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 1000]], 'rata_zero', [['ugo', 'A', 5000]]);
    expect(substr((string) DB::table('rate')->where('id', lcdRata($s, 0))->value('data_scadenza'), 0, 10))->toBe('2026-01-05');

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000]);
    DB::table('rate_quote')->where('rata_id', lcdRata($s, 0))->update(['tipo' => 'ordinaria']);
    $piano18 = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000]);
    // La gemella che il passaggio scrive su qualunque rata: `saldo_iniziale` sulla rata 3 non la porta davanti.
    DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 3))->update(['tipo' => 'saldo_iniziale']);
    $gemella = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => 5000, lcdQuota($s, 'ugo', 1) => 10000]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['numero_rata' => 0, 'resta' => 5000])
        ->and($piano18['rata_aperta']['numero_rata'])->toBe(0)
        ->and($gemella['esito'])->toBe('ordine_scadenze');
});

it('una rata più vecchia aperta su un\'altra unità della persona (la ricerca per unità non la carica): fuori ordine, e la rotta la nomina', function () {
    // Appartamento 600, box 400: la rata 1 di Ugo è 6000 + 4000. Il primo incasso (6000 sulla rata 1) salda la quota più grande,
    // l'appartamento; resta il box. Il secondo, dalla ricerca per l'appartamento, paga la rata 2.
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 600], 'G' => ['ugo', 400]]);
    $quotaA = fn (int $n) => (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, $n))->where('immobile_id', $s['immobili']['A']->id)->value('id');
    $primo = oiIncassa($this, $s, 'ugo', [$quotaA(1) => 6000]);
    expect($primo->imputazione)->toBe('ordine_scadenze');

    $esito = oiControlla($this, $s, 'ugo', [$quotaA(2) => 6000]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['numero_rata' => 1, 'unita' => 'Int. G', 'resta' => 4000]);
});

it('una rata più vecchia aperta in un\'altra gestione: fuori ordine, e la rotta dice la gestione', function () {
    $s = oiScenario($this);
    $straordinaria = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Rifacimento tetto', 'tipo' => 'straordinaria', 'data_inizio' => '2025-11-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $straordinaria->id);
    $piano = PianoRate::create(['gestione_id' => $straordinaria->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Tetto', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2025-12-05', 'metodo_distribuzione' => 'prima_rata']);
    $rata = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2025-12-05', 'descrizione' => 'Rata n.1 - Tetto', 'importo_totale' => 20000, 'stato' => 'bozza']);
    RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id, 'importo' => 20000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2025-12-05']);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], 0, ['gestione_id' => $s['g']->id]);
    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], 0, ['gestione_id' => $s['g']->id]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['rata_id' => $rata->id, 'gestione' => 'Rifacimento tetto', 'scadenza' => '2025-12-05', 'resta' => 20000])
        ->and($incasso->imputazione)->toBe('fuori_ordine');
});

it('conta il denaro, non il credito: il credito che copre la rata 2 mentre il denaro lascia aperta metà della rata 1 è secondo l\'ordine', function () {
    // Credito riportato di Ugo 10000 (rata 0, in bozza). Righe: rata 1 per 5000, rata 2 per 10000; versati 5000. Il pianificatore mette
    // il credito sulle ultime righe (la rata 2) e il denaro dalla prima: la rata 1 riceve 5000 di denaro e resta aperta per 5000; la
    // rata 2, dopo di lei, riceve solo credito.
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 1000]], 'rata_zero', [['ugo', 'A', -10000]]);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -10000, lcdQuota($s, 'ugo', 1) => 5000, lcdQuota($s, 'ugo', 2) => 10000]);
    $incasso = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -10000, lcdQuota($s, 'ugo', 1) => 5000, lcdQuota($s, 'ugo', 2) => 10000]);

    expect($esito['esito'])->toBe('ordine_scadenze')
        ->and($incasso->imputazione)->toBe('ordine_scadenze')
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->value('importo_pagato'))->toBe(5000)
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 2))->value('importo_pagato'))->toBe(10000);
});

it('a pari scadenza la rata emessa viene prima della bozza, anche se la bozza ha l\'id più basso', function () {
    // La rata 2 del piano (05/02) resta in bozza; un'altra gestione ha una rata emessa con la stessa scadenza, creata dopo.
    $s = oiScenario($this, [1, 3]);
    oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000]);
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $altra->id);
    $piano = PianoRate::create(['gestione_id' => $altra->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'metodo_distribuzione' => 'prima_rata']);
    $emessa = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-02-05', 'descrizione' => 'Rata n.1 - Riscaldamento', 'importo_totale' => 8000, 'stato' => 'emessa']);
    // La quota a giornale: la lega una scrittura di emissione (qui quella del piano, che basta all'ordine).
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 1))->value('scrittura_contabile_id');
    RataQuote::create(['rata_id' => $emessa->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id, 'importo' => 8000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-02-05', 'scrittura_contabile_id' => $emissione]);
    expect(lcdRata($s, 2))->toBeLessThan($emessa->id)
        ->and($emissione)->toBeGreaterThan(0);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['rata_id' => $emessa->id, 'emessa' => true, 'gestione' => 'Riscaldamento', 'resta' => 8000]);
});

it('la situazione debitoria manda le righe a debito nello stesso ordine che il salvataggio usa', function () {
    $s = oiScenario($this, [1], ['A' => ['ugo', 1000]], 'rata_zero', [['ugo', 'A', 5000]]);
    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone']['ugo']->id]))->assertOk()->json('rate');

    $dallaSituazione = collect($righe)->filter(fn ($r) => $r['ordine'] !== null)->sortBy('ordine')->pluck('rata_padre_id')->values()->all();
    $dallOrdine = array_column(app(OrdineImputazione::class)->rateAperte((int) $s['c']->id, (int) $s['persone']['ugo']->id), 'rata_id');

    // La rata 0 e la rata 1 (emessa) il 05/01, poi le bozze 2 e 3.
    expect($dallOrdine)->toBe([lcdRata($s, 0), lcdRata($s, 1), lcdRata($s, 2), lcdRata($s, 3)])
        ->and($dallaSituazione)->toBe($dallOrdine);
});

it('la rotta di sola lettura non scrive niente e risponde solo per un condominio dove la persona è', function () {
    $s = oiScenario($this);
    $altro = oiScenario($this);
    $prima = [DB::table('scritture_contabili')->count(), DB::table('quota_scrittura')->count(), DB::table('rate_quote')->sum('importo_pagato')];

    oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000]);
    // Ugo e la sua quota sono dell'altro condominio: la richiesta non passa la validazione, come il salvataggio.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.movimenti-rate.ordine', [$altro['c']]), oiCorpo($s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000]))
        ->assertStatus(422);

    expect([DB::table('scritture_contabili')->count(), DB::table('quota_scrittura')->count(), DB::table('rate_quote')->sum('importo_pagato')])->toBe($prima);
});

/** Il riparto automatico del modulo sulle righe della situazione debitoria, nell'ordine ricevuto: ognuna fino al suo residuo (netto della rata). */
function oiRipartoDelModulo($test, array $s, string $chi, int $budget): array
{
    $righe = $test->actingAs($test->user)->getJson(route('admin.gestionale.situazione-debitoria', [$s['c'], 'anagrafica_id' => $s['persone'][$chi]->id]))->assertOk()->json('rate');
    $riparto = [];
    foreach ($righe as $r) {
        $residuo = (int) round(((float) $r['residuo']) * 100);
        if ($residuo <= 0 || $budget <= 0) {
            continue;
        }
        $riparto[(int) $r['id']] = min($budget, $residuo);
        $budget -= $riparto[(int) $r['id']];
    }

    return $riparto;
}

it('la rata 0 mista della stessa persona (appartamento a debito, box a credito): il riparto che il modulo propone sul netto della rata è secondo l\'ordine', function () {
    // Rata 0: appartamento +10000, box −3000, netto 7000; rata 1: 6000 + 4000. Il modulo propone 7000 sulla rata 0 e 10000 sulla rata 1.
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 600], 'G' => ['ugo', 400]], 'rata_zero', [['ugo', 'A', 10000], ['ugo', 'G', -3000]]);
    $riparto = oiRipartoDelModulo($this, $s, 'ugo', 17000);

    $esito = oiControlla($this, $s, 'ugo', $riparto);
    $incasso = oiIncassa($this, $s, 'ugo', $riparto);

    expect($esito['esito'])->toBe('ordine_scadenze')
        ->and($esito['rata_aperta'])->toBeNull()
        ->and($incasso->imputazione)->toBe('ordine_scadenze');
});

it('la rata 0 mista a netto zero (la riga a € 0,00): pagare la rata 1 è secondo l\'ordine', function () {
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 600], 'G' => ['ugo', 400]], 'rata_zero', [['ugo', 'A', 3000], ['ugo', 'G', -3000]]);
    $riparto = oiRipartoDelModulo($this, $s, 'ugo', 10000);

    expect(oiControlla($this, $s, 'ugo', $riparto)['esito'])->toBe('ordine_scadenze');
});

it('una rata davvero aperta dopo il netto: «resta» dice il netto della persona sulla rata, non il lordo delle quote a debito', function () {
    // Rata 0 netto 7000; si paga solo la rata 1: fuori ordine, resta aperta la rata 0 per 7000.
    $s = oiScenario($this, [1, 2, 3], ['A' => ['ugo', 600], 'G' => ['ugo', 400]], 'rata_zero', [['ugo', 'A', 10000], ['ugo', 'G', -3000]]);

    $esito = oiControlla($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000]);

    expect($esito['esito'])->toBe('fuori_ordine')
        ->and($esito['rata_aperta'])->toMatchArray(['numero_rata' => 0, 'resta' => 7000]);
});

it('l\'elenco degli incassi filtra «fuori ordine, non dichiarata»: solo quelli, non annullati, e il conteggio è il loro', function () {
    $s = oiScenario($this);
    $nellOrdine = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 5000]);
    $fuori = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 2000]);
    $dichiarato = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 2000], 0, ['imputazione_scelta' => 'indicata_dal_versante']);
    $stornato = oiIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 3) => 2000]);
    lcdStorna($this, $s, (int) $stornato->id);
    expect([$nellOrdine->imputazione, $fuori->imputazione, $dichiarato->imputazione, $stornato->imputazione])->toBe(['ordine_scadenze', 'fuori_ordine', 'indicata_dal_versante', 'fuori_ordine']);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c'], 'stato' => 'fuori_ordine']))->assertOk()->viewData('page')['props'];

    expect(array_column($props['movimenti']['data'], 'id'))->toBe([(int) $fuori->id])
        ->and($props['movimenti']['total'])->toBe(1)
        ->and($props['stati'])->toContain('fuori_ordine');
});

<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\SaldoCassaService;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** Una cassa banca "vergine" (nessuna apertura, nessun movimento) per il condominio. */
function cvCreaCassa(int $condominioId): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => 'conto_bancario',
        'codice' => 'BANCA.'.uniqid(), 'nome' => 'Banca Test',
        'tipo' => 'attivo', 'categoria' => 'liquidita',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Cassa::create([
        'condominio_id' => $condominioId, 'nome' => 'Banca Test',
        'tipo' => 'banca', 'conto_contabile_id' => $contoId,
        'saldo_iniziale' => 0, 'attiva' => true,
    ]);
}

/** Un fondo (vincolato o generico) per il condominio, con la sua governance. */
function cvCreaFondo(int $condominioId, string $sottotipoFondo): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => 'fondo_speciale',
        'codice' => 'FONDO.'.uniqid(), 'nome' => 'Fondo Test',
        'tipo' => 'attivo', 'categoria' => 'liquidita',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Cassa::create([
        'condominio_id' => $condominioId, 'nome' => 'Fondo Test',
        'tipo' => 'fondo', 'sottotipo_fondo' => $sottotipoFondo,
        'conto_contabile_id' => $contoId, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);
}

/**
 * Ciclo end-to-end della schermata "Già versato": l'amministratore apre la pagina,
 * inserisce quanto ciascuna unità ha versato, salva — e il motore di riparto
 * inizia a chiedere solo il residuo.
 */

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Voce di spesa da €1.100 su due unità da 500 millesimi. */
function cvScenario(array $ctx): object
{
    [$condominio, $esercizio, $gestione] = $ctx;

    $pianoConto = DB::table('piani_conti')->where('gestione_id', $gestione->id)->first();

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Millesimi Proprietà',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Lavori ristrutturazione',
        'importo'        => 110_000,
        'tipo'           => 'spesa',
        'attivo'         => true,
        // Marcata "da esercizio precedente" (beta.27): senza questo flag
        // esplicito la voce non comparirebbe nell'elenco "Già versato".
        'richiede_gia_versato' => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario',
        'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $unita = [];
    foreach ([1, 2] as $n) {
        $immobile = Immobile::forceCreate([
            'condominio_id' => $condominio->id,
            'nome' => "Interno $n", 'descrizione' => 'App', 'interno' => (string) $n,
        ]);
        $an = Anagrafica::forceCreate([
            'nome' => "Proprietario $n", 'email' => "cv$n@example.com",
            'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'CVTEST'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $an->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true,
            'data_inizio' => now()->format('Y-m-d'),
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id,
            'valore' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unita[] = (object) ['immobile' => $immobile, 'anagrafica' => $an];
    }

    return (object) compact('condominio', 'gestione', 'conto', 'unita');
}

test('la pagina mostra unità, millesimi e quota dovuta', function () {
    $sc = cvScenario(setupContabile());

    $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/contributi/ContributiEdit')
            ->where('voce.importo_cents', 110_000)
            ->has('righe', 2)
            // €550 dovuti da ciascuna delle due unità da 500 millesimi
            ->where('righe.0.quota_lorda', 55_000)
            ->where('righe.0.gia_versato', 0)
            // Ripartizione di default e proprietari tutti attivi: la stima coincide
            // col motore, nessun avviso.
            ->where('stima_semplificata', false)
            ->where('ripartizione_mista', false)
        );
})->group('contributi', 'http');

/**
 * REGRESSIONE: revisione avversariale beta.27. round() indipendente per riga
 * poteva far sì che la somma delle "Quote dovute" mostrate in pagina non
 * coincidesse nemmeno con l'importo della voce.
 */
test('la somma delle quote lorde mostrate coincide sempre con l\'importo della voce', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();
    $pianoConto = DB::table('piani_conti')->where('gestione_id', $gestione->id)->first();

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Millesimi', 'tipo' => 'standard',
        'quota' => 'millesimi', 'attiva' => true,
    ]);
    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'Voce Test', 'importo' => 7_777,
        'tipo' => 'spesa', 'attivo' => true,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id,
        'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario',
        'percentuale' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Tre unità, millesimi 333/333/334: il caso che con round() indipendente
    // sballava la somma di 1 centesimo (99 invece di 100 su una base di 100).
    foreach ([333, 333, 334] as $n => $millesimi) {
        $immobile = Immobile::forceCreate([
            'condominio_id' => $condominio->id, 'nome' => "Interno $n", 'descrizione' => 'App',
            'interno' => (string) $n,
        ]);
        $an = Anagrafica::forceCreate([
            'nome' => "Prop $n", 'email' => "penny$n@example.com", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'PENNY'.str_pad((string) $n, 11, '0', STR_PAD_LEFT),
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $an->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true,
            'data_inizio' => now()->format('Y-m-d'),
        ]);
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id,
            'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $response = $this->actingAs($this->user)
        ->get("/admin/gestionale/{$condominio->id}/contributi/{$conto->id}")
        ->assertOk();

    $righe = collect($response->viewData('page')['props']['righe']);
    expect($righe->sum('quota_lorda'))->toBe(7_777);
})->group('contributi', 'http', 'regressione-avversariale');

/**
 * REGRESSIONE: revisione avversariale beta.27. La quota lorda calcolata dal
 * controller ignora le ripartizioni per soggetto e la cascata di risoluzione
 * anagrafiche del motore reale: la UI deve dirlo esplicitamente invece di
 * mostrare un numero come se fosse quello finale.
 */
test('con una ripartizione proprietario/inquilino la pagina segnala che la quota è una stima', function () {
    $sc = cvScenario(setupContabile());

    // Aggiunge una seconda riga di ripartizione (inquilino) sulla stessa tabella:
    // non più "proprietario 100%" di default.
    $pivotId = DB::table('conto_tabella_millesimale')
        ->where('conto_id', $sc->conto->id)->value('id');
    DB::table('conto_tabella_ripartizioni')
        ->where('conto_tabella_millesimale_id', $pivotId)
        ->update(['percentuale' => 70]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'inquilino',
        'percentuale' => 30, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stima_semplificata', true)
            // È esattamente il caso D8: il flag specifico dell'avviso legale
            // deve accendersi, non solo quello generico di "stima".
            ->where('ripartizione_mista', true)
        );
})->group('contributi', 'http', 'regressione-avversariale');

test('un\'unità senza proprietario attivo fa scattare l\'avviso di stima, ma NON quello di ripartizione mista', function () {
    $sc = cvScenario(setupContabile());

    // Il proprietario dell'unità 0 non è più attivo (es. appena venduta).
    DB::table('anagrafica_immobile')
        ->where('immobile_id', $sc->unita[0]->immobile->id)
        ->update(['attivo' => false]);

    $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stima_semplificata', true)
            // La ripartizione è ancora quella di default (proprietario 100%):
            // il rischio D8 (copertura che sconta il soggetto sbagliato) non
            // si applica qui, i due avvisi sono cause diverse e non vanno
            // confusi — questo test prova che sono davvero disaccoppiati.
            ->where('ripartizione_mista', false)
        );
})->group('contributi', 'http', 'regressione-avversariale');

test('salvando i contributi, il riparto chiede solo il residuo', function () {
    $sc = cvScenario(setupContabile());

    // Prima: €550 a testa.
    $prima = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione);
    expect($prima[$sc->unita[0]->anagrafica->id][$sc->unita[0]->immobile->id])->toBe(55_000);

    // L'amministratore registra €500 già versati per ciascuna unità.
    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'descrizione' => 'Delibera assembleare del 12/05/2025',
            'righe' => [
                ['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000],
                ['immobile_id' => $sc->unita[1]->immobile->id, 'gia_versato' => 50_000],
            ],
        ])
        ->assertRedirect();

    expect(ContributoVersato::count())->toBe(2);

    // Dopo: €50 a testa. Il caso del forum, risolto dall'interfaccia.
    $dopo = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione);
    expect($dopo[$sc->unita[0]->anagrafica->id][$sc->unita[0]->immobile->id])->toBe(5_000);
    expect($dopo[$sc->unita[1]->anagrafica->id][$sc->unita[1]->immobile->id])->toBe(5_000);
})->group('contributi', 'http');

/**
 * NUOVO beta.27, D8-bis: registrare "già versato" da solo non crea alcuna
 * liquidità reale — verificato con un test dedicato che, senza questa
 * dichiarazione, la cassa del condominio resta a saldo zero anche con
 * centinaia di euro di già-versato registrati. Questi test coprono i due
 * scenari con cui l'amministratore risolve la domanda "dove sono questi
 * soldi?", richiesta esplicitamente dalla UI.
 */
test('SCENARIO A: "i soldi sono ancora in cassa" porta a giornale un accantonamento reale', function () {
    $sc = cvScenario(setupContabile());
    $cassa = cvCreaCassa($sc->condominio->id);

    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa))->toBe(0);

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [
                ['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000],
                ['immobile_id' => $sc->unita[1]->immobile->id, 'gia_versato' => 50_000],
            ],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $cassa->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // La cassa ora riflette per davvero il già versato: €1.000, non più zero.
    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa->fresh()))->toBe(100_000);

    // Lo Stato Patrimoniale continua a quadrare — non è un valore senza
    // contropartita, è una scrittura vera (DARE cassa / AVERE passate_gestioni).
    $sp = app(StatoPatrimonialeService::class)->calcola($sc->condominio);
    expect($sp['quadra'])->toBeTrue();

    $cv = ContributoVersato::first();
    expect($cv->liquidita_stato)->toBe('registrata_in_cassa');
    expect($cv->cassa_id)->toBe($cassa->id);
})->group('contributi', 'http', 'liquidita');

test('SCENARIO A: un secondo salvataggio non riaccredita la cassa una seconda volta', function () {
    $sc = cvScenario(setupContabile());
    $cassa = cvCreaCassa($sc->condominio->id);
    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}";

    $payload = fn (int $importo) => [
        'natura' => 'fondo_vincolato',
        'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => $importo]],
        'liquidita_stato' => 'registrata_in_cassa',
        'cassa_id' => $cassa->id,
    ];

    $this->actingAs($this->user)->put($url, $payload(50_000))->assertSessionHasNoErrors();
    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa->fresh()))->toBe(50_000);

    // Correzione: stesso importo, stessa cassa, stesso liquidita_stato inviato di nuovo.
    $this->actingAs($this->user)->put($url, $payload(50_000))->assertSessionHasNoErrors();

    // Se il secondo salvataggio avesse ri-registrato l'accantonamento, la cassa
    // mostrerebbe 100.000 invece di 50.000.
    expect(app(SaldoCassaService::class)->saldoDisponibile($cassa->fresh()))->toBe(50_000);
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'accantonamento')->count())->toBe(1);
})->group('contributi', 'http', 'liquidita');

test('SCENARIO B: "già speso come acconto" non tocca la cassa ma apre un task di audit', function () {
    $sc = cvScenario(setupContabile());

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [
                ['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000],
                ['immobile_id' => $sc->unita[1]->immobile->id, 'gia_versato' => 50_000],
            ],
            'liquidita_stato' => 'gia_speso_acconto',
            'nota_acconto' => 'Acconto versato al fornitore Rossi Costruzioni il 15/06/2025, bonifico n. 4421.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Nessuna scrittura contabile: non c'è liquidità da registrare.
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'accantonamento')->count())->toBe(0);

    // Ma la dichiarazione è tracciata: un task inbox visibile, non solo un
    // campo di testo perso.
    $evento = Evento::where('tipo', EventoTipo::GIA_VERSATO_ACCONTO_DICHIARATO)->first();
    expect($evento)->not->toBeNull();
    expect($evento->description)->toContain('Rossi Costruzioni');
    expect($evento->description)->toContain('fattura pregressa');
    expect($evento->meta['context']['importo_cents'])->toBe(100_000);

    // Il link porta a registrare la fattura pregressa (is_pregresso), non più
    // alla stessa pagina del già-versato: riusa il meccanismo esistente per i
    // debiti ereditati da altri gestionali invece di lasciare solo una nota.
    expect($evento->meta['action_url'])->toContain('/fatture/create');

    $cv = ContributoVersato::first();
    expect($cv->liquidita_stato)->toBe('gia_speso_acconto');
    expect($cv->cassa_id)->toBeNull();
})->group('contributi', 'http', 'liquidita');

test('SCENARIO B: un secondo salvataggio non apre un secondo task', function () {
    $sc = cvScenario(setupContabile());
    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}";

    $payload = fn (int $importo) => [
        'natura' => 'fondo_vincolato',
        'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => $importo]],
        'liquidita_stato' => 'gia_speso_acconto',
        'nota_acconto' => 'Acconto 2025.',
    ];

    $this->actingAs($this->user)->put($url, $payload(50_000))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->put($url, $payload(60_000))->assertSessionHasNoErrors(); // correzione importo

    expect(Evento::where('tipo', EventoTipo::GIA_VERSATO_ACCONTO_DICHIARATO)->count())->toBe(1);
})->group('contributi', 'http', 'liquidita');

test('cassa_id è obbligatorio quando si dichiara "registrata_in_cassa"', function () {
    $sc = cvScenario(setupContabile());

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            // cassa_id mancante
        ])
        ->assertInvalid(['cassa_id']);

    expect(ContributoVersato::count())->toBe(0);
})->group('contributi', 'http', 'liquidita');

test('nota_acconto è obbligatoria quando si dichiara "gia_speso_acconto"', function () {
    $sc = cvScenario(setupContabile());

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'gia_speso_acconto',
            // nota_acconto mancante
        ])
        ->assertInvalid(['nota_acconto']);
})->group('contributi', 'http', 'liquidita');

test('un secondo salvataggio sostituisce i valori, non li somma', function () {
    $sc = cvScenario(setupContabile());
    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}";

    $payload = fn (int $importo) => [
        'natura' => 'avanzo',
        'righe'  => [
            ['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => $importo],
            ['immobile_id' => $sc->unita[1]->immobile->id, 'gia_versato' => 0],
        ],
    ];

    $this->actingAs($this->user)->put($url, $payload(50_000));
    $this->actingAs($this->user)->put($url, $payload(30_000)); // correzione

    // Una sola riga (l'unità con 0 non viene registrata) e il valore è quello nuovo.
    expect(ContributoVersato::count())->toBe(1);
    expect(ContributoVersato::first()->importo_cents)->toBe(30_000);
    expect(ContributoVersato::first()->natura)->toBe('avanzo');
})->group('contributi', 'http');

/**
 * REGRESSIONE: revisione avversariale beta.26. Il controller non rileggeva mai
 * la nota salvata in precedenza: la pagina di modifica partiva sempre da un campo
 * vuoto, e un secondo salvataggio (anche solo per correggere un importo) la
 * cancellava senza che l'amministratore se ne accorgesse.
 */
test('la nota salvata si rilegge riaprendo la pagina, e sopravvive a un secondo salvataggio', function () {
    $sc = cvScenario(setupContabile());
    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}";

    $this->actingAs($this->user)->put($url, [
        'natura'      => 'fondo_vincolato',
        'descrizione' => 'Delibera assembleare del 12/05/2025',
        'righe'       => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
    ]);

    $response = $this->actingAs($this->user)->get($url)->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('descrizione', 'Delibera assembleare del 12/05/2025')
    );

    // Un secondo salvataggio che NON tocca la nota (come farebbe il form, che la
    // riparte sempre dal valore ricevuto dal server) non deve cancellarla.
    $this->actingAs($this->user)->put($url, [
        'natura'      => 'fondo_vincolato',
        'descrizione' => 'Delibera assembleare del 12/05/2025',
        'righe'       => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 60_000]],
    ]);

    expect(ContributoVersato::first()->descrizione)->toBe('Delibera assembleare del 12/05/2025');
})->group('contributi', 'http', 'regressione-avversariale');

test('l\'elenco mostra lo stato di copertura di ogni voce', function () {
    $sc = cvScenario(setupContabile());

    // Una seconda voce, marcata anch'essa, ma senza alcun versamento registrato.
    $altraVoce = Conto::forceCreate([
        'piano_conto_id' => $sc->conto->piano_conto_id,
        'nome' => 'Altra voce da esercizio precedente', 'importo' => 20_000,
        'tipo' => 'spesa', 'attivo' => true, 'richiede_gia_versato' => true,
    ]);

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $sc->conto->id,
        'immobile_id'   => $sc->unita[0]->immobile->id,
        'importo_cents' => 50_000,
        'natura'        => 'fondo_vincolato',
    ]);

    $response = $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi")
        ->assertOk();

    // Il condominio ha più voci: cerchiamo la nostra per id, non per posizione.
    $voci = collect($response->viewData('page')['props']['voci']);
    $voce = $voci->firstWhere('id', $sc->conto->id);

    expect($voce)->not->toBeNull();
    expect($voce['coperto_cents'])->toBe(50_000);
    expect($voce['unita_coperte'])->toBe(1);

    // Le voci senza versamenti restano a zero, non ereditano nulla.
    $altra = $voci->firstWhere('id', $altraVoce->id);
    expect($altra)->not->toBeNull();
    expect($altra['coperto_cents'])->toBe(0);
})->group('contributi', 'http');

/**
 * NUOVO in beta.27, richiesta diretta dell'amministratore: l'elenco mostrava
 * TUTTE le voci di spesa del piano dei conti, confuso su decine di voci quando
 * solo una richiede il "già versato". Ora compaiono solo quelle marcate
 * esplicitamente "da esercizio precedente" — con un'eccezione di sicurezza:
 * una voce con coperture GIÀ registrate resta visibile anche senza il flag,
 * perché i dati reali non devono mai sparire per un checkbox non spuntato.
 */
test('solo le voci marcate "da esercizio precedente" compaiono nell\'elenco', function () {
    $sc = cvScenario(setupContabile()); // richiede_gia_versato = true, per costruzione

    $vociNormale = Conto::forceCreate([
        'piano_conto_id' => $sc->conto->piano_conto_id,
        'nome' => 'Spesa ordinaria corrente', 'importo' => 50_000,
        'tipo' => 'spesa', 'attivo' => true, 'richiede_gia_versato' => false,
    ]);

    $voceOrfanaConDati = Conto::forceCreate([
        'piano_conto_id' => $sc->conto->piano_conto_id,
        'nome' => 'Voce con dati ma senza flag', 'importo' => 30_000,
        'tipo' => 'spesa', 'attivo' => true, 'richiede_gia_versato' => false,
    ]);
    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class,
        'target_id' => $voceOrfanaConDati->id, 'immobile_id' => $sc->unita[0]->immobile->id,
        'importo_cents' => 10_000, 'natura' => 'avanzo',
    ]);

    $response = $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi")
        ->assertOk();

    $ids = collect($response->viewData('page')['props']['voci'])->pluck('id');

    expect($ids)->toContain($sc->conto->id);          // marcata esplicitamente
    expect($ids)->toContain($voceOrfanaConDati->id);   // non marcata, ma ha dati reali
    expect($ids)->not->toContain($vociNormale->id);    // né marcata né con dati: fuori
})->group('contributi', 'http', 'regressione-avversariale');

/**
 * Regressione: i capitoli sono contenitori (beta.22), non spese. Comparivano
 * nell'elenco con importo zero e aprivano una schermata priva di senso, in cui
 * l'amministratore avrebbe potuto registrare versamenti verso il nulla.
 */
test('i capitoli contenitore non compaiono fra le voci su cui si versa', function () {
    $sc = cvScenario(setupContabile());

    $capitolo = Conto::forceCreate([
        'piano_conto_id' => $sc->conto->piano_conto_id,
        'nome'           => 'Capitolo spese generali',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
        'is_capitolo'    => true,
    ]);

    $response = $this->actingAs($this->user)
        ->get("/admin/gestionale/{$sc->condominio->id}/contributi")
        ->assertOk();

    $ids = collect($response->viewData('page')['props']['voci'])->pluck('id');

    expect($ids)->not->toContain($capitolo->id);
    expect($ids)->toContain($sc->conto->id);
})->group('contributi', 'http');

test('anche via URL diretto un capitolo non è versabile', function () {
    $sc = cvScenario(setupContabile());

    $capitolo = Conto::forceCreate([
        'piano_conto_id' => $sc->conto->piano_conto_id,
        'nome'           => 'Capitolo spese generali',
        'importo'        => 0,
        'tipo'           => 'spesa',
        'attivo'         => true,
        'is_capitolo'    => true,
    ]);

    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$capitolo->id}";

    $this->actingAs($this->user)->get($url)->assertNotFound();

    $this->actingAs($this->user)->put($url, [
        'natura' => 'avanzo',
        'righe'  => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 10_000]],
    ])->assertNotFound();

    expect(ContributoVersato::count())->toBe(0);
})->group('contributi', 'http');

/**
 * REGRESSIONE: revisione avversariale beta.26. `exists:immobili,id` valida solo
 * che l'immobile esista da QUALCHE PARTE, non che appartenga al condominio della
 * rotta: un payload con l'immobile_id di UN ALTRO condominio veniva accettato.
 */
/**
 * REGRESSIONE: revisione avversariale beta.26. Nessun vincolo impediva due righe
 * per la stessa (target, immobile): il motore le SOMMA (perImmobile), la pagina
 * di modifica ne mostrava una sola (keyBy) — numeri diversi a seconda di dove si
 * guarda, esattamente il tipo di divergenza già corretto una volta in beta.25
 * sul saldo cassa.
 */
test('il vincolo unique impedisce due righe per la stessa unità sulla stessa voce', function () {
    $sc = cvScenario(setupContabile());

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $sc->conto->id,
        'immobile_id'   => $sc->unita[0]->immobile->id,
        'importo_cents' => 10_000,
        'natura'        => 'avanzo',
    ]);

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $sc->conto->id,
        'immobile_id'   => $sc->unita[0]->immobile->id, // stessa unità, stessa voce
        'importo_cents' => 5_000,
        'natura'        => 'avanzo',
    ]);
})->throws(\Illuminate\Database\QueryException::class)
  ->group('contributi', 'regressione-avversariale');

/** Esegue solo la migrazione del vincolo unique (non l'intera suite migrations). */
function cvEseguiMigrazioneVincoloUnique(): \Illuminate\Database\Migrations\Migration
{
    return require database_path('migrations/2026_07_25_090000_add_unique_constraint_to_contributi_versati.php');
}

test('BACKFILL REALE: la migrazione del vincolo unique deduplica sommando, non scartando', function () {
    $sc = cvScenario(setupContabile());
    $migration = cvEseguiMigrazioneVincoloUnique();

    // Il vincolo esiste già (RefreshDatabase ha girato tutte le migrazioni):
    // lo togliamo per simulare lo stato PRIMA di questa correzione.
    $migration->down();

    // Due righe duplicate, inserite scavalcando il vincolo (che non c'è più).
    DB::table('contributi_versati')->insert([
        [
            'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class,
            'target_id' => $sc->conto->id, 'immobile_id' => $sc->unita[0]->immobile->id,
            'importo_cents' => 30_000, 'natura' => 'avanzo', 'origine' => 'migrazione',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class,
            'target_id' => $sc->conto->id, 'immobile_id' => $sc->unita[0]->immobile->id,
            'importo_cents' => 20_000, 'natura' => 'avanzo', 'origine' => 'migrazione',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    expect(ContributoVersato::count())->toBe(2);

    $migration->up(); // ri-applica: deve dedupllicare PRIMA di aggiungere il vincolo

    expect(ContributoVersato::count())->toBe(1);
    expect((int) ContributoVersato::first()->importo_cents)->toBe(50_000); // 30.000+20.000, non perso

    // Il vincolo è di nuovo attivo: un nuovo duplicato torna a essere impossibile.
    expect(fn () => ContributoVersato::create([
        'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class,
        'target_id' => $sc->conto->id, 'immobile_id' => $sc->unita[0]->immobile->id,
        'importo_cents' => 1_000, 'natura' => 'avanzo',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
})->group('contributi', 'regressione-avversariale', 'backfill');

test('un immobile di un altro condominio viene rifiutato, non silenziosamente ignorato', function () {
    $sc = cvScenario(setupContabile());

    $altroCondominio = \App\Models\Condominio::factory()->create();
    $immobileAltrove = \App\Models\Immobile::forceCreate([
        'condominio_id' => $altroCondominio->id,
        'nome' => 'Interno Altrove', 'descrizione' => 'App', 'interno' => 'X',
    ]);

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'avanzo',
            'righe'  => [['immobile_id' => $immobileAltrove->id, 'gia_versato' => 10_000]],
        ])
        ->assertInvalid(['righe.0.immobile_id']);

    expect(ContributoVersato::count())->toBe(0);
})->group('contributi', 'http', 'regressione-avversariale');

test('GUARDIA: un fondo deliberato non può essere registrato su una cassa generica per imprevisti', function () {
    $sc = cvScenario(setupContabile());
    $fondoGenerico = cvCreaFondo($sc->condominio->id, 'generico');

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $fondoGenerico->id,
        ])
        ->assertInvalid(['cassa_id']);

    expect(ContributoVersato::count())->toBe(0);
})->group('contributi', 'http', 'liquidita', 'vincolo-fondo');

test('un fondo deliberato può essere registrato su un fondo dedicato (vincolato_lavori)', function () {
    $sc = cvScenario(setupContabile());
    $fondoVincolato = cvCreaFondo($sc->condominio->id, 'vincolato_lavori');

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'fondo_vincolato',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $fondoVincolato->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(SaldoCassaService::class)->saldoDisponibile($fondoVincolato))->toBe(50_000);
})->group('contributi', 'http', 'liquidita', 'vincolo-fondo');

test('un avanzo (nessun vincolo) può essere registrato anche su un fondo generico per imprevisti', function () {
    $sc = cvScenario(setupContabile());
    $fondoGenerico = cvCreaFondo($sc->condominio->id, 'generico');

    $this->actingAs($this->user)
        ->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
            'natura' => 'avanzo',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $fondoGenerico->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(SaldoCassaService::class)->saldoDisponibile($fondoGenerico))->toBe(50_000);
})->group('contributi', 'http', 'liquidita', 'vincolo-fondo');

test('GUARDIA: il vincolo fondo/cassa non si può aggirare su un resave successivo', function () {
    // REGRESSIONE (revisione avversariale): il controllo era dentro
    // if(!$liquiditaGiaDichiarata), quindi si applicava SOLO alla primissima
    // dichiarazione. Un secondo salvataggio che cambia natura in
    // 'fondo_vincolato' mantenendo la stessa cassa generica passava senza
    // errori — esattamente la combinazione che la guardia deve impedire.
    $sc = cvScenario(setupContabile());
    $fondoGenerico = cvCreaFondo($sc->condominio->id, 'generico');
    $url = "/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}";

    // Primo salvataggio: 'avanzo' + fondo generico è legittimo, nessun vincolo.
    $this->actingAs($this->user)
        ->put($url, [
            'natura' => 'avanzo',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $fondoGenerico->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Secondo salvataggio sullo STESSO conto: cambio natura in vincolato,
    // stessa cassa generica — deve essere bloccato, non silenziosamente accettato.
    $this->actingAs($this->user)
        ->put($url, [
            'natura' => 'fondo_vincolato',
            'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
            'liquidita_stato' => 'registrata_in_cassa',
            'cassa_id' => $fondoGenerico->id,
        ])
        ->assertInvalid(['cassa_id']);

    expect(ContributoVersato::first()->natura)->toBe('avanzo');
})->group('contributi', 'http', 'liquidita', 'vincolo-fondo', 'regressione-avversariale');

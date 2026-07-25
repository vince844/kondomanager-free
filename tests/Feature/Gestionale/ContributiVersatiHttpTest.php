<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

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
        );
})->group('contributi', 'http');

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

test('l\'elenco mostra lo stato di copertura di ogni voce', function () {
    $sc = cvScenario(setupContabile());

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
    $altra = $voci->firstWhere('id', '!=', $sc->conto->id);
    expect($altra['coperto_cents'])->toBe(0);
})->group('contributi', 'http');

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

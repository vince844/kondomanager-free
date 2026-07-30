<?php

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Http\Resources\Gestionale\PianiDeiConti\Conti\ContoResource;
use App\Models\User;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * I primi test verificano la catena SpesaPerVoceService → ContoResource → prop
 * `speso_raw` al livello della resource; gli ultimi due passano dalla rotta HTTP.
 *
 * Fino alla beta.30 la rotta `piani-conti.show` NON era testabile: usava
 * `GROUP_CONCAT(... SEPARATOR ', ')`, sintassi MySQL che SQLite rifiuta con
 * «near "SEPARATOR": syntax error», e ogni richiesta finiva in 500 nella suite.
 * `PianoContiController::groupConcat()` ora sceglie la forma giusta per driver.
 */
beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    ContoResource::$spesoMap = [];
    ContoResource::$coverageMap = [];
    ContoResource::$budgetOriginaliMap = [];
});

afterEach(function () {
    ContoResource::$spesoMap = [];
    ContoResource::$coverageMap = [];
    ContoResource::$budgetOriginaliMap = [];
});

function serializzaConto(\App\Models\Gestionale\Conto $conto): array
{
    return (new ContoResource($conto))->toArray(request());
}

function regolazionePiano(array $ctx, float $importo): \App\Models\Gestionale\ScritturaContabile
{
    [$condominio, $esercizio, $gestione, , , $capitolo] = $ctx;

    return (new RegistraRegolazioneImmediataAction())->execute([
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'conto_id' => $capitolo->id,
        'cassa_id' => DB::table('casse')->where('condominio_id', $condominio->id)->value('id'),
        'fornitore_id' => null,
        'data_operazione' => now()->toDateString(),
        'causale' => 'Spesa di cassa',
        'importo' => $importo,
    ], $condominio, $esercizio);
}

test('senza spese la voce espone speso_raw a zero', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    expect(serializzaConto($capitolo)['speso_raw'])->toBe(0);
});

test('una regolazione immediata arriva fino alla prop speso_raw della voce', function () {
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    // Il caso dell'amministratore: 106,72 € spesi, di cui parte via regolazione.
    regolazionePiano($ctx, 106.72);

    // Stessa catena del controller: servizio → mappa statica → resource.
    ContoResource::$spesoMap = app(SpesaPerVoceService::class)->perEsercizio($esercizio);

    expect(serializzaConto($capitolo->fresh())['speso_raw'])->toBe(10672);
});

test('speso_raw è un fatto distinto dalla copertura da piano rate', function () {
    // Il cuore del malinteso: la barra dice quanto è coperto dal piano rate, non
    // quanto è stato speso. Le due grandezze devono poter divergere.
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    regolazionePiano($ctx, 50.00);

    ContoResource::$spesoMap = app(SpesaPerVoceService::class)->perEsercizio($esercizio);
    ContoResource::$coverageMap = []; // nessun piano rate su questa voce

    $prop = serializzaConto($capitolo->fresh());

    expect($prop['speso_raw'])->toBe(5000)
        ->and($prop['impegnato'])->toBe(0);
});

test('il preventivo originale resta distinto dallo speso in caso di sforo', function () {
    // Il controller gonfia `importo` allo speso quando c'è sforo: se la vista
    // confrontasse lo speso con quello, "resta" varrebbe sempre zero e uno sforo
    // non si distinguerebbe mai. `budget_originale_raw` conserva il preventivo vero.
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    $preventivoOriginale = $capitolo->importo;
    regolazionePiano($ctx, 6000.00); // 600.000 cent contro un budget di 500.000

    ContoResource::$spesoMap = app(SpesaPerVoceService::class)->perEsercizio($esercizio);
    ContoResource::$budgetOriginaliMap = [$capitolo->id => $preventivoOriginale];

    // Il controller sovrascrive importo con lo speso quando lo supera: lo riproduciamo.
    $contoGonfiato = $capitolo->fresh();
    $contoGonfiato->importo = 600000;

    $prop = serializzaConto($contoGonfiato);

    expect($prop['speso_raw'])->toBe(600000)
        ->and($prop['budget_originale_raw'])->toBe(500000)
        ->and($prop['importo_raw'])->toBe(600000)
        // È questa differenza che permette alla vista di dire «sfora di 1.000,00 €»
        // invece di un inutile «resta 0».
        ->and($prop['speso_raw'] - $prop['budget_originale_raw'])->toBe(100000);
});

// ─── Totali di testata (via HTTP) ────────────────────────────────────────────
// Testabili solo da quando `groupConcat()` rende portabile la query degli addebiti
// personali: prima la rotta restituiva 500 su SQLite e la pagina era scoperta.

test('la pagina espone il totale consuntivo accanto al preventivo', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione] = $ctx;

    regolazionePiano($ctx, 1200.00);

    $pianoConto = \App\Models\Gestionale\PianoConto::where('gestione_id', $gestione->id)->firstOrFail();

    $response = $this->actingAs($this->user)->get(
        route('admin.gestionale.esercizi.piani-conti.show', [$condominio, $esercizio, $pianoConto])
    );

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('totaleConsuntivo', 120000)
        // Il preventivo resta il budget deliberato, non gonfiato dallo speso.
        ->where('totalePreventivo', 500000)
    );
});

test('il totale preventivo non si gonfia quando lo speso supera il budget', function () {
    // Regressione: i totali sommavano `$conto->importo`, che il controller porta allo
    // speso in caso di sforo. Il badge "Preventivo" cresceva quindi insieme agli sfori
    // e smetteva di essere un preventivo — accanto al Consuntivo sarebbe stato assurdo.
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione] = $ctx;

    regolazionePiano($ctx, 8000.00); // 800.000 cent contro un budget di 500.000

    $pianoConto = \App\Models\Gestionale\PianoConto::where('gestione_id', $gestione->id)->firstOrFail();

    $response = $this->actingAs($this->user)->get(
        route('admin.gestionale.esercizi.piani-conti.show', [$condominio, $esercizio, $pianoConto])
    );

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('totalePreventivo', 500000)
        ->where('totaleConsuntivo', 800000)
    );
});

// ─── Drill-down: i movimenti che compongono il consuntivo ────────────────────

function movimentiDiVoce(array $ctx, ?int $voceId = null)
{
    [$condominio, $esercizio, , , , $capitolo] = $ctx;

    return test()->actingAs(test()->user)->getJson(
        route('admin.gestionale.esercizi.voci.movimenti', [
            $condominio, $esercizio, $voceId ?? $capitolo->id,
        ])
    );
}

test('la voce senza movimenti restituisce un elenco vuoto e totale zero', function () {
    $ctx = setupPagamentiService();

    $risposta = movimentiDiVoce($ctx);

    $risposta->assertOk()
        ->assertJsonPath('movimenti', [])
        ->assertJsonPath('totale', 0);
});

test('i movimenti elencano fatture e regolazioni con la loro etichetta', function () {
    $ctx = setupPagamentiService();

    registraFatturaServiceTest($ctx);
    regolazionePiano($ctx, 106.72);

    $risposta = movimentiDiVoce($ctx);

    $risposta->assertOk()->assertJsonCount(2, 'movimenti');

    $tipi = collect($risposta->json('movimenti'))->pluck('tipo_movimento')->all();

    expect($tipi)->toContain('fattura_acquisto')
        ->and($tipi)->toContain('regolazione_immediata')
        ->and(collect($risposta->json('movimenti'))->pluck('tipo_movimento_label')->all())
        ->toContain('Regolazione immediata');
});

test('il totale dei movimenti coincide sempre con lo speso della voce', function () {
    // Invariante centrale del drill-down: se la somma dei movimenti divergesse dal
    // numero da cui la modale è stata aperta, l'elenco spiegherebbe un altro numero.
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    registraFatturaServiceTest($ctx);
    regolazionePiano($ctx, 42.50);

    $speso = app(SpesaPerVoceService::class)->perEsercizio($esercizio)[$capitolo->id];

    movimentiDiVoce($ctx)->assertOk()->assertJsonPath('totale', $speso);
});

test('lo storno compare come riga negativa e riporta il totale a zero', function () {
    $ctx = setupPagamentiService();
    [$condominio] = $ctx;

    $scrittura = regolazionePiano($ctx, 80.00);
    (new \App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction())
        ->execute($scrittura, $condominio, 'Errore di digitazione');

    $risposta = movimentiDiVoce($ctx);

    $risposta->assertOk()->assertJsonPath('totale', 0)->assertJsonCount(2, 'movimenti');

    $importi = collect($risposta->json('movimenti'))->pluck('importo')->sort()->values()->all();

    // Gli storni non si nascondono: sono parte della storia che spiega il totale.
    expect($importi)->toBe([-8000, 8000]);
});

test('non si possono leggere i movimenti di una voce di un altro condominio', function () {
    $ctx = setupPagamentiService();
    $altro = setupPagamentiService();
    [, , , , , $capitoloAltrui] = $altro;

    regolazionePiano($altro, 500.00);

    // Voce esistente ma di un altro condominio: l'id nell'URL non deve bastare.
    movimentiDiVoce($ctx, $capitoloAltrui->id)->assertNotFound();
});

test('con pochi movimenti il flag di troncamento resta spento', function () {
    $ctx = setupPagamentiService();
    regolazionePiano($ctx, 10.00);

    movimentiDiVoce($ctx)->assertOk()->assertJsonPath('troncato', false);
});

test('oltre il limite l elenco è tagliato ma il totale resta quello completo', function () {
    // Il taglio non deve falsare il numero: la modale si apre da un importo, e quel
    // numero deve restare vero anche quando le righe mostrate sono un sottoinsieme.
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    foreach (range(1, 6) as $i) {
        regolazionePiano($ctx, 10.00);
    }

    $spesoReale = app(SpesaPerVoceService::class)->perEsercizio($esercizio)[$capitolo->id];
    expect($spesoReale)->toBe(6000);

    // Limite artificiale: 6 movimenti reali, ne chiediamo 2.
    $movimentiTagliati = app(SpesaPerVoceService::class)->movimentiPerVoce($esercizio, $capitolo->id, 2);

    expect($movimentiTagliati)->toHaveCount(2)
        // La somma delle righe mostrate NON è il totale — ed è esattamente il motivo
        // per cui il controller espone il totale calcolato a parte.
        ->and(array_sum(array_column($movimentiTagliati, 'importo')))->toBe(2000)
        ->and($spesoReale)->not->toBe(array_sum(array_column($movimentiTagliati, 'importo')));
});

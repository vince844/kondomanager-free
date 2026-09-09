<?php

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function adminGiornale(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Condominio + esercizio + gestione + due conti contabili (uno attivo, uno passivo) per postare scritture bilanciate. */
function setupGiornale(): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto',
    ]);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    $contoAttivo = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'codice' => 'ATT-'.uniqid(),
        'nome' => 'Conto Attivo Test',
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contoPassivo = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'codice' => 'PAS-'.uniqid(),
        'nome' => 'Conto Passivo Test',
        'tipo' => 'passivo',
        'categoria' => 'debiti',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$condominio, $esercizio, $gestione, $contoAttivo, $contoPassivo];
}

/** Crea una scrittura bilanciata (dare = avere) sui due conti di setupGiornale(). */
function creaScritturaGiornale(array $ctx, array $overrides = []): ScritturaContabile
{
    [$condominio, $esercizio, $gestione, $contoAttivo, $contoPassivo] = $ctx;
    $importo = $overrides['importo'] ?? 10000;
    unset($overrides['importo']);

    $scrittura = ScritturaContabile::create(array_merge([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Scrittura di test',
        'descrizione' => 'Descrizione di test',
        'tipo_movimento' => TipoMovimentoContabile::RETTIFICA->value,
        'stato' => 'registrata',
    ], $overrides));

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoAttivo,
        'tipo_riga' => 'dare',
        'importo' => $importo,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoPassivo,
        'tipo_riga' => 'avere',
        'importo' => $importo,
    ]);

    return $scrittura->fresh();
}

test('elenca solo le scritture del condominio e dell\'esercizio richiesti', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    $scritturaGiusta = creaScritturaGiornale($ctx, ['causale' => 'Nel periodo giusto']);

    // Stesso condominio, ma un altro esercizio.
    $altroEsercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'chiuso']);
    creaScritturaGiornale($ctx, ['esercizio_id' => $altroEsercizio->id, 'causale' => 'Altro esercizio']);

    // Un altro condominio, con la sua stessa struttura.
    $altroCtx = setupGiornale();
    creaScritturaGiornale($altroCtx, ['causale' => 'Altro condominio']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/scritture/List')
            ->has('scritture.data', 1)
            ->where('scritture.data.0.id', $scritturaGiusta->id)
        );
});

test('il cambio esercizio esclude le scritture dell\'esercizio precedente', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['causale' => 'Esercizio corrente']);

    $nuovoEsercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $nuovoEsercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('scritture.data', 0));
});

test('filtro ricerca testuale isola per causale', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['causale' => 'Bollo auto condominiale']);
    creaScritturaGiornale($ctx, ['causale' => 'Pagamento fornitore ascensori']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?search=bollo')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scritture.data', 1)
            ->where('scritture.data.0.causale', 'Bollo auto condominiale')
        );
});

test('filtro tipo movimento isola per tipo', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['tipo_movimento' => TipoMovimentoContabile::GIROCONTO->value]);
    creaScritturaGiornale($ctx, ['tipo_movimento' => TipoMovimentoContabile::RETTIFICA->value]);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?tipo_movimento=giroconto')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scritture.data', 1)
            ->where('scritture.data.0.tipo_movimento', 'giroconto')
        );
});

test('filtro stato isola per stato', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['stato' => 'bozza']);
    creaScritturaGiornale($ctx, ['stato' => 'registrata']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?stato=bozza')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scritture.data', 1)
            ->where('scritture.data.0.stato', 'bozza')
        );
});

test('filtro intervallo date isola per data di registrazione', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['data_registrazione' => '2026-01-10', 'causale' => 'Fuori range']);
    $dentro = creaScritturaGiornale($ctx, ['data_registrazione' => '2026-03-15', 'causale' => 'Dentro range']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?data_da=2026-03-01&data_a=2026-03-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scritture.data', 1)
            ->where('scritture.data.0.id', $dentro->id)
        );
});

test('il widget quadratura riflette StatoPatrimonialeService::calcola', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx);

    $atteso = app(StatoPatrimonialeService::class)->calcola($condominio, $esercizio);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quadratura.quadra', $atteso['quadra'])
            ->where('quadratura.sbilancio', $atteso['sbilancio'])
            ->where('quadratura.totale_attivo', $atteso['attivo']['totale'])
            ->where('quadratura.totale_passivo', $atteso['passivo']['totale'])
            ->where('quadratura.risultato_esercizio', $atteso['risultato_esercizio'])
        );
});

test('per_page rispetta la whitelist del selettore e ignora valori non ammessi', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    foreach (range(1, 3) as $i) {
        creaScritturaGiornale($ctx, ['causale' => "Scrittura $i"]);
    }

    // ⚠️ **Venti resta venti.** La lista dei valori ammessi è passata a `config('pagination.consentite')`,
    // uguale per tutto il programma, ma il valore di partenza di *questo* elenco è rimasto il suo:
    // le scritture sono dense e dieci righe non fanno una giornata. Il controller lo dichiara con
    // `righePerPagina($request, predefinito: 20)`, che è il gradino sopra le impostazioni generali.
    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?per_page=999999')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scritture.meta.per_page', 20));

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?per_page=50')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scritture.meta.per_page', 50));
});

test('una pagina fuori range viene riportata all\'ultima pagina disponibile invece di restare vuota', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    // Una sola scrittura: last_page=1. Un ?page=5 residuo (portato da un altro
    // esercizio dal selettore in PageHeaderGuide) non deve svuotare la lista.
    $scrittura = creaScritturaGiornale($ctx);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]).'?page=5')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scritture.meta.current_page', 1)
            ->has('scritture.data', 1)
            ->where('scritture.data.0.id', $scrittura->id)
        );
});

test('quando quadra la diagnosi resta null', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quadratura.quadra', true)
            ->where('diagnosi', null)
        );
});

test('una scrittura con dare diverso da avere compare nella diagnosi come causa dello sbilancio', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio, $gestione, $contoAttivo, $contoPassivo] = $ctx;

    $rotta = ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Scrittura rotta',
        'tipo_movimento' => TipoMovimentoContabile::RETTIFICA->value,
        'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $rotta->id, 'conto_contabile_id' => $contoAttivo, 'tipo_riga' => 'dare', 'importo' => 15000]);
    RigaScrittura::create(['scrittura_id' => $rotta->id, 'conto_contabile_id' => $contoPassivo, 'tipo_riga' => 'avere', 'importo' => 10000]);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quadratura.quadra', false)
            ->has('diagnosi.scritture_non_quadrate', 1)
            ->where('diagnosi.scritture_non_quadrate.0.id', $rotta->id)
        );
});

test('una cassa con saldo iniziale non ancora portato a giornale compare nella diagnosi', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;

    $contoCassa = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'codice' => 'CASSA-'.uniqid(),
        'nome' => 'Cassa Test',
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cassa = \App\Models\Gestionale\Cassa::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Banca Test',
        'tipo' => 'banca',
        'conto_contabile_id' => $contoCassa,
        'saldo_iniziale' => 50000,
        'attiva' => true,
    ]);

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quadratura.quadra', false)
            ->has('diagnosi.casse_senza_apertura', 1)
            ->where('diagnosi.casse_senza_apertura.0.id', $cassa->id)
        );
});

/**
 * ⚠️ **Coda 145 dei registri contabili, §10.1.1.** Prima di questa beta l'elenco non mandava le
 * righe della scrittura: il payload di `index()` si fermava a `importo` (il totale dare) e
 * `is_quadrata`. La riga espandibile — «clic e sotto compaiono conto, dare e avere» — non ha
 * niente da mostrare senza questo dato, e senza l'eager load `righe.contoContabile` la stessa
 * richiesta genererebbe una query N+1 per ogni scrittura in pagina.
 */
test('elenco: ogni scrittura porta le sue righe, col conto e non solo un id', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio, , $contoAttivo, $contoPassivo] = $ctx;
    $scrittura = creaScritturaGiornale($ctx, ['causale' => 'Con righe da espandere']);

    // ⚠️ Il backend non garantisce l'ordine dare/avere — in `Show.vue` (`righeOrdinate`) è il
    // frontend a riordinarle. Il test verifica il contenuto delle due righe, non la posizione.
    $righe = $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->assertOk()
        ->viewData('page')['props']['scritture']['data'][0]['righe'];

    expect($righe)->toHaveCount(2);

    $dare = collect($righe)->firstWhere('tipo_riga', 'dare');
    $avere = collect($righe)->firstWhere('tipo_riga', 'avere');

    expect($dare)->not->toBeNull()
        ->and($dare['conto']['id'])->toBe($contoAttivo)
        ->and($dare['conto']['nome'])->not->toBeEmpty()
        ->and($avere)->not->toBeNull()
        ->and($avere['conto']['id'])->toBe($contoPassivo);
});

/**
 * ⚠️ **La forma non deve divergere fra elenco e dettaglio.** `serializzaRighe()` è condiviso da
 * `index()` e `show()` apposta: se un domani qualcuno duplica la logica invece di riusarla, questo
 * test smette di vedere la garanzia — non perché il dato sia sbagliato, ma perché avrebbe smesso
 * di essere lo stesso dato.
 */
test('elenco e dettaglio descrivono la stessa riga con le stesse chiavi', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    $scrittura = creaScritturaGiornale($ctx);

    $rigaElenco = $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.index', [$condominio, $esercizio]))
        ->viewData('page')['props']['scritture']['data'][0]['righe'][0];

    $rigaDettaglio = $this->actingAs($user)
        ->get(route('admin.gestionale.scritture.show', [$condominio, $scrittura]))
        ->viewData('page')['props']['scrittura']['righe'][0];

    expect(array_keys($rigaElenco))->toBe(array_keys($rigaDettaglio));
});

// ---------------------------------------------------------------------------
// STAMPA — §10.1.2 di docs/registri_contabili.md
// ---------------------------------------------------------------------------

/**
 * Chiama righePerStampa() via reflection: è privato e statico apposta, perché la trasformazione
 * dei dati e la generazione del PDF sono due cose diverse da provare in due modi diversi — questa
 * si verifica sull'array PHP, senza mai chiamare mPDF.
 */
function righePerStampa($scritture): array
{
    $reflection = new ReflectionClass(App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController::class);
    $method = $reflection->getMethod('righePerStampa');

    return $method->invoke(null, $scritture);
}

test('righePerStampa: dare prima di avere, indipendentemente dall\'ordine di creazione', function () {
    $ctx = setupGiornale();
    [, , , $contoAttivo, $contoPassivo] = $ctx;
    $scrittura = creaScritturaGiornale($ctx);

    // Ricarico con l'eager load che il controller usa davvero — senza, contoContabile
    // sarebbe lazy e il test non proverebbe la stessa condizione della stampa reale.
    $scrittura->load(['righe.contoContabile', 'righe.cassa', 'righe.voceSpesa']);

    $righe = righePerStampa(collect([$scrittura]));

    expect($righe)->toHaveCount(2);
    expect($righe[0]['dare'])->not->toBeNull();
    expect($righe[0]['avere'])->toBeNull();
    expect($righe[1]['avere'])->not->toBeNull();
    expect($righe[1]['dare'])->toBeNull();
});

test('righePerStampa: la forma della riga è quella che il template Blade si aspetta', function () {
    $ctx = setupGiornale();
    $scrittura = creaScritturaGiornale($ctx, [
        'causale' => 'Causale di prova',
        'numero_protocollo' => 'PROVA-001',
    ]);
    $scrittura->load(['righe.contoContabile', 'righe.cassa', 'righe.voceSpesa']);

    $righe = righePerStampa(collect([$scrittura]));

    expect($righe[0])->toHaveKeys(['data', 'protocollo', 'causale', 'conto_nome', 'conto_codice', 'dettaglio', 'dare', 'avere']);
    expect($righe[0]['protocollo'])->toBe('PROVA-001');
    expect($righe[0]['causale'])->toBe('Causale di prova');
    // Nessuna cassa, nessuna voce di spesa, nessuna nota su queste righe: il dettaglio deve
    // essere null e non una stringa vuota — è il fallback del template che decide cosa scrivere.
    expect($righe[0]['dettaglio'])->toBeNull();
});

test('righePerStampa: il dettaglio unisce cassa, voce di spesa e nota con un separatore', function () {
    $ctx = setupGiornale();
    [$condominio, $esercizio, $gestione, $contoAttivo] = $ctx;

    $cassa = DB::table('casse')->insertGetId([
        'condominio_id' => $condominio->id,
        'conto_contabile_id' => $contoAttivo,
        'nome' => 'Cassa di prova',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $scrittura = ScritturaContabile::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_registrazione' => now()->format('Y-m-d'), 'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Con dettaglio', 'tipo_movimento' => TipoMovimentoContabile::RETTIFICA->value, 'stato' => 'registrata',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id, 'conto_contabile_id' => $contoAttivo, 'cassa_id' => $cassa,
        'tipo_riga' => 'dare', 'importo' => 5000, 'note' => 'Nota di prova',
    ]);
    $scrittura->load(['righe.contoContabile', 'righe.cassa', 'righe.voceSpesa']);

    $righe = righePerStampa(collect([$scrittura]));

    expect($righe[0]['dettaglio'])->toBe('Cassa di prova · Nota di prova');
});

test('stampa: risponde con un PDF valido e rispetta gli stessi filtri dell\'elenco', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['causale' => 'Fuori periodo', 'data_registrazione' => '2020-01-01']);
    creaScritturaGiornale($ctx, ['causale' => 'Dentro periodo', 'data_registrazione' => now()->format('Y-m-d')]);

    $risposta = $this->actingAs($user)->get(route('admin.gestionale.esercizi.scritture.print', [
        $condominio, $esercizio, 'data_da' => now()->subDay()->format('Y-m-d'),
    ]));

    $risposta->assertOk();
    $risposta->assertHeader('Content-Type', 'application/pdf');
});

/**
 * ⚠️ **Fase 1-bis della beta.23**: il test sopra si fermava a status e header, mai al contenuto
 * — mutando via `applyFiltri()` disattivato restava verde lo stesso. Il confronto per
 * dimensione del PDF è stato provato e scartato: la compressione di mPDF non è monotona
 * rispetto al numero di righe (misurato: un documento più corto ma con un riquadro di testo
 * in più pesava DI PIÙ di uno con sei righe ripetitive, perché la ripetizione comprime meglio
 * della varietà). La prova che regge è sulla QUERY: si intercetta l'SQL vero con `DB::listen()`
 * e si legge il binding, non il PDF.
 */
test('stampa: la query eseguita porta davvero il filtro data, non solo il PDF che lo dichiara', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;
    creaScritturaGiornale($ctx, ['data_registrazione' => '2020-01-01']);

    $sogliaAttesa = now()->subDay()->format('Y-m-d');
    $bindingsCatturati = [];

    DB::listen(function ($query) use (&$bindingsCatturati) {
        if (str_contains($query->sql, 'scritture_contabili') && str_contains($query->sql, 'data_registrazione')) {
            $bindingsCatturati[] = $query->bindings;
        }
    });

    $this->actingAs($user)->get(route('admin.gestionale.esercizi.scritture.print', [
        $condominio, $esercizio, 'data_da' => $sogliaAttesa,
    ]));

    $tuttiIBindings = collect($bindingsCatturati)->flatten()->all();

    // ⚠️ `toContain()` è variadico: un secondo argomento diventa un secondo valore da cercare,
    // non un messaggio — lo stesso trabocchetto già incontrato e documentato nella beta.22.
    expect(in_array($sogliaAttesa, $tuttiIBindings, true))
        ->toBeTrue('nessuna query verso scritture_contabili porta il valore del filtro data_da: applyFiltri() non sta filtrando la stampa');
});

test('stampa: un esercizio senza scritture produce comunque un PDF, non un errore', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio, $esercizio] = $ctx;

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.print', [$condominio, $esercizio]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

/**
 * ⚠️ **404, non 403** — misurato, non presunto. `abort(403, ...)` è la prima riga di `stampa()`,
 * ma non la si raggiunge mai su questo scenario: l'intero gruppo di rotte del gestionale ha
 * `scopeBindings()` (beta.66, `routes/gestionale.php`) e Laravel rifiuta di risolvere un
 * `{esercizio}` che non è annidato sotto quel `{condominio}` **prima** che il controller giri.
 * Il controllo manuale è ridondante qui, non sbagliato — resta come difesa in profondità.
 */
test('stampa: un esercizio di un altro condominio non si risolve nemmeno per rotta', function () {
    $user = adminGiornale();
    $ctx = setupGiornale();
    [$condominio] = $ctx;
    $altroCtx = setupGiornale();
    $esercizioDiAltri = $altroCtx[1];

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.scritture.print', [$condominio, $esercizioDiAltri]))
        ->assertNotFound();
});

/**
 * ⚠️ **Il tetto della stampa non è un numero inventato: deriva dal `memory_limit` dell'host.**
 *
 * Rilievo di punta della Fase 1-bis della beta.23, trovato da tre lenti indipendenti: la stampa
 * esauriva la memoria PRIMA del tempo, e moriva con un fatal error dentro mPDF — pagina bianca,
 * nessuna eccezione applicativa, niente nei log. La tabella a blocchi (vedi
 * `libro_giornale.blade.php`) ha portato la capienza da ~1.000 a ~4.500 righe con
 * `memory_limit = 128M`, ma un tetto serve comunque: oltre, si rifiuta spiegando cosa fare
 * invece di schiantarsi in silenzio.
 */
function tettoConLimite(string $limite): int
{
    // ⚠️ Si passa la stringa alla formula, non si abbassa il `memory_limit` del processo:
    // `ini_set('memory_limit', '128M')` fallisce appena la suite ha già allocato di più
    // («Current memory usage is 210763776 bytes») — misurato, ed era un difetto di questo
    // test, non del codice.
    $metodo = new ReflectionMethod(
        App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController::class,
        'righeStampabiliCon'
    );

    return $metodo->invoke(null, $limite);
}

test('il tetto della stampa cresce con la memoria disponibile dell\'installazione', function () {
    $a128 = tettoConLimite('128M');
    $a256 = tettoConLimite('256M');
    $a512 = tettoConLimite('512M');

    // Su 128M — il parco installato che il progetto dichiara — deve restare largamente sopra
    // il volume di un anno ordinario (~250 scritture, cioè ~600 righe) senza avvicinarsi alle
    // ~4.500 righe che sono la rottura misurata.
    expect($a128)->toBeGreaterThan(1500)
        ->and($a128)->toBeLessThan(4500);

    // Chi ha più memoria ottiene più capienza, senza configurare nulla.
    expect($a256)->toBeGreaterThan($a128);
    expect($a512)->toBeGreaterThan($a256);
});

test('senza limite di memoria non si applica nessun tetto', function () {
    expect(tettoConLimite('-1'))->toBe(PHP_INT_MAX);
});

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

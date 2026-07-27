<?php

use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Regressione: filtri stato + intervallo date aggiunti alla toolbar di
 * "Incassi rate" (stesso pattern già in uso su Libro Giornale / Giroconti).
 */
function adminIncassiRate(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/**
 * Crea una scrittura 'incasso_rata' direttamente via Eloquent (senza passare
 * dall'Action di registrazione, che qui non serve), con una riga collegata
 * sul conto crediti condòmini creato da setupContabile().
 */
function creaIncassoRata(array $ctx, string $stato, string $dataRegistrazione, int $importoCents = 10000): ScritturaContabile
{
    [$condominio, $esercizio, $gestione] = $ctx;

    $conto = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'crediti_condomini')
        ->firstOrFail();

    $scrittura = ScritturaContabile::create([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'data_registrazione' => $dataRegistrazione,
        'data_competenza'    => $dataRegistrazione,
        'causale'            => 'Incasso di test',
        'tipo_movimento'     => 'incasso_rata',
        'stato'              => $stato,
    ]);

    $scrittura->righe()->create([
        'conto_contabile_id' => $conto->id,
        'tipo_riga'          => 'dare',
        'importo'            => $importoCents,
    ]);

    return $scrittura;
}

test('index filtra gli incassi per stato', function () {
    $user = adminIncassiRate();
    $ctx = setupContabile();
    [$condominio] = $ctx;

    creaIncassoRata($ctx, 'registrata', '2026-03-10');
    creaIncassoRata($ctx, 'riconciliata', '2026-03-11');

    $this->actingAs($user)
        ->get(route('admin.gestionale.movimenti-rate.index', ['condominio' => $condominio->id, 'stato' => 'riconciliata']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/incassi/IncassoRateList')
            ->has('movimenti.data', 1)
            ->where('movimenti.data.0.stato', 'riconciliata')
        );
});

test('index filtra gli incassi per intervallo data_registrazione', function () {
    $user = adminIncassiRate();
    $ctx = setupContabile();
    [$condominio] = $ctx;

    creaIncassoRata($ctx, 'registrata', '2026-01-15');
    creaIncassoRata($ctx, 'registrata', '2026-03-15');
    creaIncassoRata($ctx, 'registrata', '2026-05-15');

    $this->actingAs($user)
        ->get(route('admin.gestionale.movimenti-rate.index', [
            'condominio' => $condominio->id,
            'data_da'    => '2026-02-01',
            'data_a'     => '2026-04-01',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/incassi/IncassoRateList')
            ->has('movimenti.data', 1)
            ->where('movimenti.data.0.data_registrazione', '2026-03-15')
        );
});

test('index combina stato e intervallo date insieme', function () {
    $user = adminIncassiRate();
    $ctx = setupContabile();
    [$condominio] = $ctx;

    creaIncassoRata($ctx, 'registrata', '2026-03-10'); // fuori: stato sbagliato
    creaIncassoRata($ctx, 'riconciliata', '2026-01-05'); // fuori: fuori intervallo
    creaIncassoRata($ctx, 'riconciliata', '2026-03-11'); // dentro

    $this->actingAs($user)
        ->get(route('admin.gestionale.movimenti-rate.index', [
            'condominio' => $condominio->id,
            'stato'      => 'riconciliata',
            'data_da'    => '2026-03-01',
            'data_a'     => '2026-03-31',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movimenti.data', 1)
            ->where('movimenti.data.0.data_registrazione', '2026-03-11')
        );
});

test('lo stat "stornati" conta le scritture annullata, non un valore stato inesistente', function () {
    // StornoIncassoRateAction sigilla la scrittura originale con stato='annullata':
    // non esiste un valore 'stornato' nella colonna, il confronto sbagliato dava
    // sempre 0 indipendentemente da quanti incassi fossero stati davvero stornati.
    $user = adminIncassiRate();
    $ctx = setupContabile();
    [$condominio] = $ctx;

    creaIncassoRata($ctx, 'registrata', '2026-03-10');
    creaIncassoRata($ctx, 'annullata', '2026-03-11');
    creaIncassoRata($ctx, 'annullata', '2026-03-12');

    $this->actingAs($user)
        ->get(route('admin.gestionale.movimenti-rate.index', ['condominio' => $condominio->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.stornati', 2));
});

test('index espone i 4 stati ammessi come prop stati', function () {
    $user = adminIncassiRate();
    $ctx = setupContabile();
    [$condominio] = $ctx;

    $this->actingAs($user)
        ->get(route('admin.gestionale.movimenti-rate.index', ['condominio' => $condominio->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stati', ['bozza', 'registrata', 'riconciliata', 'annullata'])
        );
});

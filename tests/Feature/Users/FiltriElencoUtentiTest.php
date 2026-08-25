<?php

/**
 * I due filtri dell'elenco utenti: per ruolo e per stato.
 *
 * ## Perché questo file esiste
 *
 * L'elenco aveva il solo filtro per nome. Con la sospensione che diventa efficace, «chi è sospeso»
 * e «chi è amministratore» sono le due domande che l'amministratore si fa davvero prima di agire,
 * e senza filtro si rispondono scorrendo le pagine.
 *
 * I filtri vivono nella query, non nella tabella a video: filtrare le righe già scaricate
 * risponderebbe sulle dieci in pagina e non sull'archivio — è il difetto che la beta.54 ha
 * corretto per l'ordinamento, e sarebbe stato ripetuto qui.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'interfaccia: verifica cosa la query restituisce, non cosa la tendina mostra. Non
 * copre la combinazione dei filtri con l'ordinamento, coperta dai test dell'ordinamento.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Anna Admin', 'email_verified_at' => now()]);
    $this->admin->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['name' => 'Bruno Collaboratore', 'email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->sospeso = User::factory()->create([
        'name'              => 'Carla Sospesa',
        'email_verified_at' => now(),
        'suspended_at'      => now(),
    ]);
    $this->sospeso->assignRole(RoleEnum::UTENTE->value);
});

/** I nomi che l'elenco restituisce, nell'ordine in cui arrivano. */
function nomiInElenco(array $parametri): array
{
    $nomi = [];

    test()->actingAs(test()->admin)
        ->get(route('utenti.index', $parametri))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $pagina) use (&$nomi) {
            $nomi = collect($pagina->toArray()['props']['users'])->pluck('name')->all();
        });

    return $nomi;
}

it('senza filtri restituisce tutti', function () {
    expect(nomiInElenco([]))->toHaveCount(3);
});

it('filtra per ruolo', function () {
    expect(nomiInElenco(['roles' => [RoleEnum::AMMINISTRATORE->value]]))
        ->toBe(['Anna Admin']);
});

it('accetta più ruoli insieme', function () {
    expect(nomiInElenco(['roles' => [RoleEnum::AMMINISTRATORE->value, RoleEnum::COLLABORATORE->value]]))
        ->toBe(['Anna Admin', 'Bruno Collaboratore']);
});

it('filtra per stato sospeso', function () {
    expect(nomiInElenco(['stato' => ['sospeso']]))->toBe(['Carla Sospesa']);
});

it('filtra per stato attivo', function () {
    expect(nomiInElenco(['stato' => ['attivo']]))->toBe(['Anna Admin', 'Bruno Collaboratore']);
});

it('selezionare entrambi gli stati non è «nessun risultato», è «tutti»', function () {
    // Due caselle spuntate su due possibili non sono una contraddizione: sono l'assenza di filtro.
    expect(nomiInElenco(['stato' => ['attivo', 'sospeso']]))->toHaveCount(3);
});

it('combina ruolo e stato', function () {
    $secondoAdmin = User::factory()->create(['name' => 'Dario Admin', 'suspended_at' => now()]);
    $secondoAdmin->assignRole(RoleEnum::AMMINISTRATORE->value);

    expect(nomiInElenco(['roles' => [RoleEnum::AMMINISTRATORE->value], 'stato' => ['sospeso']]))
        ->toBe(['Dario Admin']);
});

it('un ruolo inventato viene rifiutato, non ignorato', function () {
    $this->actingAs($this->admin)
        ->get(route('utenti.index', ['roles' => ['ruolo-che-non-esiste']]))
        ->assertSessionHasErrors('roles.0');
});

it('uno stato inventato viene rifiutato', function () {
    $this->actingAs($this->admin)
        ->get(route('utenti.index', ['stato' => ['in-ferie']]))
        ->assertSessionHasErrors('stato.0');
});

it('il filtro lavora sull\'archivio, non sulla pagina', function () {
    // Con dieci righe per pagina e tredici utenti, un filtro applicato alle sole righe scaricate
    // risponderebbe «nessun sospeso» pur essendocene uno in fondo all'elenco.
    User::factory()->count(10)->create(['name' => 'Zeta riempitivo']);

    expect(nomiInElenco(['stato' => ['sospeso'], 'per_page' => 10]))->toBe(['Carla Sospesa']);
});

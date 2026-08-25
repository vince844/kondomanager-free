<?php

/**
 * Un'installazione non può restare senza amministratori.
 *
 * ## Perché questo file esiste
 *
 * Fino alla beta.55 niente lo impediva: `UserController::destroy` cancellava chiunque senza
 * guardare chi restava, `syncRoles` poteva togliere il ruolo all'unico amministratore, e la
 * sospensione — una volta resa efficace — sarebbe diventata il terzo modo per chiudersi fuori di
 * casa. Da un'installazione senza amministratori non si esce dall'interfaccia: serve `tinker` o SQL.
 *
 * Il precedente in casa è `HasProtectedRoles`, che impedisce di cancellare i quattro ruoli di
 * sistema. Qui la stessa idea scende di un gradino, dal ruolo all'utente.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la creazione (nessuno perde l'ultimo amministratore creando un utente), e non copre il
 * caso di un amministratore **sospeso** che sia l'unico rimasto: la regola guarda gli amministratori
 * **attivi**, quindi con un solo amministratore già sospeso l'installazione è già nello stato che
 * questi test impediscono di raggiungere. Recuperarlo è un'altra questione.
 */

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(RoleEnum::AMMINISTRATORE->value);
});

function scheda(User $utente, array $modifiche = []): array
{
    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $utente->roles->first()?->id,
        'permissions' => [],
        'anagrafica'  => null,
    ], $modifiche);
}

it('l\'ultimo amministratore non può essere sospeso', function () {
    $this->actingAs($this->admin)
        ->put(route('utenti.suspend', ['user' => $this->admin->id]));

    expect($this->admin->fresh()->suspended())->toBeFalse();
});

it('l\'ultimo amministratore non può essere eliminato', function () {
    $this->actingAs($this->admin)
        ->delete(route('utenti.destroy', ['utenti' => $this->admin->id]));

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('l\'ultimo amministratore non può essere degradato', function () {
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();

    $this->actingAs($this->admin)
        ->put(
            route('utenti.update', ['utenti' => $this->admin->id]),
            scheda($this->admin, ['roles' => $utente->id])
        );

    expect($this->admin->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();
});

it('nessuno può sospendere sé stesso, nemmeno con un secondo amministratore in casa', function () {
    $secondo = User::factory()->create(['email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($this->admin)
        ->put(route('utenti.suspend', ['user' => $this->admin->id]));

    expect($this->admin->fresh()->suspended())->toBeFalse();
});

it('l\'elenco marca l\'ultimo amministratore, così il menù non offre ciò che il server rifiuta', function () {
    $altro = User::factory()->create(['email_verified_at' => now()]);
    $altro->assignRole(RoleEnum::UTENTE->value);

    $this->actingAs($this->admin)
        ->get(route('utenti.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $pagina) use ($altro) {
            $righe = collect($pagina->toArray()['props']['users']);

            expect($righe->firstWhere('id', $this->admin->id)['is_ultimo_amministratore'])->toBeTrue()
                ->and($righe->firstWhere('id', $this->admin->id)['is_self'])->toBeTrue()
                ->and($righe->firstWhere('id', $altro->id)['is_ultimo_amministratore'])->toBeFalse();
        });
});

it('con due amministratori si può sospendere l\'altro', function () {
    $secondo = User::factory()->create(['email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($this->admin)
        ->put(route('utenti.suspend', ['user' => $secondo->id]));

    expect($secondo->fresh()->suspended())->toBeTrue();
});

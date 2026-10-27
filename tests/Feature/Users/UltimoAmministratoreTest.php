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

// ─── I ruoli mandati come elenco (Coda 258, decisione 130.1) ──────────────────

it('Coda 258 — l\'ultimo amministratore non si degrada con un elenco di ruoli senza «amministratore», in nessuna forma', function () {
    // Spatie appiattisce l'elenco e assegna ogni elemento: la regola guarda l'elenco intero, non il primo.
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $fornitore = Role::where('name', RoleEnum::FORNITORE->value)->firstOrFail();

    foreach ([
        'due id'                      => [$utente->id, $fornitore->id],
        'un elenco dentro l\'elenco'  => [[$utente->id, $fornitore->id]],
        'un elemento con id e nome'   => [['id' => $utente->id, 'name' => RoleEnum::FORNITORE->value]],
        'per nome'                    => [RoleEnum::UTENTE->value],
    ] as $forma => $ruoli) {
        $this->actingAs($this->admin)
            ->put(route('utenti.update', ['utenti' => $this->admin->id]), scheda($this->admin, ['roles' => $ruoli]))
            ->assertSessionHasErrors('roles');

        expect($this->admin->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value], $forma);
    }
});

it('Coda 258 — un elenco che tiene «amministratore» non degrada l\'ultimo amministratore, e passa', function () {
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $amministratore = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('utenti.update', ['utenti' => $this->admin->id]), scheda($this->admin, ['roles' => [$utente->id, $amministratore->id]]))
        ->assertSessionHasNoErrors();

    expect($this->admin->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();
});

// ─── L'eliminazione del proprio account dal profilo ───────────────────────────
// Il quarto modo di restare senza amministratori: il profilo offre a tutti «Elimina account» e chiedeva
// solo la password. La regola è la stessa delle altre tre (`isUltimoAmministratoreAttivo()`), e l'errore
// arriva sotto il campo della password della finestra, l'unico che la finestra mostra.

/** L'eliminazione come la manda la finestra del profilo (`DeleteUser.vue`, `form.delete`). */
function eliminaDalProfilo($test, User $utente, string $password = 'password')
{
    return $test->actingAs($utente)
        ->from('/settings/profile')
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->delete('/settings/profile', ['password' => $password]);
}

it('l\'ultimo amministratore non elimina il proprio account dal profilo: la finestra dice perché, e l\'account resta', function () {
    eliminaDalProfilo($this, $this->admin)
        ->assertRedirect('/settings/profile')
        ->assertSessionHasErrors(['password' => __('policies.users_last_admin_self')]);

    expect(User::find($this->admin->id))->not->toBeNull()
        ->and(User::role(RoleEnum::AMMINISTRATORE->value)->count())->toBe(1)
        ->and(auth()->check())->toBeTrue();
});

it('un amministratore sospeso non conta: l\'unico attivo non elimina il proprio account dal profilo', function () {
    $sospeso = User::factory()->create(['email_verified_at' => now(), 'suspended_at' => now()]);
    $sospeso->assignRole(RoleEnum::AMMINISTRATORE->value);

    eliminaDalProfilo($this, $this->admin)
        ->assertSessionHasErrors(['password' => __('policies.users_last_admin_self')]);

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('con un altro amministratore attivo, l\'amministratore elimina il proprio account dal profilo', function () {
    $secondo = User::factory()->create(['email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);

    eliminaDalProfilo($this, $this->admin)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($this->admin->id))->toBeNull()
        ->and(User::find($secondo->id))->not->toBeNull();
});

it('chi non è amministratore continua a eliminare il proprio account dal profilo, anche con un solo amministratore in casa', function () {
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(RoleEnum::UTENTE->value);

    eliminaDalProfilo($this, $condomino)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($condomino->id))->toBeNull()
        ->and(User::find($this->admin->id))->not->toBeNull();
});

it('l\'ultimo amministratore legge perché anche con la password sbagliata: la password non si prova per un\'eliminazione che non può avvenire', function () {
    eliminaDalProfilo($this, $this->admin, 'sbagliata')
        ->assertSessionHasErrors(['password' => __('policies.users_last_admin_self')]);

    expect(User::find($this->admin->id))->not->toBeNull();
});

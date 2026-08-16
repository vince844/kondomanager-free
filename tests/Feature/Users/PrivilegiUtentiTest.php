<?php

/**
 * Chi può concedere cosa, sulla scheda di un utente.
 *
 * ## Perché questo file esiste
 *
 * Il gruppo di rotte `/utenti` è stato scritto quando l'unico che ci arrivava era l'amministratore,
 * e non è stato rivisto quando è nato il ruolo `collaboratore`. Alla beta.55 l'accertamento ha
 * trovato tre buchi, tutti raggiungibili **dall'interfaccia**, non con richieste costruite a mano:
 *
 * 1. chi ha `EDIT_USERS` — cioè ogni collaboratore — può assegnarsi il ruolo `amministratore`
 *    scegliendolo dalla tendina, perché `UpdateUserRequest` valida i ruoli con un `['required']`
 *    nudo e la pagina di modifica riceve l'elenco completo dei ruoli;
 * 2. lo stesso vale per i permessi singoli, concedibili anche se l'attore non li possiede;
 * 3. `suspend`/`unsuspend` non hanno alcun `Gate::authorize`, quindi qualunque utente autenticato
 *    e verificato può sospendere chiunque, amministratori inclusi;
 * 4. `utenti/reinvite/{email}` è fuori da `auth` e azzera la password del bersaglio.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la creazione utente (`CreateUserRequest` ha la stessa forma e va coperta con il fix),
 * non copre l'associazione all'anagrafica, non copre l'interfaccia: verifica cosa il server accetta,
 * non cosa la tendina mostra. Le due cose vanno tenute allineate a mano.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

/** Il modulo che la pagina di modifica manda davvero: il ruolo è un id, i permessi un elenco di id. */
function schedaUtente(User $utente, array $modifiche = []): array
{
    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $utente->roles->first()?->id,
        'permissions' => [],
        'anagrafica'  => null,
    ], $modifiche);
}

it('un collaboratore non può promuoversi ad amministratore', function () {
    $amministratore = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->collaboratore->id]),
            schedaUtente($this->collaboratore, ['roles' => $amministratore->id])
        );

    expect($this->collaboratore->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeFalse();
});

it('un collaboratore non può promuovere ad amministratore qualcun altro', function () {
    $amministratore = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => $amministratore->id])
        );

    expect($this->condomino->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeFalse();
});

it('un collaboratore non può concedere un permesso che non possiede', function () {
    // «Elimina utenti» non è fra i permessi del collaboratore: vedi Role::permissions().
    expect($this->collaboratore->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeFalse();

    $permesso = \Spatie\Permission\Models\Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->collaboratore->id]),
            schedaUtente($this->collaboratore, ['permissions' => [$permesso->id]])
        );

    expect($this->collaboratore->fresh()->hasDirectPermission(PermissionEnum::DELETE_USERS->value))->toBeFalse();
});

it('la regola non si aggira mandando il nome del ruolo invece dell\'id', function () {
    // Trovato dalla revisione avversariale della beta.55, lente «dove un valore cambia forma».
    // Il modulo manda un id, ma `syncRoles()` accetta anche i **nomi**: una regola che risolve il
    // ruolo solo per id lascia passare la stringa e poi Spatie la onora.
    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->collaboratore->id]),
            schedaUtente($this->collaboratore, ['roles' => RoleEnum::AMMINISTRATORE->value])
        );

    expect($this->collaboratore->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeFalse();
});

it('la regola non si aggira mandando il nome del permesso invece dell\'id', function () {
    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->collaboratore->id]),
            schedaUtente($this->collaboratore, ['permissions' => [PermissionEnum::DELETE_USERS->value]])
        );

    expect($this->collaboratore->fresh()->hasDirectPermission(PermissionEnum::DELETE_USERS->value))->toBeFalse();
});

it('un ruolo inesistente non passa in silenzio', function () {
    $this->actingAs($this->amministratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => 'ruolo-che-non-esiste'])
        )
        ->assertSessionHasErrors('roles');
});

it('un collaboratore non può reinvitare un amministratore: reinvitare azzera la password', function () {
    $passwordPrima = $this->amministratore->password;

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.reinvite', ['email' => $this->amministratore->email]))
        ->assertForbidden();

    expect($this->amministratore->fresh()->password)->toBe($passwordPrima);
});

it('il collaboratore continua a poter reinvitare un condòmino', function () {
    $this->actingAs($this->collaboratore)
        ->post(route('utenti.reinvite', ['email' => $this->condomino->email]))
        ->assertRedirect(route('utenti.index'));
});

it('il collaboratore continua a poter assegnare i ruoli non privilegiati', function () {
    $fornitore = Role::where('name', RoleEnum::FORNITORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => $fornitore->id])
        );

    expect($this->condomino->fresh()->hasRole(RoleEnum::FORNITORE->value))->toBeTrue();
});

it('un condòmino non può sospendere un amministratore', function () {
    $this->actingAs($this->condomino)
        ->put(route('utenti.suspend', ['user' => $this->amministratore->id]))
        ->assertForbidden();

    expect($this->amministratore->fresh()->suspended())->toBeFalse();
});

it('un condòmino non può riattivare un utente sospeso', function () {
    $this->amministratore->update(['suspended_at' => now()]);

    $this->actingAs($this->condomino)
        ->put(route('utenti.unsuspend', ['user' => $this->amministratore->id]))
        ->assertForbidden();

    expect($this->amministratore->fresh()->suspended())->toBeTrue();
});

it('la rotta di reinvito non è raggiungibile da un anonimo, e non azzera la password', function () {
    $passwordPrima = $this->amministratore->password;

    $this->post(route('utenti.reinvite', ['email' => $this->amministratore->email]))
        ->assertRedirect(route('login'));

    expect($this->amministratore->fresh()->password)->toBe($passwordPrima);
});

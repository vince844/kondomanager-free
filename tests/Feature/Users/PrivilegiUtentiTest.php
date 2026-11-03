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
 * ## I ruoli e i permessi mandati come elenco (Coda 258, decisione 130.1)
 *
 * La pagina manda un ruolo solo, ma la richiesta può portarne un elenco, e
 * `assignRole()`/`syncRoles()` li assegnano tutti dopo aver appiattito l'elenco. Ogni ruolo
 * dell'elenco passa dalla regola, in creazione e in modifica, nelle forme che Spatie accetta (id,
 * nome, elenchi annidati); lo stesso per i permessi, che `givePermissionTo()` appiattisce allo stesso
 * modo. I test qui sotto mandano anche le forme che la pagina non usa, perché il server le accetta
 * comunque.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'associazione all'anagrafica (`AnagraficaCollegataTest.php`), non copre l'interfaccia:
 * verifica cosa il server accetta, non cosa la tendina mostra. Le due cose vanno tenute allineate a mano.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
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

// Coda 258, decisione 134.1: chi non è amministratore assegna solo `utente` e `fornitore`; un ruolo su misura no
// (`ChiPuoToccareChiTest.php`), e il nome di prima («i ruoli non privilegiati») li comprendeva.
it('il collaboratore continua a poter assegnare i ruoli utente e fornitore', function () {
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

// ─── I ruoli e i permessi mandati come elenco (Coda 258, decisione 130.1) ─────

it('Coda 258 — in modifica la regola sui ruoli guarda ogni ruolo di un elenco: il condòmino resta condòmino', function () {
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $amministratore = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => [$utente->id, $amministratore->id]])
        )
        ->assertSessionHasErrors('roles');

    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 258 — in creazione la regola sui ruoli guarda ogni ruolo di un elenco', function () {
    Notification::fake();
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $amministratore = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.store'), [
            'name'        => 'Utente Nuovo',
            'email'       => 'utente.nuovo@example.com',
            'roles'       => [$utente->id, $amministratore->id],
            'permissions' => [],
            'anagrafica'  => null,
        ])
        ->assertSessionHasErrors('roles');

    expect(User::where('email', 'utente.nuovo@example.com')->exists())->toBeFalse();
});

/** Le forme in cui un elenco di ruoli arriva a Spatie, che le appiattisce e le assegna tutte. */
function elenchiDiRuoliConUnPrivilegiato(): array
{
    $id = fn (RoleEnum $ruolo) => Role::where('name', $ruolo->value)->firstOrFail()->id;

    return [
        'id, privilegiato per secondo'    => [$id(RoleEnum::UTENTE), $id(RoleEnum::AMMINISTRATORE)],
        'id, privilegiato per primo'      => [$id(RoleEnum::AMMINISTRATORE), $id(RoleEnum::UTENTE)],
        'collaboratore per secondo'       => [$id(RoleEnum::FORNITORE), $id(RoleEnum::COLLABORATORE)],
        'il privilegiato per nome'        => [$id(RoleEnum::UTENTE), RoleEnum::AMMINISTRATORE->value],
        'un elenco dentro l\'elenco'      => [[$id(RoleEnum::UTENTE), $id(RoleEnum::AMMINISTRATORE)]],
        'un elemento con id e nome'       => [['id' => $id(RoleEnum::UTENTE), 'name' => RoleEnum::AMMINISTRATORE->value]],
    ];
}

it('Coda 258 — in modifica ogni ruolo dell\'elenco passa dalla regola, in ogni forma', function () {
    foreach (elenchiDiRuoliConUnPrivilegiato() as $forma => $ruoli) {
        $this->actingAs($this->collaboratore)
            ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaUtente($this->condomino, ['roles' => $ruoli]))
            ->assertSessionHasErrors('roles');

        expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value], $forma);
    }
});

it('Coda 258 — in creazione ogni ruolo dell\'elenco passa dalla regola, in ogni forma', function () {
    Notification::fake();

    foreach (elenchiDiRuoliConUnPrivilegiato() as $forma => $ruoli) {
        $this->actingAs($this->collaboratore)
            ->post(route('utenti.store'), [
                'name'        => 'Utente Nuovo',
                'email'       => 'utente.nuovo@example.com',
                'roles'       => $ruoli,
                'permissions' => [],
                'anagrafica'  => null,
            ])
            ->assertSessionHasErrors('roles');

        expect(User::where('email', 'utente.nuovo@example.com')->exists())->toBeFalse($forma);
    }
});

it('Coda 258 — un elenco di ruoli che il collaboratore può dare passa ancora', function () {
    // La regola guarda ogni ruolo, non rifiuta l'elenco: con ruoli tutti concedibili il salvataggio riesce.
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $fornitore = Role::where('name', RoleEnum::FORNITORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => [$utente->id, $fornitore->id]])
        )
        ->assertSessionHasNoErrors();

    expect($this->condomino->fresh()->getRoleNames()->sort()->values()->all())
        ->toBe([RoleEnum::FORNITORE->value, RoleEnum::UTENTE->value]);
});

it('Coda 258 — un permesso che il collaboratore non ha non passa nemmeno accanto a uno che ha', function () {
    // `regolaPermessiConcedibili` guarda ogni permesso dell'elenco, in modifica e in creazione.
    Notification::fake();
    $suo = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();
    $nonSuo = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['permissions' => [$suo->id, $nonSuo->id]])
        )
        ->assertSessionHasErrors('permissions');

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.store'), [
            'name'        => 'Utente Nuovo',
            'email'       => 'utente.nuovo@example.com',
            'roles'       => Role::where('name', RoleEnum::UTENTE->value)->firstOrFail()->id,
            'permissions' => [$suo->id, $nonSuo->id],
            'anagrafica'  => null,
        ])
        ->assertSessionHasErrors('permissions');

    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::DELETE_USERS->value))->toBeFalse()
        ->and(User::where('email', 'utente.nuovo@example.com')->exists())->toBeFalse();
});

it('Coda 258 — un permesso che il collaboratore non ha non passa dentro un elemento che porta id e nome', function () {
    // `givePermissionTo()` appiattisce l'elemento e concede l'id e il nome: la regola li guarda tutti e due.
    Notification::fake();
    $suo = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.store'), [
            'name'        => 'Utente Nuovo',
            'email'       => 'utente.nuovo@example.com',
            'roles'       => Role::where('name', RoleEnum::UTENTE->value)->firstOrFail()->id,
            'permissions' => [['id' => $suo->id, 'name' => PermissionEnum::MANAGE_GENERAL_SETTINGS->value]],
            'anagrafica'  => null,
        ])
        ->assertSessionHasErrors('permissions');

    expect(User::where('email', 'utente.nuovo@example.com')->first()?->hasPermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value) ?? false)
        ->toBeFalse();
});

it('Coda 258 — un elenco di ruoli vuoto, anche annidato, non toglie i ruoli', function () {
    // `syncRoles()` con un elenco che non contiene ruoli toglierebbe tutti quelli che ci sono.
    foreach ([[[]], [[[]]], [null]] as $ruoli) {
        $this->actingAs($this->collaboratore)
            ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaUtente($this->condomino, ['roles' => $ruoli]))
            ->assertSessionHasErrors('roles');

        expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
    }
});

it('Coda 258 — la regola scompone l\'elenco come lo assegna Spatie: ruoli concedibili in un elenco annidato passano tutti', function () {
    // `syncRoles()` appiattisce l'elenco: la regola guarda gli stessi valori, non li scarta né li scambia per altro.
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $fornitore = Role::where('name', RoleEnum::FORNITORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->condomino->id]),
            schedaUtente($this->condomino, ['roles' => [[$utente->id], ['id' => $fornitore->id]]])
        )
        ->assertSessionHasNoErrors();

    expect($this->condomino->fresh()->getRoleNames()->sort()->values()->all())
        ->toBe([RoleEnum::FORNITORE->value, RoleEnum::UTENTE->value]);
});

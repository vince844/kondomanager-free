<?php

/**
 * Coda 261 — dare poteri chiede la password ridigitata da poco (decisione 134.1).
 *
 * ## Perché questo file esiste
 *
 * Dare poteri chiede la password, non soltanto la sessione: un accesso nuovo con poteri passa dalla
 * password di chi lo concede, che sia un ruolo, un permesso aggiuntivo o il ruolo della
 * registrazione pubblica. Le azioni che danno poteri chiedono la password ridigitata da non più di
 * quindici minuti, con lo stesso meccanismo delle altre azioni della Coda 261
 * (`ConfermaPasswordRecente`):
 *
 * - concedere un ruolo diverso da `utente` e `fornitore`, in creazione o in modifica di un utente;
 * - creare o modificare un ruolo su misura (aggiungere permessi a un ruolo alza chi ce l'ha);
 * - cambiare il ruolo della registrazione pubblica.
 *
 * Solo quando l'azione lo chiede: salvare un condòmino, la scheda di un amministratore senza cambiargli i
 * ruoli o le impostazioni generali con lo stesso ruolo della registrazione non chiedono niente. Ogni
 * richiesta è mandata come la manda la sua pagina, e dopo la conferma si torna alla pagina del modulo.
 *
 * ## Cosa questo file NON copre
 *
 * Chi può concedere che cosa (`ChiPuoToccareChiTest.php`, `PrivilegiUtentiTest.php`): qui chi agisce è
 * sempre l'amministratore, l'unico che quei poteri li può dare.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

/** La password ridigitata da `$secondi` secondi. */
function poteriConfermataDa(int $secondi): array
{
    return ['auth.password_confirmed_at' => time() - $secondi];
}

function poteriIdRuolo(string $nome): int
{
    return Role::where('name', $nome)->firstOrFail()->id;
}

/** Il modulo di «Nuovo utente» come lo manda la pagina. */
function poteriNuovoUtente(string $email, int $ruolo): array
{
    return ['name' => 'Ombra', 'email' => $email, 'roles' => $ruolo, 'permissions' => [], 'anagrafica' => null];
}

/** La scheda come la manda `ModificaUtente.vue`: intera, con il ruolo come id. */
function poteriScheda(User $utente, array $modifiche = []): array
{
    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $utente->roles->first()?->id,
        'permissions' => [],
        'anagrafica'  => null,
    ], $modifiche);
}

/** Il modulo delle impostazioni generali come lo manda la pagina, dai dati che riceve. */
function poteriImpostazioni($test, User $chi, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];

    return array_merge([
        'user_frontend_registration' => (bool) $props['can_register'],
        'language'                   => $props['language'],
        'app_name'                   => $props['app_name'],
        'open_condominio_on_login'   => (bool) $props['open_condominio_on_login'],
        'default_condominio_id'      => $props['default_condominio_id'] ? (string) $props['default_condominio_id'] : '',
        'default_user_role'          => $props['default_user_role'],
        'force_comment_moderation'   => (bool) $props['force_comment_moderation'],
        'default_per_page'           => (int) $props['default_per_page'],
    ], $modifiche);
}

// ─── Concedere un ruolo diverso da utente e fornitore ─────────────────────────────────────────────────

it('Coda 261 — creare un amministratore chiede la password, e dopo la conferma si torna al modulo del nuovo utente', function () {
    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), poteriNuovoUtente('ombra@example.com', poteriIdRuolo(RoleEnum::AMMINISTRATORE->value)))
        ->assertRedirect(route('password.confirm'));
    expect(User::where('email', 'ombra@example.com')->exists())->toBeFalse();

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('utenti.create'));

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), poteriNuovoUtente('ombra@example.com', poteriIdRuolo(RoleEnum::AMMINISTRATORE->value)))
        ->assertRedirect(route('utenti.index'));
    expect(User::where('email', 'ombra@example.com')->first()?->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();
});

it('Coda 261 — creare un utente con un ruolo diverso da utente e fornitore chiede la password, anche con un elenco di ruoli', function (string $ruolo) {
    Role::create(['name' => 'Consigliere', 'guard_name' => 'web'])->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value);

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), array_merge(poteriNuovoUtente('nuovo@example.com', 0), [
            'roles' => [poteriIdRuolo(RoleEnum::UTENTE->value), poteriIdRuolo($ruolo)],
        ]))
        ->assertRedirect(route('password.confirm'));

    expect(User::where('email', 'nuovo@example.com')->exists())->toBeFalse();
})->with([
    'collaboratore' => [RoleEnum::COLLABORATORE->value],
    'un ruolo su misura' => ['Consigliere'],
]);

it('Coda 261 — creare un condòmino o un fornitore non chiede la password', function (string $ruolo) {
    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), poteriNuovoUtente('nuovo@example.com', poteriIdRuolo($ruolo)))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    expect(User::where('email', 'nuovo@example.com')->first()?->hasRole($ruolo))->toBeTrue();
})->with([[RoleEnum::UTENTE->value], [RoleEnum::FORNITORE->value]]);

it('Coda 261 — promuovere un condòmino chiede la password, e dopo la conferma si torna alla sua scheda', function () {
    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), poteriScheda($this->condomino, [
            'roles' => poteriIdRuolo(RoleEnum::COLLABORATORE->value),
        ]))
        ->assertRedirect(route('password.confirm'));
    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('utenti.edit', ['utenti' => $this->condomino->id]));

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), poteriScheda($this->condomino, [
            'roles' => poteriIdRuolo(RoleEnum::COLLABORATORE->value),
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::COLLABORATORE->value]);
});

it('Coda 261 — la conferma vale quindici minuti anche per dare poteri', function () {
    $this->actingAs($this->amministratore)->withSession(poteriConfermataDa(14 * 60))
        ->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), poteriScheda($this->condomino, [
            'roles' => poteriIdRuolo(RoleEnum::AMMINISTRATORE->value),
        ]))
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();

    $altro = User::factory()->create(['email_verified_at' => now()]);
    $altro->assignRole(RoleEnum::UTENTE->value);
    $this->actingAs($this->amministratore)->withSession(poteriConfermataDa(16 * 60))
        ->from(route('utenti.edit', ['utenti' => $altro->id]))
        ->put(route('utenti.update', ['utenti' => $altro->id]), poteriScheda($altro, [
            'roles' => poteriIdRuolo(RoleEnum::AMMINISTRATORE->value),
        ]))
        ->assertRedirect(route('password.confirm'));
    expect($altro->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeFalse();
});

it('Coda 261 — la scheda di un amministratore aperta e mandata senza toccare niente non chiede la password', function () {
    $secondo = User::factory()->create(['name' => "D'Àngelo <b>Secondo</b>", 'email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);
    $secondo->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $props = $this->actingAs($this->amministratore)
        ->get(route('utenti.edit', ['utenti' => $secondo->id]))->assertOk()->viewData('page')['props'];
    $dalRuolo = collect($props['user']['roles'][0]['permissions'] ?? [])->pluck('id')->all();

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $secondo->id]))
        ->put(route('utenti.update', ['utenti' => $secondo->id]), [
            'name'        => $props['user']['name'],
            'email'       => $props['user']['email'],
            'roles'       => $props['user']['roles'][0]['id'],
            'permissions' => collect($props['user']['permissions'])->pluck('id')->reject(fn ($id) => in_array($id, $dalRuolo, true))->values()->all(),
            'anagrafica'  => $props['user']['anagrafica']['id'] ?? null,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    expect($secondo->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value]);
});

it('Coda 261 — togliere un ruolo, senza darne uno diverso da utente e fornitore, non chiede la password', function () {
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $collaboratore->id]))
        ->put(route('utenti.update', ['utenti' => $collaboratore->id]), poteriScheda($collaboratore, [
            'roles' => poteriIdRuolo(RoleEnum::UTENTE->value),
        ]))
        ->assertRedirect(route('utenti.index'));

    expect($collaboratore->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 261 — chi chiede JSON per dare poteri riceve 423, e niente cambia', function () {
    $this->actingAs($this->amministratore)
        ->putJson(route('utenti.update', ['utenti' => $this->condomino->id]), poteriScheda($this->condomino, [
            'roles' => poteriIdRuolo(RoleEnum::AMMINISTRATORE->value),
        ]))
        ->assertStatus(423);

    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

// ─── Creare o modificare un ruolo su misura ───────────────────────────────────────────────────────────

it('Coda 261 — creare un ruolo su misura chiede la password, e dopo la conferma si torna al modulo del nuovo ruolo', function () {
    $modulo = [
        'name' => 'Contabile', 'description' => 'Contabilità',
        'permissions' => [Permission::where('name', PermissionEnum::MANAGE_GENERAL_SETTINGS->value)->firstOrFail()->id],
        'accessAdmin' => true,
    ];

    $this->actingAs($this->amministratore)->from(route('ruoli.create'))
        ->post(route('ruoli.store'), $modulo)
        ->assertRedirect(route('password.confirm'));
    expect(Role::where('name', 'Contabile')->exists())->toBeFalse();

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('ruoli.create'));

    $this->actingAs($this->amministratore)->from(route('ruoli.create'))
        ->post(route('ruoli.store'), $modulo)
        ->assertRedirect(route('ruoli.index'));
    expect(Role::where('name', 'Contabile')->first()?->hasPermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value))->toBeTrue();
});

it('Coda 261 — modificare un ruolo su misura chiede la password, e dopo la conferma si torna alla sua pagina', function () {
    $ruolo = Role::create(['name' => 'Contabile', 'guard_name' => 'web', 'description' => 'Contabilità']);
    $ruolo->givePermissionTo(PermissionEnum::ACCESS_ADMIN_PANEL->value);
    $modulo = [
        'name' => 'Contabile', 'description' => 'Contabilità',
        'permissions' => [Permission::where('name', PermissionEnum::MANAGE_GENERAL_SETTINGS->value)->firstOrFail()->id],
        'accessAdmin' => true,
    ];

    $this->actingAs($this->amministratore)->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), $modulo)
        ->assertRedirect(route('password.confirm'));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value))->toBeFalse();

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('ruoli.edit', ['ruoli' => $ruolo->id]));

    $this->actingAs($this->amministratore)->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), $modulo)
        ->assertRedirect(route('ruoli.index'));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value))->toBeTrue();
});

it('Coda 261 — la pagina di un ruolo su misura aperta e mandata senza toccare niente chiede la password, e dopo la conferma il ruolo resta com\'era', function () {
    $ruolo = Role::create(['name' => 'Contabile', 'guard_name' => 'web', 'description' => "Contabilità «ordinaria» e d'esercizio"]);
    $ruolo->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value, PermissionEnum::MANAGE_GENERAL_SETTINGS->value]);

    // Il corpo come lo costruisce `ModificaRuolo.vue` dai dati che riceve.
    $props = $this->actingAs($this->amministratore)->get(route('ruoli.edit', ['ruoli' => $ruolo->id]))->assertOk()->viewData('page')['props'];
    $permessi = collect($props['role']['permissions']);
    $corpo = [
        'name'        => (string) $props['role']['name'],
        'description' => $props['role']['description'],
        'permissions' => $permessi->reject(fn ($p) => $p['name'] === PermissionEnum::ACCESS_ADMIN_PANEL->value)->pluck('id')->values()->all(),
        'accessAdmin' => $permessi->contains(fn ($p) => $p['name'] === PermissionEnum::ACCESS_ADMIN_PANEL->value),
    ];

    $this->actingAs($this->amministratore)->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), $corpo)
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->amministratore)->withSession(poteriConfermataDa(60))->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ruoli.index'));

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $dopo = $ruolo->fresh();
    expect($dopo->name)->toBe('Contabile')
        ->and($dopo->description)->toBe("Contabilità «ordinaria» e d'esercizio")
        ->and($dopo->permissions->pluck('name')->sort()->values()->all())->toBe([
            PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::MANAGE_GENERAL_SETTINGS->value, PermissionEnum::VIEW_USERS->value,
        ]);
});

it('Coda 261 — eliminare un ruolo su misura o togliergli un permesso non chiede la password', function () {
    $ruolo = Role::create(['name' => 'Contabile', 'guard_name' => 'web', 'description' => 'Contabilità']);
    $ruolo->givePermissionTo([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value]);

    $this->actingAs($this->amministratore)->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->delete(route('ruoli.permissions.destroy', [
            'role' => $ruolo->id, 'permission' => Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail()->id,
        ]))
        ->assertRedirect(route('ruoli.edit', ['ruoli' => $ruolo->id]));

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'));

    expect(Role::where('name', 'Contabile')->exists())->toBeFalse();
});

// ─── Il ruolo della registrazione pubblica ────────────────────────────────────────────────────────────

it('Coda 261 — cambiare il ruolo della registrazione chiede la password, e dopo la conferma si torna alle impostazioni generali', function () {
    $modulo = poteriImpostazioni($this, $this->amministratore, [
        'default_user_role' => RoleEnum::AMMINISTRATORE->value, 'user_frontend_registration' => true,
    ]);

    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), $modulo)
        ->assertRedirect(route('password.confirm'));
    expect(app(GeneralSettings::class)->default_user_role)->toBe(RoleEnum::UTENTE->value);

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('impostazioni.generali'));

    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), $modulo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->refresh();
    expect($impostazioni->default_user_role)->toBe(RoleEnum::AMMINISTRATORE->value);
});

it('Coda 261 — le impostazioni generali salvate con lo stesso ruolo della registrazione non chiedono la password', function () {
    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), poteriImpostazioni($this, $this->amministratore, ['app_name' => 'Condominio Aurora']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->refresh();
    expect($impostazioni->app_name)->toBe('Condominio Aurora');
});

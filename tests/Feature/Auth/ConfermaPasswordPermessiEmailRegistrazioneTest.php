<?php

/**
 * Coda 261 — la password ridigitata da poco anche per i permessi aggiuntivi, per l'email di chi ha poteri e
 * per accendere la registrazione pubblica con un ruolo che dà poteri.
 *
 * ## Perché questo file esiste
 *
 * Dare poteri chiede la password ridigitata da non più di quindici minuti (decisione 134.1,
 * `ConfermaPasswordPerIPoteriTest.php`): concedere un ruolo diverso da `utente` e `fornitore`, creare o
 * modificare un ruolo su misura, cambiare il ruolo della registrazione. La chiedono anche:
 *
 * - concedere un permesso aggiuntivo che l'account non ha già, in creazione e dalla scheda; quelli sui propri
 *   contenuti (`*_OWN_*`) no, perché non danno poteri su nessun altro;
 * - cambiare l'email di un account che non ha soltanto i ruoli `utente` e `fornitore`, o che ha un permesso
 *   aggiuntivo che non è sui propri contenuti: l'email è la chiave dell'account («password dimenticata»);
 * - accendere la registrazione pubblica quando il ruolo dei nuovi iscritti non è `utente` né `fornitore`.
 *
 * Solo quando l'azione lo chiede: la scheda aperta e inviata, un permesso tolto, l'email di un condòmino, la
 * registrazione accesa con il ruolo dei condòmini non chiedono niente. Ogni richiesta è mandata come la manda
 * la sua pagina, e dopo la conferma si torna alla pagina del modulo, con il meccanismo delle altre azioni della
 * Coda 261 (`ConfermaPasswordRecente`, 423 a chi chiede JSON).
 *
 * ## Cosa questo file NON copre
 *
 * Chi può concedere che cosa (`ChiPuoToccareChiTest.php`, `PrivilegiUtentiTest.php`).
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

    $this->amministratore = cpeUtente(RoleEnum::AMMINISTRATORE->value);
    $this->collaboratore = cpeUtente(RoleEnum::COLLABORATORE->value);
    $this->condomino = cpeUtente(RoleEnum::UTENTE->value);
});

function cpeUtente(string|Role ...$ruoli): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);

    if ($ruoli !== []) {
        $utente->assignRole($ruoli);
    }

    return $utente;
}

function cpeIdRuolo(string $nome): int
{
    return Role::where('name', $nome)->firstOrFail()->id;
}

/** @return array<int, int> */
function cpeIdPermessi(PermissionEnum ...$permessi): array
{
    return array_map(fn (PermissionEnum $p) => Permission::where('name', $p->value)->firstOrFail()->id, $permessi);
}

/** I permessi di governo che si possono dare a un condòmino senza nessun ruolo nuovo. */
function cpePermessiDiGoverno(): array
{
    return [
        PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::VIEW_USERS, PermissionEnum::CREATE_USERS,
        PermissionEnum::EDIT_USERS, PermissionEnum::DELETE_USERS, PermissionEnum::SUSPEND_USERS,
        PermissionEnum::MANAGE_GENERAL_SETTINGS,
    ];
}

/** Il modulo di «Nuovo utente» come lo manda la pagina. */
function cpeNuovoUtente(string $email, int $ruolo, array $permessi = []): array
{
    return ['name' => 'Ombra', 'email' => $email, 'roles' => $ruolo, 'permissions' => $permessi, 'anagrafica' => null];
}

/** Il corpo di `ModificaUtente.vue` aperta e inviata, costruito dai dati che la pagina riceve. */
function cpeScheda($test, User $chi, User $di, array $modifiche = []): array
{
    $props = $test->actingAs($chi)->get(route('utenti.edit', ['utenti' => $di->id]))->assertOk()->viewData('page')['props'];
    $dalRuolo = collect($props['user']['roles'][0]['permissions'] ?? [])->pluck('id')->all();

    return array_merge([
        'name'        => $props['user']['name'],
        'email'       => $props['user']['email'],
        'roles'       => $props['user']['roles'][0]['id'] ?? null,
        'permissions' => collect($props['user']['permissions'])->pluck('id')->reject(fn ($id) => in_array($id, $dalRuolo, true))->values()->all(),
        'anagrafica'  => $props['user']['anagrafica']['id'] ?? null,
    ], $modifiche);
}

/** Il modulo delle impostazioni generali come lo manda la pagina, dai dati che riceve. */
function cpeImpostazioni($test, User $chi, array $modifiche = []): array
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

function cpeRegistrazione(bool $accesa, string $ruolo): void
{
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->user_frontend_registration = $accesa;
    $impostazioni->default_user_role = $ruolo;
    $impostazioni->save();
}

// ─── I permessi aggiuntivi ────────────────────────────────────────────────────────────────────────────

it('Coda 261 — creare un utente con permessi aggiuntivi che danno poteri chiede la password, e dopo la conferma si torna al modulo del nuovo utente', function () {
    $modulo = cpeNuovoUtente('ombra@example.com', cpeIdRuolo(RoleEnum::UTENTE->value), cpeIdPermessi(...cpePermessiDiGoverno()));

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), $modulo)
        ->assertRedirect(route('password.confirm'));
    expect(User::where('email', 'ombra@example.com')->exists())->toBeFalse();

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('utenti.create'));

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), $modulo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect(User::where('email', 'ombra@example.com')->first()?->hasDirectPermission(PermissionEnum::MANAGE_GENERAL_SETTINGS->value))->toBeTrue();
});

it('Coda 261 — dare dalla scheda a un condòmino un permesso aggiuntivo che non ha chiede la password, e dopo la conferma si torna alla sua scheda', function () {
    $scheda = route('utenti.edit', ['utenti' => $this->condomino->id]);
    $corpo = cpeScheda($this, $this->amministratore, $this->condomino, ['permissions' => cpeIdPermessi(...cpePermessiDiGoverno())]);

    $this->actingAs($this->amministratore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), $corpo)
        ->assertRedirect(route('password.confirm'));
    expect($this->condomino->fresh()->getDirectPermissions())->toHaveCount(0);

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect($scheda);

    $this->actingAs($this->amministratore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::ACCESS_ADMIN_PANEL->value))->toBeTrue();
});

it('Coda 261 — anche il collaboratore ridigita la password per dare a un condòmino un permesso aggiuntivo che ha anche lui', function () {
    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->collaboratore, $this->condomino, [
            'permissions' => cpeIdPermessi(PermissionEnum::VIEW_USERS),
        ]))
        ->assertRedirect(route('password.confirm'));

    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeFalse();
});

it('Coda 261 — creare un utente con un elemento che porta l\'id di un permesso sui propri contenuti e il nome di un permesso che dà poteri chiede la password', function () {
    // `givePermissionTo()` appiattisce l'elenco e concede tutti e due i valori dell'elemento: la conferma li guarda
    // tutti e due, come la regola dei permessi concedibili.
    $modulo = cpeNuovoUtente('ombra@example.com', cpeIdRuolo(RoleEnum::UTENTE->value), [
        ['id' => cpeIdPermessi(PermissionEnum::EDIT_OWN_SEGNALAZIONI)[0], 'name' => PermissionEnum::EDIT_USERS->value],
    ]);

    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), $modulo)
        ->assertRedirect(route('password.confirm'));

    expect(User::where('email', 'ombra@example.com')->exists())->toBeFalse();
});

it('Coda 261 — un permesso aggiuntivo sui propri contenuti, in creazione o dalla scheda, non chiede la password', function () {
    $this->actingAs($this->amministratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), cpeNuovoUtente('nuovo@example.com', cpeIdRuolo(RoleEnum::UTENTE->value), cpeIdPermessi(PermissionEnum::EDIT_OWN_SEGNALAZIONI)))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect(User::where('email', 'nuovo@example.com')->first()?->hasDirectPermission(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeTrue();

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->amministratore, $this->condomino, [
            'permissions' => cpeIdPermessi(PermissionEnum::DELETE_OWN_SEGNALAZIONI),
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::DELETE_OWN_SEGNALAZIONI->value))->toBeTrue();
});

it('Coda 261 — la scheda aperta e inviata con i permessi aggiuntivi che ha, o con uno tolto, non chiede la password', function () {
    $this->condomino->givePermissionTo([PermissionEnum::VIEW_USERS->value, PermissionEnum::ACCESS_ADMIN_PANEL->value]);

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->amministratore, $this->condomino))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->amministratore, $this->condomino, [
            'permissions' => cpeIdPermessi(PermissionEnum::VIEW_USERS),
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    expect($this->condomino->fresh()->getDirectPermissions()->pluck('name')->all())->toBe([PermissionEnum::VIEW_USERS->value]);
});

// ─── L'email di chi non ha soltanto i ruoli utente e fornitore ────────────────────────────────────────

it('Coda 261 — cambiare dalla scheda l\'email di un account che non ha soltanto i ruoli utente e fornitore chiede la password', function (string $quale) {
    $consigliere = Role::create(['name' => 'Consigliere', 'guard_name' => 'web']);
    $consigliere->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value);

    $bersaglio = match ($quale) {
        'amministratore' => cpeUtente(RoleEnum::AMMINISTRATORE->value),
        'collaboratore'  => $this->collaboratore,
        'ruolo su misura' => cpeUtente($consigliere),
    };
    $email = $bersaglio->email;
    $scheda = route('utenti.edit', ['utenti' => $bersaglio->id]);
    $corpo = cpeScheda($this, $this->amministratore, $bersaglio, ['email' => 'casella.nuova@example.com']);

    $this->actingAs($this->amministratore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $bersaglio->id]), $corpo)
        ->assertRedirect(route('password.confirm'));
    expect($bersaglio->fresh()->email)->toBe($email);

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect($scheda);

    $this->actingAs($this->amministratore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $bersaglio->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($bersaglio->fresh()->email)->toBe('casella.nuova@example.com');
})->with(['amministratore', 'collaboratore', 'ruolo su misura']);

it('Coda 261 — cambiare dalla scheda l\'email di un condòmino o di un fornitore con un permesso aggiuntivo che non è sui propri contenuti chiede la password', function (string $chi, string $ruolo, array $permessi) {
    $attore = $chi === 'amministratore' ? $this->amministratore : $this->collaboratore;
    $bersaglio = cpeUtente($ruolo);
    $bersaglio->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $email = $bersaglio->email;
    $scheda = route('utenti.edit', ['utenti' => $bersaglio->id]);
    $corpo = cpeScheda($this, $attore, $bersaglio, ['email' => 'casella.nuova@example.com']);

    $this->actingAs($attore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $bersaglio->id]), $corpo)
        ->assertRedirect(route('password.confirm'));
    expect($bersaglio->fresh()->email)->toBe($email);

    $this->actingAs($attore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect($scheda);

    $this->actingAs($attore)->from($scheda)
        ->put(route('utenti.update', ['utenti' => $bersaglio->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($bersaglio->fresh()->email)->toBe('casella.nuova@example.com')
        ->and($bersaglio->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect($permessi)->map(fn (PermissionEnum $p) => $p->value)->sort()->values()->all());
})->with([
    'l\'amministratore, un condòmino con i permessi di governo' => ['amministratore', RoleEnum::UTENTE->value, [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::MANAGE_GENERAL_SETTINGS]],
    'l\'amministratore, un fornitore con un permesso e uno sui propri contenuti' => ['amministratore', RoleEnum::FORNITORE->value, [PermissionEnum::VIEW_USERS, PermissionEnum::EDIT_OWN_SEGNALAZIONI]],
    // Nella portata del collaboratore: il permesso lo ha anche lui.
    'il collaboratore, un condòmino con un permesso che ha anche lui' => ['collaboratore', RoleEnum::UTENTE->value, [PermissionEnum::VIEW_USERS]],
]);

it('Coda 261 — cambiare l\'email di un condòmino con i soli permessi aggiuntivi sui propri contenuti non chiede la password', function () {
    $this->condomino->givePermissionTo([PermissionEnum::EDIT_OWN_SEGNALAZIONI->value, PermissionEnum::DELETE_OWN_SEGNALAZIONI->value]);

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->amministratore, $this->condomino, [
            'email' => 'corretta@example.com',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    expect($this->condomino->fresh()->email)->toBe('corretta@example.com');
});

it('Coda 261 — cambiare l\'email di un condòmino, di un fornitore, di chi ha tutti e due i ruoli o di un account senza ruoli non chiede la password', function () {
    $utente = cpeIdRuolo(RoleEnum::UTENTE->value);
    $bersagli = [
        [$this->condomino, []],
        [cpeUtente(RoleEnum::FORNITORE->value), []],
        [cpeUtente(RoleEnum::UTENTE->value, RoleEnum::FORNITORE->value), []],
        // Senza ruoli la scheda chiede di sceglierne uno.
        [cpeUtente(), ['roles' => $utente]],
    ];

    foreach ($bersagli as $i => [$bersaglio, $scelte]) {
        $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->put(route('utenti.update', ['utenti' => $bersaglio->id]), cpeScheda($this, $this->amministratore, $bersaglio, [
                'email' => "corretta{$i}@example.com", ...$scelte,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('utenti.index'));

        expect($bersaglio->fresh()->email)->toBe("corretta{$i}@example.com");
    }
});

it('Coda 261 — chi chiede JSON per un permesso aggiuntivo o per l\'email di un altro amministratore riceve 423, e niente cambia', function () {
    $secondo = cpeUtente(RoleEnum::AMMINISTRATORE->value);
    $email = $secondo->email;

    $this->actingAs($this->amministratore)
        ->putJson(route('utenti.update', ['utenti' => $secondo->id]), cpeScheda($this, $this->amministratore, $secondo, ['email' => 'casella.nuova@example.com']))
        ->assertStatus(423);

    $this->actingAs($this->amministratore)
        ->putJson(route('utenti.update', ['utenti' => $this->condomino->id]), cpeScheda($this, $this->amministratore, $this->condomino, [
            'permissions' => cpeIdPermessi(PermissionEnum::ACCESS_ADMIN_PANEL),
        ]))
        ->assertStatus(423);

    expect($secondo->fresh()->email)->toBe($email)
        ->and($this->condomino->fresh()->getDirectPermissions())->toHaveCount(0);
});

// ─── La registrazione pubblica ────────────────────────────────────────────────────────────────────────

it('Coda 261 — accendere la registrazione pubblica quando il ruolo dei nuovi iscritti dà poteri chiede la password, e dopo la conferma si torna alle impostazioni', function (string $ruolo) {
    Role::create(['name' => 'Consigliere', 'guard_name' => 'web'])->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value);
    cpeRegistrazione(false, $ruolo);
    $modulo = cpeImpostazioni($this, $this->amministratore, ['user_frontend_registration' => true]);

    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), $modulo)
        ->assertRedirect(route('password.confirm'));
    expect(app(GeneralSettings::class)->refresh()->user_frontend_registration)->toBeFalse();

    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('impostazioni.generali'));

    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), $modulo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));
    expect(app(GeneralSettings::class)->refresh()->user_frontend_registration)->toBeTrue();
})->with([
    'collaboratore'   => [RoleEnum::COLLABORATORE->value],
    'amministratore'  => [RoleEnum::AMMINISTRATORE->value],
    'ruolo su misura' => ['Consigliere'],
]);

it('Coda 261 — accendere la registrazione con il ruolo dei condòmini o dei fornitori, spegnerla, o salvare con la registrazione già accesa non chiede la password', function () {
    foreach ([RoleEnum::UTENTE->value, RoleEnum::FORNITORE->value] as $ruolo) {
        cpeRegistrazione(false, $ruolo);

        $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
            ->post(route('impostazioni.generali.store'), cpeImpostazioni($this, $this->amministratore, ['user_frontend_registration' => true]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('impostazioni.generali'));
        expect(app(GeneralSettings::class)->refresh()->user_frontend_registration)->toBeTrue();
    }

    // Già accesa con un ruolo che dà poteri (salvato prima di questa versione): salvare il resto non chiede niente.
    cpeRegistrazione(true, RoleEnum::COLLABORATORE->value);
    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), cpeImpostazioni($this, $this->amministratore, ['app_name' => 'Condominio «Aurora»']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));
    expect(app(GeneralSettings::class)->refresh()->app_name)->toBe('Condominio «Aurora»');

    // Spegnerla toglie una porta, non ne apre una.
    $this->actingAs($this->amministratore)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), cpeImpostazioni($this, $this->amministratore, ['user_frontend_registration' => false]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('impostazioni.generali'));
    expect(app(GeneralSettings::class)->refresh()->user_frontend_registration)->toBeFalse();
});

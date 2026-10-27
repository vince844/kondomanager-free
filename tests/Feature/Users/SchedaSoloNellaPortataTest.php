<?php

/**
 * Coda 258 — la scheda di un account fuori dalla portata è solo dell'amministratore (decisione 136.1).
 *
 * ## Perché questo file esiste
 *
 * Con la regola della portata (decisione 134.1) chi non è amministratore non cambia email, ruoli e permessi
 * aggiuntivi degli account fuori dalla sua portata; la decisione 136.1 gli toglie anche la scheda intera, nome e
 * anagrafica compresi: per chi non è amministratore la pagina e il salvataggio di quella scheda rispondono 403,
 * con la frase della portata, e nell'elenco «Modifica» non compare. La riga del collaboratore stesso è fuori dalla
 * sua portata: i propri dati si correggono dalle impostazioni dell'account (nome ed email in «Profilo», la
 * password in «Password»). L'amministratore apre e salva ogni scheda, e per lui la regola sui permessi aggiuntivi
 * non scatta.
 *
 * Nello stesso posto la revoca di un permesso diretto dal collegamento della scheda: la portata non conta i
 * permessi sui propri contenuti, quindi un account nella portata li perde anche da chi non li ha; una revoca
 * rifiutata parla di revoca, non di concessione.
 *
 * ## Cosa questo file NON copre
 *
 * Le altre porte della portata (`ChiPuoToccareChiTest.php`, `PorteLateraliTest.php`); il menù della riga e la
 * pagina della scheda (`resources/js/components/users/DataTableRowActions.test.ts`,
 * `resources/js/pages/utenti/ModificaUtente.test.ts`).
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = sspUtente(RoleEnum::AMMINISTRATORE->value);
    $this->collaboratore = sspUtente(RoleEnum::COLLABORATORE->value);
    $this->condomino = sspUtente(RoleEnum::UTENTE->value);
});

/** Un utente verificato con i ruoli dati (nomi o modelli). */
function sspUtente(string|Role ...$ruoli): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);

    if ($ruoli !== []) {
        $utente->assignRole($ruoli);
    }

    return $utente;
}

/**
 * «Consigliere», un ruolo su misura, con un permesso diretto che dopo l'amministratore ha aggiunto anche al
 * ruolo e un'anagrafica collegata: i dati scomodi della scheda «aperta e inviata».
 */
function sspConsigliere(): User
{
    $ruolo = Role::create(['name' => 'Consigliere', 'guard_name' => 'web', 'description' => 'Consigliere']);
    $ruolo->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value);

    $consigliere = sspUtente($ruolo);
    $consigliere->givePermissionTo(PermissionEnum::VIEW_USERS->value);
    $ruolo->givePermissionTo(PermissionEnum::VIEW_USERS->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Anagrafica::factory()->create(['user_id' => $consigliere->id]);

    return $consigliere->fresh();
}

/** Il corpo di `ModificaUtente.vue` aperta e inviata, costruito dai dati che la pagina riceve. */
function sspCorpoDallaPagina($test, User $chi, User $di, array $modifiche = []): array
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

/**
 * Lo stesso corpo costruito dal modello, per chi la pagina non la può aprire: è ciò che manderebbe una pagina
 * rimasta aperta da prima, o una richiesta scritta a mano.
 */
function sspCorpoDalModello(User $utente, array $modifiche = []): array
{
    $utente->load(['roles.permissions', 'permissions', 'anagrafica']);
    $primoRuolo = $utente->roles->first();
    $dalRuolo = $primoRuolo?->permissions->pluck('id')->all() ?? [];

    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $primoRuolo?->id,
        'permissions' => $utente->permissions->pluck('id')->reject(fn ($id) => in_array($id, $dalRuolo, true))->values()->all(),
        'anagrafica'  => $utente->anagrafica?->id,
    ], $modifiche);
}

function sspIdPermesso(PermissionEnum $permesso): int
{
    return Permission::where('name', $permesso->value)->firstOrFail()->id;
}

/** Gli account fuori dalla portata del collaboratore, uno per ogni ragione. */
function sspFuoriPortata(): array
{
    $collega = sspUtente(RoleEnum::COLLABORATORE->value);
    $amministratore = sspUtente(RoleEnum::AMMINISTRATORE->value);
    $condominoConPoteri = sspUtente(RoleEnum::UTENTE->value);
    $condominoConPoteri->givePermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value);

    return [
        'un amministratore'                          => $amministratore,
        'un altro collaboratore'                     => $collega,
        'un ruolo su misura'                         => sspConsigliere(),
        'un condòmino con un permesso che lui non ha' => $condominoConPoteri,
    ];
}

// ─── Fuori dalla portata: la scheda è solo dell'amministratore ────────────────────────────────────────

it('Coda 258 — chi non è amministratore non apre la scheda di un account fuori dalla sua portata, e il rifiuto dice perché', function () {
    foreach (sspFuoriPortata() as $quale => $bersaglio) {
        $this->actingAs($this->collaboratore)
            ->get(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->assertForbidden();

        $this->actingAs($this->collaboratore)
            ->getJson(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->assertForbidden()
            ->assertJsonPath('message', __('policies.users_privileged_target'));
    }
});

it('Coda 258 — chi non è amministratore non salva la scheda di un account fuori dalla sua portata, nemmeno per correggerne il nome, e niente cambia', function () {
    foreach (sspFuoriPortata() as $quale => $bersaglio) {
        $prima = $bersaglio->fresh();
        $diretti = $prima->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $anagrafica = Anagrafica::where('user_id', $bersaglio->id)->value('id');

        // La scheda com'era, con il solo nome corretto.
        $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->put(route('utenti.update', ['utenti' => $bersaglio->id]), sspCorpoDalModello($bersaglio, ['name' => 'Nome corretto']))
            ->assertForbidden();

        // Chi chiede JSON legge perché.
        $this->actingAs($this->collaboratore)
            ->putJson(route('utenti.update', ['utenti' => $bersaglio->id]), sspCorpoDalModello($bersaglio))
            ->assertForbidden()
            ->assertJsonPath('message', __('policies.users_privileged_target'));

        $dopo = $bersaglio->fresh();
        expect($dopo->name)->toBe($prima->name, $quale)
            ->and($dopo->email)->toBe($prima->email)
            ->and($dopo->getRoleNames()->all())->toBe($prima->getRoleNames()->all())
            ->and($dopo->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe($diretti)
            ->and(Anagrafica::where('user_id', $bersaglio->id)->value('id'))->toBe($anagrafica);
    }
});

it('Coda 258 — il rifiuto arriva prima degli errori sui campi: una scheda fuori portata con l\'email cambiata riceve 403, non un errore sull\'email', function () {
    $collega = sspUtente(RoleEnum::COLLABORATORE->value);

    $this->actingAs($this->collaboratore)
        ->putJson(route('utenti.update', ['utenti' => $collega->id]), sspCorpoDalModello($collega, ['email' => 'casella.altrui@example.com']))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    expect($collega->fresh()->email)->not->toBe('casella.altrui@example.com');
});

it('Coda 258 — la scheda del collaboratore stesso è fuori dalla sua portata: non la apre e non la salva, e il nome lo corregge dal profilo', function () {
    $nome = $this->collaboratore->name;

    $this->actingAs($this->collaboratore)
        ->get(route('utenti.edit', ['utenti' => $this->collaboratore->id]))
        ->assertForbidden();

    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->collaboratore->id]))
        ->put(route('utenti.update', ['utenti' => $this->collaboratore->id]), sspCorpoDalModello($this->collaboratore, ['name' => 'Nome mio']))
        ->assertForbidden();
    expect($this->collaboratore->fresh()->name)->toBe($nome);

    // Il profilo resta la sua strada.
    $this->actingAs($this->collaboratore)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Nome mio', 'email' => $this->collaboratore->email])
        ->assertSessionHasNoErrors();
    expect($this->collaboratore->fresh()->name)->toBe('Nome mio');
});

it('Coda 258 — sulla propria scheda fuori portata il rifiuto dice dove si cambiano i propri dati', function (string $quale) {
    $chi = $quale === 'il collaboratore'
        ? $this->collaboratore
        : sspUtente(Role::create(['name' => 'Segreteria', 'guard_name' => 'web'])->givePermissionTo([
            PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::VIEW_USERS->value, PermissionEnum::EDIT_USERS->value,
        ]));

    $this->actingAs($chi)
        ->getJson(route('utenti.edit', ['utenti' => $chi->id]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_own_from_profile'));

    $this->actingAs($chi)
        ->putJson(route('utenti.update', ['utenti' => $chi->id]), sspCorpoDalModello($chi, ['name' => 'Nome mio']))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_own_from_profile'));

    expect($chi->fresh()->name)->not->toBe('Nome mio');
})->with(['il collaboratore', 'un ruolo su misura con «Modifica utenti»']);

it('Coda 258 — la frase della propria scheda manda nome ed email a «Profilo» e la password alla sua voce, come le chiama il menù delle impostazioni, in ogni lingua', function (string $lingua, string $parolaPassword) {
    // Il profilo prende nome ed email (`ProfileController::update`), la password ha la sua pagina
    // (`PasswordController::update`): la frase le nomina come le voci del menù delle impostazioni
    // (`settings.layout.nav`), nella lingua scelta nelle impostazioni generali.
    $impostazioni = app(\App\Settings\GeneralSettings::class);
    $impostazioni->language = $lingua;
    $impostazioni->save();

    $frase = $this->actingAs($this->collaboratore)
        ->getJson(route('utenti.edit', ['utenti' => $this->collaboratore->id]))
        ->assertForbidden()
        ->json('message');

    $profilo = '«' . __('settings.layout.nav.profile', [], $lingua) . '»';
    $vocePassword = '«' . __('settings.layout.nav.password', [], $lingua) . '»';
    expect($frase)->toBe(__('policies.users_own_from_profile', [], $lingua))
        ->toContain($profilo)
        ->toContain($vocePassword);

    // La password non va con il profilo: la frase la nomina dopo «Profilo», e la manda alla sua voce.
    [$primaDelProfilo, $dopoIlProfilo] = explode($profilo, $frase, 2);
    $parolaDopo = mb_stripos($dopoIlProfilo, $parolaPassword);
    expect(mb_stripos($primaDelProfilo, $parolaPassword))->toBeFalse()
        ->and($parolaDopo)->toBeInt()
        ->and($parolaDopo)->toBeLessThan(mb_strpos($dopoIlProfilo, $vocePassword));

    // Le due pagine fanno quello che la frase dice: il profilo non cambia la password, la sua pagina sì.
    $prima = $this->collaboratore->fresh()->password;
    $this->actingAs($this->collaboratore)->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Nome mio', 'email' => $this->collaboratore->email,
            'current_password' => 'password', 'password' => 'Una-password-nuova-1', 'password_confirmation' => 'Una-password-nuova-1',
        ])
        ->assertSessionHasNoErrors();
    expect($this->collaboratore->fresh()->name)->toBe('Nome mio')
        ->and($this->collaboratore->fresh()->password)->toBe($prima);

    $this->actingAs($this->collaboratore)->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'Una-password-nuova-1', 'password_confirmation' => 'Una-password-nuova-1',
        ])
        ->assertSessionHasNoErrors();
    expect(\Illuminate\Support\Facades\Hash::check('Una-password-nuova-1', $this->collaboratore->fresh()->password))->toBeTrue();
})->with([
    'italiano'    => ['it', 'password'],
    'inglese'     => ['en', 'password'],
    'portoghese'  => ['pt', 'palavra-passe'],
    'spagnolo'    => ['es', 'contraseña'],
]);

// ─── Le controprove: nella portata, e l'amministratore ────────────────────────────────────────────────

it('Coda 258 — nella portata chi non è amministratore apre e salva la scheda di un condòmino, di un fornitore e di un account senza ruoli', function () {
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail()->id;

    // Senza ruoli la scheda chiede di sceglierne uno (il campo è obbligatorio): il collaboratore sceglie «utente».
    $bersagli = [
        [$this->condomino, []],
        [sspUtente(RoleEnum::FORNITORE->value), []],
        [sspUtente(), ['roles' => $utente]],
    ];

    foreach ($bersagli as [$bersaglio, $scelte]) {
        $props = $this->actingAs($this->collaboratore)->get(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->assertOk()->viewData('page')['props'];
        expect($props['gestibile'])->toBeTrue();

        $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->put(route('utenti.update', ['utenti' => $bersaglio->id]), sspCorpoDalModello($bersaglio, ['name' => 'Nome di D\'Àngelo «corretto»', ...$scelte]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('utenti.index'));

        expect($bersaglio->fresh()->name)->toBe('Nome di D\'Àngelo «corretto»');
    }
});

it('Coda 258 — l\'amministratore apre e salva la scheda di un ruolo su misura con un permesso diretto che il ruolo dà già: aperta e inviata non dà errori, e il nome si corregge', function () {
    $consigliere = sspConsigliere();
    $anagrafica = Anagrafica::where('user_id', $consigliere->id)->value('id');

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $consigliere->id]))
        ->put(route('utenti.update', ['utenti' => $consigliere->id]), sspCorpoDallaPagina($this, $this->amministratore, $consigliere))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $consigliere->id]))
        ->put(route('utenti.update', ['utenti' => $consigliere->id]), sspCorpoDallaPagina($this, $this->amministratore, $consigliere, ['name' => 'Nome corretto']))
        ->assertSessionHasNoErrors();

    $dopo = $consigliere->fresh();
    expect($dopo->name)->toBe('Nome corretto')
        ->and($dopo->getRoleNames()->all())->toBe(['Consigliere'])
        ->and(Anagrafica::where('user_id', $consigliere->id)->value('id'))->toBe($anagrafica);
});

it('Coda 258 — l\'amministratore corregge il nome di un collaboratore con un permesso diretto che il ruolo dà già', function () {
    $this->collaboratore->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $this->collaboratore->id]))
        ->put(route('utenti.update', ['utenti' => $this->collaboratore->id]), sspCorpoDallaPagina($this, $this->amministratore, $this->collaboratore, ['name' => 'Nome del collaboratore']))
        ->assertSessionHasNoErrors();

    expect($this->collaboratore->fresh()->name)->toBe('Nome del collaboratore')
        ->and($this->collaboratore->fresh()->hasRole(RoleEnum::COLLABORATORE->value))->toBeTrue();
});

// ─── La revoca di un permesso dal collegamento della scheda ───────────────────────────────────────────

it('Coda 258 — nella portata un permesso diretto sui propri contenuti si toglie anche senza averlo: la scheda lo offre e la rotta lo toglie', function () {
    $this->condomino->givePermissionTo(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value);
    expect($this->collaboratore->hasPermissionTo(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeFalse();

    $props = $this->actingAs($this->collaboratore)->get(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->assertOk()->viewData('page')['props'];
    expect($props['gestibile'])->toBeTrue()
        ->and(collect($props['user']['permissions'])->pluck('name')->all())->toContain(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value);

    // Come lo manda il collegamento della scheda: una richiesta Inertia che torna alla scheda.
    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->delete(route('users.permissions.destroy', [
            'user'       => $this->condomino->id,
            'permission' => sspIdPermesso(PermissionEnum::EDIT_OWN_SEGNALAZIONI),
        ]))
        ->assertRedirect(route('utenti.edit', ['utenti' => $this->condomino->id]));

    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeFalse();
});

it('Coda 258 — la portata viene prima anche per un permesso sui propri contenuti: la revoca a un account fuori portata è rifiutata con la frase della portata, e il permesso resta', function () {
    $collega = sspUtente(RoleEnum::COLLABORATORE->value);
    $collega->givePermissionTo(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value);

    $this->actingAs($this->collaboratore)
        ->deleteJson(route('users.permissions.destroy', [
            'user'       => $collega->id,
            'permission' => sspIdPermesso(PermissionEnum::EDIT_OWN_SEGNALAZIONI),
        ]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    expect($collega->fresh()->hasDirectPermission(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeTrue();
});

it('Coda 258 — la revoca di un permesso che chi agisce non ha, fuori dai propri contenuti, è rifiutata con una frase sulla revoca', function () {
    expect($this->collaboratore->hasPermissionTo(PermissionEnum::MANAGE_GENERAL_SETTINGS->value))->toBeFalse();

    $this->actingAs($this->collaboratore)
        ->deleteJson(route('users.permissions.destroy', [
            'user'       => $this->condomino->id,
            'permission' => sspIdPermesso(PermissionEnum::MANAGE_GENERAL_SETTINGS),
        ]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_revoke_not_owned'));
});

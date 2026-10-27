<?php

/**
 * Chi può toccare chi: la regola in un posto solo (Coda 258, decisione 134.1).
 *
 * ## Perché questo file esiste
 *
 * Le porte che toccano un altro account — la scheda (email, ruoli, permessi diretti), «reinvia», la
 * verifica dell'email, la revoca di un permesso, sospendere, riattivare, eliminare — e quelle che
 * decidono quali poteri esistono — le pagine dei ruoli, il ruolo della registrazione pubblica — guardano
 * la stessa condizione, scritta in un posto solo e provata qui porta per porta. La regola della
 * decisione 134.1:
 *
 * - l'amministratore agisce su tutti, con l'invariante «mai senza amministratori»;
 * - chi non è amministratore (il collaboratore, o un ruolo su misura con i permessi degli utenti) agisce
 *   solo sugli account nella sua portata, concede solo i ruoli `utente` e `fornitore`, non entra nelle
 *   pagine dei ruoli e non cambia il ruolo della registrazione pubblica;
 * - nella portata è un account con i soli ruoli `utente` e `fornitore` (o nessuno), senza permessi
 *   diretti che chi agisce non ha (quelli sui propri contenuti non contano). I permessi che vengono dai
 *   ruoli del bersaglio non contano più: un ruolo su misura con i soli permessi degli utenti gestisce i
 *   condòmini anche senza avere i loro permessi del portale.
 *
 * Dalla decisione 136.1 la scheda di un account fuori portata è tutta dell'amministratore: per chi non lo è la
 * pagina e il salvataggio rispondono 403, prima degli errori sui campi (`SchedaSoloNellaPortataTest.php`).
 *
 * Ogni richiesta è mandata come la manda la sua pagina: la scheda intera (`ModificaUtente.vue`), i comandi
 * dell'elenco (`DataTableRowActions.vue`), la revoca dal collegamento della scheda, le pagine dei ruoli,
 * il modulo delle impostazioni generali.
 *
 * ## Cosa questo file NON copre
 *
 * La conferma della password sulle azioni che danno poteri (`ConfermaPasswordPerIPoteriTest.php`);
 * l'ultimo amministratore sulla scheda e sul profilo (`UltimoAmministratoreTest.php`); le forme della
 * richiesta (ruoli come elenco, per nome) già in `PrivilegiUtentiTest.php` e `PorteLateraliTest.php`.
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

    $this->amministratore = portataUtente(RoleEnum::AMMINISTRATORE->value);
    $this->collaboratore = portataUtente(RoleEnum::COLLABORATORE->value);
    $this->condomino = portataUtente(RoleEnum::UTENTE->value);
    $this->fornitore = portataUtente(RoleEnum::FORNITORE->value);
});

/** Un utente verificato con i ruoli dati (nomi o modelli). */
function portataUtente(string|Role ...$ruoli): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);

    if ($ruoli !== []) {
        $utente->assignRole($ruoli);
    }

    return $utente;
}

/** Un ruolo su misura, come lo crea un amministratore dalla pagina dei ruoli. */
function portataRuolo(string $nome, PermissionEnum ...$permessi): Role
{
    $ruolo = Role::create(['name' => $nome, 'guard_name' => 'web', 'description' => $nome]);
    $ruolo->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));

    return $ruolo;
}

/**
 * Un ruolo su misura di sola consultazione, con un permesso che ha anche il collaboratore: è fuori dalla sua
 * portata anche se il collaboratore ha i suoi permessi, perché il ruolo non è `utente` né `fornitore`.
 */
function portataConsigliere(): User
{
    return portataUtente(portataRuolo('Consigliere', PermissionEnum::VIEW_COMUNICAZIONI));
}

/** Un ruolo su misura di governo, con un permesso diretto in più che ha anche il collaboratore. */
function portataContabile(): User
{
    $contabile = portataUtente(portataRuolo('Contabile', PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::ACCESS_ADMIN_PANEL));
    $contabile->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    return $contabile;
}

/** La «Segreteria»: i soli permessi per gestire gli utenti, senza nessun permesso del portale. */
function portataSegreteria(): User
{
    return portataUtente(portataRuolo(
        'Segreteria',
        PermissionEnum::ACCESS_ADMIN_PANEL,
        PermissionEnum::VIEW_USERS,
        PermissionEnum::EDIT_USERS,
        PermissionEnum::SUSPEND_USERS,
        PermissionEnum::DELETE_USERS,
    ));
}

/**
 * La scheda come la manda `ModificaUtente.vue` aperta e inviata: il primo ruolo come id, i permessi diretti
 * che non vengono dal primo ruolo come id, l'anagrafica come id.
 */
function portataScheda(User $utente, array $modifiche = []): array
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

function portataIdRuolo(string $nome): int
{
    return Role::where('name', $nome)->firstOrFail()->id;
}

function portataIdPermesso(PermissionEnum $permesso): int
{
    return Permission::where('name', $permesso->value)->firstOrFail()->id;
}

// ─── Sospendere, riattivare, eliminare ────────────────────────────────────────────────────────────────

it('Coda 258 — un ruolo su misura con i soli permessi degli utenti sospende, riattiva ed elimina condòmini e fornitori', function () {
    $segreteria = portataSegreteria();

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->put(route('utenti.suspend', ['user' => $this->condomino->id]))
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->suspended())->toBeTrue();

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->put(route('utenti.unsuspend', ['user' => $this->condomino->id]))
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->suspended())->toBeFalse();

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->delete(route('utenti.destroy', ['utenti' => $this->fornitore->id]))
        ->assertRedirect(route('utenti.index'));
    expect(User::find($this->fornitore->id))->toBeNull();
});

it('Coda 258 — chi non è amministratore non sospende né elimina chi ha un ruolo su misura, anche con permessi che ha anche lui', function () {
    $segreteria = portataUtente(portataRuolo('Segreteria', ...[
        ...RoleEnum::COLLABORATORE->permissions(), PermissionEnum::SUSPEND_USERS, PermissionEnum::DELETE_USERS,
    ]));
    $consigliere = portataConsigliere();

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->putJson(route('utenti.suspend', ['user' => $consigliere->id]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));
    expect($consigliere->fresh()->suspended())->toBeFalse();

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->deleteJson(route('utenti.destroy', ['utenti' => $consigliere->id]))
        ->assertForbidden();
    expect(User::find($consigliere->id))->not->toBeNull();
});

it('Coda 258 — chi non è amministratore non riattiva un account fuori dalla sua portata, e il rifiuto dice perché', function () {
    $segreteria = portataUtente(portataRuolo('Segreteria', ...[
        ...RoleEnum::COLLABORATORE->permissions(), PermissionEnum::SUSPEND_USERS,
    ]));
    $sospeso = User::factory()->create(['email_verified_at' => now(), 'suspended_at' => now()]);
    $sospeso->assignRole(RoleEnum::COLLABORATORE->value);

    $this->actingAs($segreteria)->from(route('utenti.index'))
        ->putJson(route('utenti.unsuspend', ['user' => $sospeso->id]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    expect($sospeso->fresh()->suspended())->toBeTrue();
});

it('Coda 258 — l\'amministratore riattiva chi ha un ruolo privilegiato o su misura', function () {
    $sospesi = [portataUtente(RoleEnum::COLLABORATORE->value), portataConsigliere()];

    foreach ($sospesi as $sospeso) {
        $sospeso->update(['suspended_at' => now()]);

        $this->actingAs($this->amministratore)->from(route('utenti.index'))
            ->put(route('utenti.unsuspend', ['user' => $sospeso->id]))
            ->assertRedirect(route('utenti.index'));

        expect($sospeso->fresh()->suspended())->toBeFalse();
    }
});

// ─── «Reinvia» e la verifica dell'email ───────────────────────────────────────────────────────────────

it('Coda 258 — chi non è amministratore non reinvita e non toglie la verifica a chi ha un ruolo su misura, anche con permessi che ha anche lui; il rifiuto dice perché', function () {
    $consigliere = portataConsigliere();
    $passwordPrima = $consigliere->password;

    $this->actingAs($this->collaboratore)
        ->postJson(route('utenti.reinvite', ['email' => $consigliere->email]), ['email' => $consigliere->email])
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    $this->actingAs($this->collaboratore)
        ->putJson(route('utenti.toggle-verification', ['user' => $consigliere->id]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    $dopo = $consigliere->fresh();
    expect($dopo->password)->toBe($passwordPrima)
        ->and($dopo->email_verified_at)->not->toBeNull();
    Notification::assertNothingSentTo($consigliere);
});

// ─── I permessi diretti: la revoca e la scheda ────────────────────────────────────────────────────────

it('Coda 258 — la revoca rifiutata dice perché anche a chi ha «Modifica utenti»', function () {
    $contabile = portataContabile();

    $this->actingAs($this->collaboratore)
        ->deleteJson(route('users.permissions.destroy', [
            'user'       => $contabile->id,
            'permission' => portataIdPermesso(PermissionEnum::VIEW_USERS),
        ]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));

    expect($contabile->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('Coda 258 — dalla scheda chi non è amministratore non toglie un permesso diretto a un account fuori dalla sua portata', function () {
    $contabile = portataContabile();
    $collega = portataUtente(RoleEnum::COLLABORATORE->value);
    $collega->givePermissionTo(PermissionEnum::SUSPEND_USERS->value);
    $collega->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    foreach ([$contabile, $collega] as $bersaglio) {
        $diretti = $bersaglio->getDirectPermissions()->pluck('name')->sort()->values()->all();

        // Il permesso tolto dalla tendina, come lo manderebbe la pagina: dalla decisione 136.1 la scheda fuori
        // portata è solo dell'amministratore, e il rifiuto è il 403 della scheda.
        $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->put(route('utenti.update', ['utenti' => $bersaglio->id]), portataScheda($bersaglio, ['permissions' => []]))
            ->assertForbidden();
        expect($bersaglio->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe($diretti);

        // Senza il campo: vale come l'elenco vuoto.
        $senzaPermessi = portataScheda($bersaglio);
        unset($senzaPermessi['permissions']);
        $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $bersaglio->id]))
            ->put(route('utenti.update', ['utenti' => $bersaglio->id]), $senzaPermessi)
            ->assertForbidden();
        expect($bersaglio->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe($diretti);
    }
});

it('Coda 258 — chi non è amministratore non aggiunge dalla scheda un permesso diretto a un account fuori dalla sua portata', function () {
    $consigliere = portataConsigliere();

    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $consigliere->id]))
        ->put(route('utenti.update', ['utenti' => $consigliere->id]), portataScheda($consigliere, [
            'permissions' => [portataIdPermesso(PermissionEnum::VIEW_USERS)],
        ]))
        ->assertForbidden();

    expect($consigliere->fresh()->getDirectPermissions())->toHaveCount(0);
});

it('Coda 258 — la scheda di un account fuori portata la apre e la salva solo l\'amministratore: aperta e mandata senza toccare niente, non cambia niente', function () {
    // Dati scomodi: un permesso diretto che il collaboratore ha, uno che non ha, un'anagrafica collegata.
    $contabile = portataContabile();
    $contabile->givePermissionTo(PermissionEnum::DELETE_USERS->value);
    $anagrafica = \App\Models\Anagrafica::factory()->create(['user_id' => $contabile->id]);

    // Decisione 136.1: chi non è amministratore non la apre.
    $this->actingAs($this->collaboratore)
        ->get(route('utenti.edit', ['utenti' => $contabile->id]))
        ->assertForbidden();

    $props = $this->actingAs($this->amministratore)
        ->get(route('utenti.edit', ['utenti' => $contabile->id]))
        ->assertOk()
        ->viewData('page')['props'];

    // Il corpo come lo costruisce la pagina dai dati che riceve.
    $dalRuolo = collect($props['user']['roles'][0]['permissions'] ?? [])->pluck('id')->all();
    $corpo = [
        'name'        => $props['user']['name'],
        'email'       => $props['user']['email'],
        'roles'       => $props['user']['roles'][0]['id'],
        'permissions' => collect($props['user']['permissions'])->pluck('id')->reject(fn ($id) => in_array($id, $dalRuolo, true))->values()->all(),
        'anagrafica'  => $props['user']['anagrafica']['id'] ?? null,
    ];
    $diretti = $contabile->getDirectPermissions()->pluck('name')->sort()->values()->all();

    // Lo stesso corpo, mandato da chi non è amministratore (una pagina rimasta aperta da prima): 403.
    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $contabile->id]))
        ->put(route('utenti.update', ['utenti' => $contabile->id]), $corpo)
        ->assertForbidden();

    $this->actingAs($this->amministratore)->from(route('utenti.edit', ['utenti' => $contabile->id]))
        ->put(route('utenti.update', ['utenti' => $contabile->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    $dopo = $contabile->fresh();
    expect($dopo->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe($diretti)
        ->and($dopo->getRoleNames()->all())->toBe(['Contabile'])
        ->and($anagrafica->fresh()->user_id)->toBe($contabile->id);
});

it('Coda 258 — il collaboratore continua a togliere e a dare dalla scheda a un condòmino un permesso diretto che ha anche lui', function () {
    $this->condomino->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), portataScheda($this->condomino, ['permissions' => []]))
        ->assertSessionHasNoErrors();
    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeFalse();

    // Dare un permesso aggiuntivo chiede la password ridigitata da poco (Coda 261): qui è appena confermata.
    $this->actingAs($this->collaboratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), portataScheda($this->condomino, [
            'permissions' => [portataIdPermesso(PermissionEnum::VIEW_USERS)],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('Coda 258 — un permesso diretto sui propri contenuti non porta un condòmino fuori dalla portata', function () {
    // «Modifica proprie segnalazioni» il collaboratore non ce l'ha, ma non dà potere su nessun altro.
    $this->condomino->givePermissionTo(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value);
    expect($this->collaboratore->hasPermissionTo(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeFalse();

    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), portataScheda($this->condomino, [
            'email' => 'casella.corretta@example.com',
        ]))
        ->assertSessionHasNoErrors();

    $dopo = $this->condomino->fresh();
    expect($dopo->email)->toBe('casella.corretta@example.com')
        ->and($dopo->hasDirectPermission(PermissionEnum::EDIT_OWN_SEGNALAZIONI->value))->toBeTrue();
});

// ─── Concedere i ruoli ────────────────────────────────────────────────────────────────────────────────

it('Coda 258 — chi non è amministratore concede solo i ruoli utente e fornitore: un ruolo su misura no, in creazione e in modifica, nemmeno con permessi che ha anche lui', function () {
    portataRuolo('Consigliere', PermissionEnum::VIEW_COMUNICAZIONI);

    $this->actingAs($this->collaboratore)->from(route('utenti.create'))
        ->post(route('utenti.store'), [
            'name' => 'Seconda casella', 'email' => 'seconda.casella@example.com',
            'roles' => portataIdRuolo('Consigliere'), 'permissions' => [], 'anagrafica' => null,
        ])
        ->assertSessionHasErrors('roles');
    expect(User::where('email', 'seconda.casella@example.com')->exists())->toBeFalse();

    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), portataScheda($this->condomino, [
            'roles' => portataIdRuolo('Consigliere'),
        ]))
        ->assertSessionHasErrors('roles');
    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);

    // Controprova: utente e fornitore sì.
    $this->actingAs($this->collaboratore)->from(route('utenti.edit', ['utenti' => $this->condomino->id]))
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), portataScheda($this->condomino, [
            'roles' => portataIdRuolo(RoleEnum::FORNITORE->value),
        ]))
        ->assertSessionHasNoErrors();
    expect($this->condomino->fresh()->getRoleNames()->all())->toBe([RoleEnum::FORNITORE->value]);
});

it('Coda 258 — le pagine di creazione e di modifica offrono a chi non è amministratore solo i ruoli utente e fornitore', function () {
    portataRuolo('Consigliere', PermissionEnum::VIEW_COMUNICAZIONI);

    // La pagina riceve i nomi con l'iniziale maiuscola (`RoleResource`): si confrontano in minuscolo.
    $nomi = fn (User $chi, string $pagina) => collect($this->actingAs($chi)->get($pagina)->assertOk()->viewData('page')['props']['roles'])
        ->map(fn ($ruolo) => mb_strtolower((string) $ruolo['name']))->sort()->values()->all();

    foreach ([route('utenti.create'), route('utenti.edit', ['utenti' => $this->condomino->id])] as $pagina) {
        expect($nomi($this->collaboratore, $pagina))->toBe([RoleEnum::FORNITORE->value, RoleEnum::UTENTE->value])
            ->and($nomi($this->amministratore, $pagina))->toBe([
                RoleEnum::AMMINISTRATORE->value, RoleEnum::COLLABORATORE->value, 'consigliere', RoleEnum::FORNITORE->value, RoleEnum::UTENTE->value,
            ]);
    }
});

// ─── Le pagine dei ruoli ──────────────────────────────────────────────────────────────────────────────

it('Coda 258 — chi non è amministratore non entra nelle pagine dei ruoli, e il rifiuto dice perché', function () {
    $consigliere = portataRuolo('Consigliere', PermissionEnum::VIEW_COMUNICAZIONI);

    foreach ([route('ruoli.index'), route('ruoli.create'), route('ruoli.edit', ['ruoli' => $consigliere->id])] as $pagina) {
        $risposta = $this->actingAs($this->collaboratore)->get($pagina);
        $risposta->assertForbidden();
        expect($risposta->exception?->getMessage())->toBeIn([
            __('policies.view_roles'), __('policies.create_roles'), __('policies.edit_roles'),
        ]);
    }

    $this->actingAs($this->amministratore)->get(route('ruoli.index'))->assertOk();
});

it('Coda 258 — chi non è amministratore non crea, non modifica e non elimina un ruolo su misura, e non gli toglie permessi', function () {
    $contabile = portataContabile();
    $ruolo = Role::where('name', 'Contabile')->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->post(route('ruoli.store'), [
            'name' => 'Ruolo del collaboratore', 'description' => 'x',
            'permissions' => [portataIdPermesso(PermissionEnum::VIEW_USERS)], 'accessAdmin' => true,
        ])
        ->assertForbidden();
    expect(Role::where('name', 'Ruolo del collaboratore')->exists())->toBeFalse();

    // Il rifiuto viene prima degli errori sui campi: anche con il modulo vuoto, la risposta è il perché.
    $this->actingAs($this->collaboratore)->from(route('ruoli.create'))
        ->post(route('ruoli.store'), [])
        ->assertForbidden();
    $this->actingAs($this->collaboratore)->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), [])
        ->assertForbidden();

    $this->actingAs($this->collaboratore)
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), [
            'name' => 'Contabile', 'description' => 'Contabile', 'permissions' => [], 'accessAdmin' => true,
        ])
        ->assertForbidden();

    $this->actingAs($this->collaboratore)
        ->delete(route('ruoli.permissions.destroy', [
            'role' => $ruolo->id, 'permission' => portataIdPermesso(PermissionEnum::ACCESS_ADMIN_PANEL),
        ]))
        ->assertForbidden();

    $this->actingAs($this->collaboratore)
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertForbidden();

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    expect(Role::where('name', 'Contabile')->exists())->toBeTrue()
        ->and($ruolo->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe([PermissionEnum::ACCESS_ADMIN_PANEL->value, PermissionEnum::MANAGE_GENERAL_SETTINGS->value])
        ->and($contabile->fresh()->getRoleNames()->all())->toBe(['Contabile']);
});

it('Coda 258 — eliminare un ruolo su misura toglie solo quel ruolo: chi ne ha un altro lo tiene, chi resta senza ruoli diventa utente', function () {
    $ruolo = portataRuolo('Contabile', PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::ACCESS_ADMIN_PANEL);
    $secondoAmministratore = portataUtente(RoleEnum::AMMINISTRATORE->value, $ruolo);
    $fornitoreContabile = portataUtente(RoleEnum::FORNITORE->value, $ruolo);
    $soloContabile = portataUtente($ruolo);

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'));

    expect(Role::where('name', 'Contabile')->exists())->toBeFalse()
        ->and($secondoAmministratore->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value])
        ->and($fornitoreContabile->fresh()->getRoleNames()->all())->toBe([RoleEnum::FORNITORE->value])
        ->and($soloContabile->fresh()->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);

    // Il messaggio conta chi è stato messo nel ruolo utente: uno solo.
    expect(session('message')['message'] ?? null)->toBe(__('ruoli.success_delete_with_reassign', [
        'count' => 1, 'default_role' => RoleEnum::UTENTE->value,
    ]));
});

it('Coda 258 — eliminare un ruolo su misura non lascia l\'installazione senza amministratori', function () {
    $ruolo = portataRuolo('Contabile', PermissionEnum::MANAGE_GENERAL_SETTINGS, PermissionEnum::ACCESS_ADMIN_PANEL);
    $this->amministratore->assignRole($ruolo);
    expect($this->amministratore->fresh()->isUltimoAmministratoreAttivo())->toBeTrue();

    $this->actingAs($this->amministratore)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]))
        ->assertRedirect(route('ruoli.index'));

    expect($this->amministratore->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value])
        ->and(User::role(RoleEnum::AMMINISTRATORE->value)->count())->toBe(1);
});

// ─── Il ruolo della registrazione pubblica ────────────────────────────────────────────────────────────

/** Il modulo delle impostazioni generali come lo manda la pagina, dai dati che riceve. */
function portataImpostazioni($test, User $chi, array $modifiche = []): array
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

it('Coda 258 — chi non è amministratore non cambia il ruolo della registrazione pubblica', function () {
    $contabile = portataContabile();

    $this->actingAs($contabile)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), portataImpostazioni($this, $contabile, [
            'default_user_role' => RoleEnum::AMMINISTRATORE->value,
        ]))
        ->assertSessionHasErrors('default_user_role');

    expect(app(GeneralSettings::class)->default_user_role)->toBe(RoleEnum::UTENTE->value);
});

it('Coda 258 — chi non è amministratore salva le impostazioni generali senza toccare il ruolo della registrazione, e la pagina gli dice che non lo cambia', function () {
    $contabile = portataContabile();

    $props = $this->actingAs($contabile)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];
    expect($props['ruolo_registrazione_modificabile'])->toBeFalse();

    $this->actingAs($contabile)->from(route('impostazioni.generali'))
        ->post(route('impostazioni.generali.store'), portataImpostazioni($this, $contabile, ['app_name' => 'Condominio Aurora']))
        ->assertSessionHasNoErrors();

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->refresh();
    expect($impostazioni->app_name)->toBe('Condominio Aurora')
        ->and($impostazioni->default_user_role)->toBe(RoleEnum::UTENTE->value);

    $props = $this->actingAs($this->amministratore)->get(route('impostazioni.generali'))->assertOk()->viewData('page')['props'];
    expect($props['ruolo_registrazione_modificabile'])->toBeTrue();
});

// ─── Le pagine sanno chi è nella portata ──────────────────────────────────────────────────────────────

it('Coda 258 — l\'elenco degli utenti dice, riga per riga, chi è nella portata di chi lo guarda', function () {
    $contabile = portataContabile();
    $consigliere = portataConsigliere();
    $this->condomino->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $gestibili = fn (User $chi) => collect($this->actingAs($chi)->get(route('utenti.index', ['per_page' => 50]))
        ->assertOk()->viewData('page')['props']['users'])
        ->mapWithKeys(fn ($riga) => [$riga['id'] => $riga['gestibile']]);

    $perIlCollaboratore = $gestibili($this->collaboratore);
    expect($perIlCollaboratore[$this->condomino->id])->toBeTrue()
        ->and($perIlCollaboratore[$this->fornitore->id])->toBeTrue()
        ->and($perIlCollaboratore[$this->amministratore->id])->toBeFalse()
        ->and($perIlCollaboratore[$this->collaboratore->id])->toBeFalse()
        ->and($perIlCollaboratore[$contabile->id])->toBeFalse()
        ->and($perIlCollaboratore[$consigliere->id])->toBeFalse();

    expect($gestibili($this->amministratore)->unique()->values()->all())->toBe([true]);
});

it('Coda 258 — la scheda dice alla pagina se l\'account è nella portata di chi la apre', function () {
    $consigliere = portataConsigliere();

    $props = fn (User $chi, User $di) => $this->actingAs($chi)->get(route('utenti.edit', ['utenti' => $di->id]))
        ->assertOk()->viewData('page')['props'];

    expect($props($this->collaboratore, $this->condomino)['gestibile'])->toBeTrue()
        ->and($props($this->amministratore, $consigliere)['gestibile'])->toBeTrue();

    // Fuori portata la scheda non si apre (decisione 136.1): la pagina con `gestibile` falso resta una difesa.
    $this->actingAs($this->collaboratore)->get(route('utenti.edit', ['utenti' => $consigliere->id]))->assertForbidden();
});

it('Coda 258 — la scheda mostra il ruolo che l\'account ha, con i suoi permessi; al collaboratore, nella portata, offre solo utente e fornitore', function () {
    $consigliere = portataConsigliere();

    // Fuori dalla portata del collaboratore la scheda la apre solo l'amministratore (decisione 136.1).
    $this->actingAs($this->collaboratore)->get(route('utenti.edit', ['utenti' => $consigliere->id]))->assertForbidden();

    $props = $this->actingAs($this->amministratore)->get(route('utenti.edit', ['utenti' => $consigliere->id]))
        ->assertOk()->viewData('page')['props'];

    $ruolo = collect($props['roles'])->firstWhere('id', $props['user']['roles'][0]['id']);
    expect($ruolo)->not->toBeNull()
        ->and(collect($ruolo['permissions'])->pluck('name')->all())->toBe([PermissionEnum::VIEW_COMUNICAZIONI->value]);

    // Nella portata, la tendina resta quella dei due ruoli del portale.
    $props = $this->actingAs($this->collaboratore)->get(route('utenti.edit', ['utenti' => $this->fornitore->id]))
        ->assertOk()->viewData('page')['props'];
    expect(collect($props['roles'])->map(fn ($r) => mb_strtolower((string) $r['name']))->sort()->values()->all())
        ->toBe([RoleEnum::FORNITORE->value, RoleEnum::UTENTE->value]);
});

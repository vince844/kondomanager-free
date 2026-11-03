<?php

/**
 * Le porte laterali: togliere poteri, non prenderseli.
 *
 * ## Perché questo file esiste
 *
 * La beta.55 ha chiuso tre modi di **prendersi** poteri che non spettano: assegnarsi un ruolo
 * privilegiato, concedersi un permesso diretto, costruirsi un ruolo su misura. Cercando se ne
 * restassero altri, la domanda è stata rovesciata — *e per togliere?* — e ha trovato tre porte
 * aperte in tre file diversi, tutte della stessa forma: **un'azione di governo senza guardia**.
 *
 * Non servono a scalare privilegi, servono a **chiudere fuori qualcun altro**, che sul piano
 * pratico è lo stesso danno: un'installazione governata da chi resta dentro.
 *
 * ## L'email e i ruoli di un account privilegiato (Coda 258)
 *
 * La stessa forma, una porta più in là: «Modifica utenti» guarda anche chi è l'utente modificato,
 * non solo il permesso in generale. L'email è la chiave dell'account: chi la sceglie riceve i link
 * di «password dimenticata». La decisione 127.8: chi non è amministratore non cambia né l'email né
 * i ruoli di un account privilegiato (amministratore o collaboratore). Dalla decisione 136.1 la
 * scheda di un account fuori dalla portata di chi non è amministratore è tutta
 * dell'amministratore, nome compreso: chi non lo è riceve 403 sulla pagina e sul salvataggio,
 * prima di qualunque errore sui campi (i test qui sotto erano errori sul campo, finché il nome
 * restava modificabile). Quando un amministratore cambia l'email di un altro, la verifica si
 * azzera, come dal profilo.
 *
 * Fuori portata, per chi non è amministratore, è anche chi ha un permesso che lui non ha (decisione
 * 130.2), e la stessa condizione decide chi si può reinvitare e a chi si può togliere la verifica
 * dell'email: due modi di chiudere fuori. La decisione 134.1 ha reso la regola una sola
 * (`User::haNellaPortata()`): nella portata solo chi ha i ruoli `utente` e `fornitore` e nessun permesso
 * diretto che chi agisce non ha; le sue varianti nuove sono in `ChiPuoToccareChiTest.php`.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre le pagine dei ruoli e l'eliminazione di un ruolo (`RoleController::destroy`), che sono in
 * `ChiPuoToccareChiTest.php`. Non copre gli inviti. L'avviso al vecchio indirizzo quando l'email cambia è
 * in `tests/Feature/Auth/AvvisiDiSicurezzaTest.php`.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Notifications\CustomVerifyEmailNotification;
use App\Notifications\Users\AvvisoDiSicurezzaNotification;
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

it('un condòmino non può revocare un permesso diretto a un altro utente', function () {
    $permesso = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();
    $this->collaboratore->givePermissionTo($permesso);

    $this->actingAs($this->condomino)
        ->delete(route('users.permissions.destroy', [
            'user'       => $this->collaboratore->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($this->collaboratore->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('un condòmino non può svuotare il ruolo amministratore un permesso alla volta', function () {
    // La porta peggiore delle tre: `RoleController` protegge i quattro ruoli di sistema, ma questa
    // rotta vive in un controller a parte e non passava da nessuna guardia.
    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();
    $ruolo = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->condomino)
        ->delete(route('ruoli.permissions.destroy', [
            'role'       => $ruolo->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeTrue();
});

it('nemmeno un collaboratore può toccare i permessi dei ruoli di sistema', function () {
    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();
    $ruolo = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->delete(route('ruoli.permissions.destroy', [
            'role'       => $ruolo->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeTrue();
});

it('un collaboratore non può togliere la verifica email a un amministratore', function () {
    // Togliere la verifica è un modo di chiudere fuori: il middleware `verified` sta su tutto il
    // programma, quindi un amministratore non verificato non arriva da nessuna parte.
    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $this->amministratore->id]))
        ->assertForbidden();

    expect($this->amministratore->fresh()->email_verified_at)->not->toBeNull();
});

it('il collaboratore continua a poter verificare un condòmino', function () {
    // Controprova: la guardia non deve rompere il lavoro legittimo.
    $this->condomino->update(['email_verified_at' => null]);

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $this->condomino->id]));

    expect($this->condomino->fresh()->email_verified_at)->not->toBeNull();
});

/** La scheda come la manda `ModificaUtente.vue`: intera, con il ruolo come id. */
function schedaDellaPorta(User $utente, array $modifiche = []): array
{
    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $utente->roles->first()?->id,
        'permissions' => [],
        'anagrafica'  => null,
    ], $modifiche);
}

function ruoloDellaPorta(RoleEnum $ruolo): int
{
    return Role::where('name', $ruolo->value)->firstOrFail()->id;
}

it('Coda 258 — chi non è amministratore non cambia l\'email di un amministratore', function () {
    Notification::fake();
    $emailPrima = $this->amministratore->email;

    $this->actingAs($this->collaboratore)
        ->put(
            route('utenti.update', ['utenti' => $this->amministratore->id]),
            schedaDellaPorta($this->amministratore, ['email' => 'casella.del.collaboratore@example.com'])
        )
        ->assertForbidden();

    expect($this->amministratore->fresh()->email)->toBe($emailPrima);
    Notification::assertSentOnDemandTimes(AvvisoDiSicurezzaNotification::class, 0);
});

it('Coda 258 — chi non è amministratore non cambia l\'email di un altro collaboratore', function () {
    $collega = User::factory()->create(['email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);
    $emailPrima = $collega->email;

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $collega->id]), schedaDellaPorta($collega, ['email' => 'altra@example.com']))
        ->assertForbidden();

    expect($collega->fresh()->email)->toBe($emailPrima);
});

it('Coda 258 — il collaboratore non cambia la propria email dalla scheda utenti: si cambia dal profilo, con la password', function () {
    $emailPrima = $this->collaboratore->email;

    // La propria scheda è fuori dalla sua portata (decisione 136.1): il rifiuto è il 403 della scheda.
    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $this->collaboratore->id]), schedaDellaPorta($this->collaboratore, ['email' => 'nuova@example.com']))
        ->assertForbidden();

    expect($this->collaboratore->fresh()->email)->toBe($emailPrima);
});

it('Coda 258 — il rifiuto è un 403 con il suo perché, anche per chi chiede JSON (decisione 136.1)', function () {
    $this->actingAs($this->collaboratore)
        ->putJson(
            route('utenti.update', ['utenti' => $this->amministratore->id]),
            schedaDellaPorta($this->amministratore, ['email' => 'casella.del.collaboratore@example.com'])
        )
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));
});

it('Coda 258 — il collaboratore non corregge nemmeno il nome di un amministratore: la scheda è solo dell\'amministratore (decisione 136.1)', function () {
    $nomePrima = $this->amministratore->name;

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $this->amministratore->id]), schedaDellaPorta($this->amministratore, ['name' => 'Nome Corretto']))
        ->assertForbidden();

    $dopo = $this->amministratore->fresh();
    expect($dopo->name)->toBe($nomePrima)
        ->and($dopo->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue()
        ->and($dopo->email_verified_at)->not->toBeNull();
});

it('Coda 258 — il collaboratore continua a correggere l\'email di un condòmino', function () {
    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaDellaPorta($this->condomino, ['email' => 'corretta@example.com']))
        ->assertSessionHasNoErrors();

    expect($this->condomino->fresh()->email)->toBe('corretta@example.com');
});

it('Coda 258 — chi non è amministratore non toglie il ruolo a un amministratore, anche se non è l\'ultimo', function () {
    $secondo = User::factory()->create(['email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $secondo->id]), schedaDellaPorta($secondo, ['roles' => ruoloDellaPorta(RoleEnum::UTENTE)]))
        ->assertForbidden();

    expect($secondo->fresh()->hasRole(RoleEnum::AMMINISTRATORE->value))->toBeTrue();
});

it('Coda 258 — chi non è amministratore non cambia il ruolo di un altro collaboratore', function () {
    $collega = User::factory()->create(['email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $collega->id]), schedaDellaPorta($collega, ['roles' => ruoloDellaPorta(RoleEnum::FORNITORE)]))
        ->assertForbidden();

    expect($collega->fresh()->getRoleNames()->all())->toBe([RoleEnum::COLLABORATORE->value]);
});

it('Coda 258 — la regola sui ruoli guarda il ruolo mandato per nome e ogni ruolo di un elenco', function () {
    $secondo = User::factory()->create(['email_verified_at' => now()]);
    $secondo->assignRole(RoleEnum::AMMINISTRATORE->value);

    foreach ([
        RoleEnum::UTENTE->value,
        [ruoloDellaPorta(RoleEnum::AMMINISTRATORE), ruoloDellaPorta(RoleEnum::UTENTE)],
        [ruoloDellaPorta(RoleEnum::UTENTE), ruoloDellaPorta(RoleEnum::AMMINISTRATORE)],
    ] as $ruoli) {
        $this->actingAs($this->collaboratore)
            ->put(route('utenti.update', ['utenti' => $secondo->id]), schedaDellaPorta($secondo, ['roles' => $ruoli]))
            ->assertForbidden();
    }

    expect($secondo->fresh()->getRoleNames()->all())->toBe([RoleEnum::AMMINISTRATORE->value]);
});

it('Coda 258 — l\'amministratore cambia email e ruolo di un collaboratore, e la verifica dell\'email si azzera', function () {
    $collega = User::factory()->create(['email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);

    // L'email di un collaboratore si cambia con la password ridigitata da poco (Coda 261).
    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('utenti.update', ['utenti' => $collega->id]), schedaDellaPorta($collega, [
            'email' => 'nuova.casella@example.com',
            'roles' => ruoloDellaPorta(RoleEnum::UTENTE),
        ]))
        ->assertSessionHasNoErrors();

    $dopo = $collega->fresh();
    expect($dopo->email)->toBe('nuova.casella@example.com')
        ->and($dopo->hasRole(RoleEnum::UTENTE->value))->toBeTrue()
        ->and($dopo->email_verified_at)->toBeNull();
});

it('Coda 258 — dopo il cambio fatto dall\'amministratore, l\'utente trova la pagina della verifica e il link arriva alla casella nuova', function () {
    Notification::fake();

    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaDellaPorta($this->condomino, ['email' => 'nuova@example.com']))
        ->assertSessionHasNoErrors();

    $condomino = $this->condomino->fresh();

    // Le pagine con `verified` lo rimandano qui; la pagina chiede di verificare la casella nuova.
    $this->actingAs($condomino)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('auth/VerifyEmail'));

    $this->actingAs($condomino)->post(route('verification.send'));

    Notification::assertSentTo($condomino, CustomVerifyEmailNotification::class,
        fn ($avviso, $canali, $destinatario) => $destinatario->routeNotificationFor('mail') === 'nuova@example.com');
});

it('Coda 258 — l\'amministratore che salva una scheda senza cambiare l\'email non tocca la verifica', function () {
    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaDellaPorta($this->condomino, ['name' => 'Altro Nome']))
        ->assertSessionHasNoErrors();

    expect($this->condomino->fresh()->email_verified_at)->not->toBeNull();
});

it('Coda 258 — la scheda di un privilegiato aperta e mandata senza toccare niente passa, anche con dati scomodi', function () {
    // Il corpo si costruisce dalle props della pagina come lo costruisce `ModificaUtente.vue`: il
    // ruolo è il primo ruolo ricevuto, i permessi sono quelli diretti che il ruolo non dà già. La scheda
    // di un collaboratore la apre solo l'amministratore (decisione 136.1).
    $collega = User::factory()->create(['name' => "Dell'Àcqua Niccolò", 'email' => 'niccolo.dellacqua@example.com', 'email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);
    $collega->givePermissionTo(PermissionEnum::DELETE_USERS->value);

    $props = null;
    $this->actingAs($this->amministratore)
        ->get(route('utenti.edit', ['utenti' => $collega->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props']['user'];
        });

    $permessiDelRuolo = collect($props['roles'][0]['permissions'] ?? [])->pluck('id')->all();
    $corpo = [
        'name'        => $props['name'],
        'email'       => $props['email'],
        'roles'       => $props['roles'][0]['id'],
        'permissions' => collect($props['permissions'])->pluck('id')->reject(fn ($id) => in_array($id, $permessiDelRuolo, true))->values()->all(),
        'anagrafica'  => $props['anagrafica']['id'] ?? null,
    ];

    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $collega->id]), $corpo)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('utenti.index'));

    $dopo = $collega->fresh();
    expect($dopo->name)->toBe("Dell'Àcqua Niccolò")
        ->and($dopo->email)->toBe('niccolo.dellacqua@example.com')
        ->and($dopo->getRoleNames()->all())->toBe([RoleEnum::COLLABORATORE->value])
        ->and($dopo->hasDirectPermission(PermissionEnum::DELETE_USERS->value))->toBeTrue()
        ->and($dopo->email_verified_at)->not->toBeNull();
});


// ─── «Privilegiato» rispetto a chi modifica (Coda 258, decisione 130.2) ────────

/** Un ruolo su misura di governo, come lo crea un amministratore dalla pagina dei ruoli. */
function ruoloSuMisuraDiGoverno(): Role
{
    $ruolo = Role::create(['name' => 'Contabile', 'guard_name' => 'web']);
    $ruolo->givePermissionTo([PermissionEnum::MANAGE_GENERAL_SETTINGS->value, PermissionEnum::ACCESS_ADMIN_PANEL->value]);

    return $ruolo;
}

it('Coda 258 — chi non è amministratore non cambia l\'email di chi ha un ruolo su misura con un permesso che lui non ha', function () {
    Notification::fake();
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    $emailPrima = $contabile->email;

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $contabile->id]), schedaDellaPorta($contabile, ['email' => 'casella.del.collaboratore@example.com']))
        ->assertForbidden();

    expect($contabile->fresh()->email)->toBe($emailPrima);
    Notification::assertSentOnDemandTimes(AvvisoDiSicurezzaNotification::class, 0);
});

it('Coda 258 — chi non è amministratore non cambia il ruolo di chi ha un ruolo su misura con un permesso che lui non ha', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $contabile->id]), schedaDellaPorta($contabile, ['roles' => ruoloDellaPorta(RoleEnum::UTENTE)]))
        ->assertForbidden();

    expect($contabile->fresh()->getRoleNames()->all())->toBe(['Contabile']);
});

it('Coda 258 — chi non è amministratore non cambia l\'email di un condòmino a cui l\'amministratore ha dato un permesso diretto che lui non ha', function () {
    $this->condomino->givePermissionTo(PermissionEnum::DELETE_USERS->value);
    $emailPrima = $this->condomino->email;

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), schedaDellaPorta($this->condomino, ['email' => 'altra@example.com']))
        ->assertForbidden();

    expect($this->condomino->fresh()->email)->toBe($emailPrima);
});

it('Coda 258 — il collaboratore non corregge il nome di chi ha un ruolo su misura: la scheda è solo dell\'amministratore (decisione 136.1)', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    $nomePrima = $contabile->name;

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $contabile->id]), schedaDellaPorta($contabile, ['name' => 'Nome Corretto']))
        ->assertForbidden();

    expect($contabile->fresh()->name)->toBe($nomePrima);
});

it('Coda 258 — i permessi sui propri contenuti non contano: il collaboratore corregge email e ruolo di un fornitore', function () {
    // Il ruolo `fornitore`, come `utente`, ha «Modifica propri commenti segnalazioni» e «Elimina propri
    // commenti segnalazioni», che il collaboratore non ha (ha quelli sui commenti di tutti). Non danno
    // potere su nessun altro, e non fanno del fornitore un account fuori portata.
    $fornitore = User::factory()->create(['email_verified_at' => now()]);
    $fornitore->assignRole(RoleEnum::FORNITORE->value);
    expect($fornitore->getAllPermissions()->pluck('name')->diff($this->collaboratore->getAllPermissions()->pluck('name'))->values()->all())
        ->toBe([PermissionEnum::EDIT_OWN_COMMENTS_SEGNALAZIONI->value, PermissionEnum::DELETE_OWN_COMMENTS_SEGNALAZIONI->value]);

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $fornitore->id]), schedaDellaPorta($fornitore, [
            'email' => 'fornitore.corretto@example.com',
            'roles' => ruoloDellaPorta(RoleEnum::UTENTE),
        ]))
        ->assertSessionHasNoErrors();

    $dopo = $fornitore->fresh();
    expect($dopo->email)->toBe('fornitore.corretto@example.com')
        ->and($dopo->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value]);
});

it('Coda 258 — un collaboratore resta fuori portata per un altro collaboratore anche con gli stessi permessi', function () {
    // La regola dei permessi si aggiunge a quella dei ruoli, non la sostituisce: due collaboratori di
    // serie hanno gli stessi permessi, e l'email e il ruolo dell'altro restano dell'amministratore.
    $collega = User::factory()->create(['email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);
    expect($collega->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe($this->collaboratore->getAllPermissions()->pluck('name')->sort()->values()->all());

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.update', ['utenti' => $collega->id]), schedaDellaPorta($collega, ['email' => 'altra@example.com']))
        ->assertForbidden();

    expect($collega->fresh()->email)->not->toBe('altra@example.com');
});

// ─── «Reinvia» e la verifica dell'email, con la stessa regola (Coda 258, decisione 130.2) ──────

it('Coda 258 — chi non è amministratore non reinvita chi ha un ruolo su misura con un permesso che lui non ha', function () {
    Notification::fake();
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    $passwordPrima = $contabile->password;

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.reinvite', ['email' => $contabile->email]), ['email' => $contabile->email])
        ->assertForbidden();

    expect($contabile->fresh()->password)->toBe($passwordPrima);
    Notification::assertNothingSentTo($contabile);
});

it('Coda 258 — chi non è amministratore non toglie la verifica dell\'email a chi ha un ruolo su misura con un permesso che lui non ha', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $contabile->id]))
        ->assertForbidden();

    expect($contabile->fresh()->email_verified_at)->not->toBeNull();
});

it('Coda 258 — chi non è amministratore non reinvita e non toglie la verifica a un condòmino con un permesso diretto che lui non ha', function () {
    $this->condomino->givePermissionTo(PermissionEnum::DELETE_USERS->value);
    $passwordPrima = $this->condomino->password;

    $this->actingAs($this->collaboratore)
        ->post(route('utenti.reinvite', ['email' => $this->condomino->email]), ['email' => $this->condomino->email])
        ->assertForbidden();
    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $this->condomino->id]))
        ->assertForbidden();

    $dopo = $this->condomino->fresh();
    expect($dopo->password)->toBe($passwordPrima)
        ->and($dopo->email_verified_at)->not->toBeNull();
});

it('Coda 258 — un collaboratore non toglie la verifica dell\'email a un amministratore né a un altro collaboratore', function () {
    $collega = User::factory()->create(['email_verified_at' => now()]);
    $collega->assignRole(RoleEnum::COLLABORATORE->value);

    foreach ([$this->amministratore, $collega] as $bersaglio) {
        $this->actingAs($this->collaboratore)
            ->put(route('utenti.toggle-verification', ['user' => $bersaglio->id]))
            ->assertForbidden();

        expect($bersaglio->fresh()->email_verified_at)->not->toBeNull();
    }
});

it('Coda 258 — sui condòmini e sui fornitori il collaboratore continua a reinvitare e a togliere e rimettere la verifica', function () {
    // Il fornitore ha i due permessi sui propri commenti che il collaboratore non ha: non contano, come
    // nella scheda.
    Notification::fake();
    $fornitore = User::factory()->create(['email_verified_at' => now()]);
    $fornitore->assignRole(RoleEnum::FORNITORE->value);

    foreach ([$this->condomino, $fornitore] as $bersaglio) {
        $this->actingAs($this->collaboratore)
            ->from(route('utenti.index'))
            ->put(route('utenti.toggle-verification', ['user' => $bersaglio->id]))
            ->assertRedirect(route('utenti.index'));
        expect($bersaglio->fresh()->email_verified_at)->toBeNull();

        $this->actingAs($this->collaboratore)
            ->from(route('utenti.index'))
            ->put(route('utenti.toggle-verification', ['user' => $bersaglio->id]))
            ->assertRedirect(route('utenti.index'));
        expect($bersaglio->fresh()->email_verified_at)->not->toBeNull();

        $this->actingAs($this->collaboratore)
            ->post(route('utenti.reinvite', ['email' => $bersaglio->email]), ['email' => $bersaglio->email])
            ->assertRedirect(route('utenti.index'));
        expect($bersaglio->fresh()->password)->toBeNull();
    }
});

it('Coda 258 — l\'amministratore reinvita e toglie la verifica a chi ha un ruolo su misura', function () {
    Notification::fake();
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());

    $this->actingAs($this->amministratore)
        ->from(route('utenti.index'))
        ->put(route('utenti.toggle-verification', ['user' => $contabile->id]))
        ->assertRedirect(route('utenti.index'));
    expect($contabile->fresh()->email_verified_at)->toBeNull();

    $this->actingAs($this->amministratore)
        ->post(route('utenti.reinvite', ['email' => $contabile->email]), ['email' => $contabile->email])
        ->assertRedirect(route('utenti.index'));
    expect($contabile->fresh()->password)->toBeNull();
});

// ─── Togliere un permesso, sospendere, eliminare: la stessa condizione (Coda 258, decisione 130.2) ──
// Togliere un permesso diretto toglie un potere; sospendere ed eliminare chiudono fuori (con un permesso
// dedicato che un amministratore può dare a un ruolo su misura). Oltre a «non sé stessi» e «non l'ultimo
// amministratore», usano la stessa condizione della scheda, di «reinvia» e della verifica: chi non è
// amministratore non le usa su un account privilegiato, cioè con un ruolo privilegiato o con un permesso
// che lui non ha.

/**
 * Un ruolo su misura che sospende ed elimina utenti: i permessi del collaboratore più «Sospendi utenti» ed
 * «Elimina utenti», senza altri poteri di governo. I condòmini sono alla sua portata: hanno solo il ruolo
 * `utente` e nessun permesso diretto (decisione 134.1).
 */
function ruoloSuMisuraCheSospendeEdElimina(): User
{
    $ruolo = Role::create(['name' => 'Segreteria', 'guard_name' => 'web']);
    $ruolo->givePermissionTo([
        ...array_map(fn (PermissionEnum $permesso) => $permesso->value, RoleEnum::COLLABORATORE->permissions()),
        PermissionEnum::SUSPEND_USERS->value, PermissionEnum::DELETE_USERS->value,
    ]);
    $segreteria = User::factory()->create(['email_verified_at' => now()]);
    $segreteria->assignRole($ruolo);

    return $segreteria;
}

it('Coda 258 — chi non è amministratore non toglie un permesso diretto a chi ha un ruolo su misura con un permesso che lui non ha', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    $contabile->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $this->actingAs($this->collaboratore)
        ->delete(route('users.permissions.destroy', [
            'user'       => $contabile->id,
            'permission' => Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail()->id,
        ]))
        ->assertForbidden();

    expect($contabile->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('Coda 258 — chi non è amministratore non toglie a un condòmino un permesso diretto, se l\'amministratore gliene ha dato un altro che lui non ha', function () {
    $this->condomino->givePermissionTo([PermissionEnum::VIEW_USERS->value, PermissionEnum::DELETE_USERS->value]);

    $this->actingAs($this->collaboratore)
        ->delete(route('users.permissions.destroy', [
            'user'       => $this->condomino->id,
            'permission' => Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail()->id,
        ]))
        ->assertForbidden();

    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('Coda 258 — il collaboratore continua a togliere a un condòmino un permesso diretto che ha anche lui', function () {
    $this->condomino->givePermissionTo(PermissionEnum::VIEW_USERS->value);

    $this->actingAs($this->collaboratore)
        ->from(route('utenti.index'))
        ->delete(route('users.permissions.destroy', [
            'user'       => $this->condomino->id,
            'permission' => Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail()->id,
        ]))
        ->assertRedirect(route('utenti.index'));

    expect($this->condomino->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeFalse();
});

it('Coda 258 — un ruolo su misura con «Sospendi utenti» non sospende un amministratore, un collaboratore o chi ha un permesso che lui non ha', function () {
    $segreteria = ruoloSuMisuraCheSospendeEdElimina();
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    // Un secondo amministratore: il primo resterebbe, la guardia dell'ultimo amministratore non c'entra.
    $secondoAmministratore = User::factory()->create(['email_verified_at' => now()]);
    $secondoAmministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    foreach ([$secondoAmministratore, $this->collaboratore, $contabile] as $bersaglio) {
        $this->actingAs($segreteria)
            ->put(route('utenti.suspend', ['user' => $bersaglio->id]))
            ->assertForbidden();

        expect($bersaglio->fresh()->suspended())->toBeFalse();
    }
});

it('Coda 258 — un ruolo su misura con «Elimina utenti» non elimina un amministratore, un collaboratore o chi ha un permesso che lui non ha', function () {
    $segreteria = ruoloSuMisuraCheSospendeEdElimina();
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());
    $secondoAmministratore = User::factory()->create(['email_verified_at' => now()]);
    $secondoAmministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    foreach ([$secondoAmministratore, $this->collaboratore, $contabile] as $bersaglio) {
        $this->actingAs($segreteria)
            ->delete(route('utenti.destroy', ['utenti' => $bersaglio->id]))
            ->assertForbidden();

        expect(User::find($bersaglio->id))->not->toBeNull();
    }
});

it('Coda 258 — il ruolo su misura che sospende ed elimina continua a farlo con i condòmini', function () {
    $segreteria = ruoloSuMisuraCheSospendeEdElimina();
    $altro = User::factory()->create(['email_verified_at' => now()]);
    $altro->assignRole(RoleEnum::UTENTE->value);

    $this->actingAs($segreteria)
        ->from(route('utenti.index'))
        ->put(route('utenti.suspend', ['user' => $this->condomino->id]))
        ->assertRedirect(route('utenti.index'));
    expect($this->condomino->fresh()->suspended())->toBeTrue();

    $this->actingAs($segreteria)
        ->from(route('utenti.index'))
        ->delete(route('utenti.destroy', ['utenti' => $altro->id]))
        ->assertRedirect(route('utenti.index'));
    expect(User::find($altro->id))->toBeNull();
});

it('Coda 258 — il rifiuto della sospensione dice perché', function () {
    $segreteria = ruoloSuMisuraCheSospendeEdElimina();

    $this->actingAs($segreteria)
        ->putJson(route('utenti.suspend', ['user' => $this->collaboratore->id]))
        ->assertForbidden()
        ->assertJsonPath('message', __('policies.users_privileged_target'));
});

it('Coda 258 — l\'amministratore continua a sospendere ed eliminare chi ha un ruolo su misura', function () {
    $contabile = User::factory()->create(['email_verified_at' => now()]);
    $contabile->assignRole(ruoloSuMisuraDiGoverno());

    $this->actingAs($this->amministratore)
        ->from(route('utenti.index'))
        ->put(route('utenti.suspend', ['user' => $contabile->id]))
        ->assertRedirect(route('utenti.index'));
    expect($contabile->fresh()->suspended())->toBeTrue();

    $this->actingAs($this->amministratore)
        ->from(route('utenti.index'))
        ->delete(route('utenti.destroy', ['utenti' => $contabile->id]))
        ->assertRedirect(route('utenti.index'));
    expect(User::find($contabile->id))->toBeNull();
});

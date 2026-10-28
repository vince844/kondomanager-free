<?php

/**
 * Coda 261 — le azioni sensibili chiedono la password ridigitata da poco, e il cambio della password
 * chiude le altre sessioni.
 *
 * ## Perché questo file esiste
 *
 * La 2FA (attivarla, disattivarla, leggere e rigenerare i codici di recupero), il
 * cambio dell'email, lo scarico del backup, la password dei backup e la posta in
 * uscita chiedono la password ridigitata da non più di 15 minuti (decisione 127.7); il
 * login non vale come conferma. Il cambio dell'email dal profilo sta nello stesso
 * modulo del nome: lì la «password attuale» si chiede nel modulo, solo se l'email
 * cambia.
 *
 * Il cambio e il reset della password chiudono le altre sessioni e i cookie «ricordami»:
 * `AuthenticateSession` confronta a ogni richiesta la password della sessione con quella dell'account,
 * con ogni driver di sessione; chi cambia la password resta dentro.
 *
 * ## Cosa questo file NON copre
 *
 * Il ripristino (`ripristino/riprendi` e `annulla`), che ha la sua password per scelta della .39. Gli
 * avvisi al titolare (`AvvisiDiSicurezzaTest.php`).
 */

use App\Enums\BackupStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupPasswordStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->amministratore = User::factory()->create(['name' => "D'Àngelo Amministratore", 'email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->conDueFattori = User::factory()->create([
        'email_verified_at'         => now(),
        'two_factor_secret'         => encrypt((new Google2FA)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['recupero-uno-0001'])),
        'two_factor_confirmed_at'   => now(),
    ]);
});

afterEach(function () {
    app(BackupPasswordStore::class)->clear();
});

/** La password ridigitata da `$secondi` secondi. */
function confermataDa(int $secondi): array
{
    return ['auth.password_confirmed_at' => time() - $secondi];
}

/** Un backup completato con il suo file sul disco dei backup. */
function backupCompletato(): Backup
{
    Storage::fake('backups');
    Storage::disk('backups')->put('kondo-prova.zip', 'contenuto del backup');

    return Backup::create([
        'status'   => BackupStatus::COMPLETED,
        'filename' => 'kondo-prova.zip',
        'disk'     => 'backups',
    ]);
}

// ─── La 2FA ──────────────────────────────────────────────────────────────────

it('Coda 261 — la pagina della 2FA, che mostra i codici di recupero, chiede la password', function () {
    $this->actingAs($this->conDueFattori)
        ->get(route('two-factor.show'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->conDueFattori)->withSession(confermataDa(60))
        ->get(route('two-factor.show'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('settings/TwoFactor')->where('recoveryCodes', ['recupero-uno-0001']));
});

it('Coda 261 — dopo la conferma si torna alla pagina della 2FA', function () {
    $this->actingAs($this->conDueFattori)
        ->get(route('two-factor.show'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->conDueFattori)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('two-factor.show'));
});

it('Coda 261 — le quattro azioni della 2FA rispondono 423 senza la password, e la 2FA resta com\'era', function (string $metodo, string $rotta) {
    $prima = $this->conDueFattori->fresh()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);

    $this->actingAs($this->conDueFattori)
        ->json($metodo, route($rotta), ['code' => '123456'])
        ->assertStatus(423);

    expect($this->conDueFattori->fresh()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->toEqual($prima);
})->with([
    'attiva'           => ['POST', 'two-factor.enable'],
    'conferma'         => ['POST', 'two-factor.confirm'],
    'rigenera i codici' => ['POST', 'two-factor.regenerate-recovery-codes'],
    'disattiva'        => ['DELETE', 'two-factor.disable'],
]);

it('Coda 261 — la conferma vale quindici minuti', function () {
    $this->actingAs($this->conDueFattori)->withSession(confermataDa(14 * 60))
        ->deleteJson(route('two-factor.disable'))
        ->assertSuccessful();

    expect($this->conDueFattori->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('Coda 261 — una conferma di più di quindici minuti fa non vale più', function () {
    $this->actingAs($this->conDueFattori)->withSession(confermataDa(15 * 60 + 5))
        ->deleteJson(route('two-factor.disable'))
        ->assertStatus(423);

    expect($this->conDueFattori->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('Coda 261 — il login non vale come conferma', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    $this->post('/login', ['email' => $utente->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($utente);

    $this->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));
});

it('Coda 404 — dopo un 423 non resta un indirizzo di ritorno: la pagina conferma in JSON e rifà la chiamata, il server non ricorda dove tornare', function () {
    // Il 423 lo ricevono le chiamate della pagina della 2FA aperta da più di quindici minuti. Con la Coda 261
    // restava il ritorno alla pagina della 2FA per la conferma come pagina; dalla Coda 404 la finestra della
    // pagina conferma in JSON (`ConfermaPasswordNellaPaginaTest`) e rifà la chiamata, e un indirizzo rimasto
    // in sessione deciderebbe il rimando di una conferma qualunque.
    $this->actingAs($this->conDueFattori)
        ->withHeader('Referer', route('two-factor.show'))
        ->deleteJson(route('two-factor.disable'))
        ->assertStatus(423);

    $this->assertFalse(session()->has('url.intended'));

    // Una conferma come pagina, senza ritorno, va alla pagina di casa come dopo l'accesso.
    $risposta = $this->actingAs($this->conDueFattori)->post('/confirm-password', ['password' => 'password']);

    expect($risposta->headers->get('Location'))->not->toContain('two-factor');
});

it('Coda 261 — dopo un 423 una pagina di un altro sito non diventa la destinazione della conferma', function () {
    $this->actingAs($this->conDueFattori)
        ->withHeader('Referer', 'https://altrove.example/pagina')
        ->deleteJson(route('two-factor.disable'))
        ->assertStatus(423);

    $risposta = $this->actingAs($this->conDueFattori)->post('/confirm-password', ['password' => 'password']);

    expect($risposta->headers->get('Location'))->not->toContain('altrove.example');
});

it('Coda 261 — attivare la 2FA quando è già confermata è rifiutato, e il segreto non cambia', function () {
    $segretoPrima = $this->conDueFattori->fresh()->two_factor_secret;

    $this->actingAs($this->conDueFattori)->withSession(confermataDa(60))
        ->postJson(route('two-factor.enable'))
        ->assertStatus(409);

    expect($this->conDueFattori->fresh()->two_factor_secret)->toBe($segretoPrima);
});

// ─── Il backup, la sua password, la posta ────────────────────────────────────

it('Coda 261 — scaricare un backup chiede la password, e dopo la conferma si torna alla pagina dei backup', function () {
    $backup = backupCompletato();

    $this->actingAs($this->amministratore)
        ->withHeader('Referer', route('impostazioni.backups'))
        ->get(route('impostazioni.backups.download', $backup->uuid))
        ->assertRedirect(route('password.confirm'));

    // Non all'indirizzo del file: la conferma è un modulo Inertia, e un file come risposta
    // finirebbe dentro la finestra della pagina invece di scaricarsi.
    $this->actingAs($this->amministratore)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('impostazioni.backups'));

    $this->actingAs($this->amministratore)
        ->get(route('impostazioni.backups.download', $backup->uuid))
        ->assertOk()
        ->assertDownload('kondo-prova.zip');
});

it('Coda 261 — togliere la password dei backup chiede la password dell\'account', function () {
    app(BackupPasswordStore::class)->set('chiave-dei-backup');

    $this->actingAs($this->amministratore)
        ->from(route('impostazioni.backups'))
        ->post(route('impostazioni.backups.password'), ['remove' => true])
        ->assertRedirect(route('password.confirm'));

    expect(app(BackupPasswordStore::class)->has())->toBeTrue();

    $this->actingAs($this->amministratore)->withSession(confermataDa(60))
        ->from(route('impostazioni.backups'))
        ->post(route('impostazioni.backups.password'), ['remove' => true])
        ->assertRedirect(route('impostazioni.backups'));

    expect(app(BackupPasswordStore::class)->has())->toBeFalse();
});

it('Coda 261 — impostare una password nuova dei backup chiede la password dell\'account', function () {
    $this->actingAs($this->amministratore)
        ->from(route('impostazioni.backups'))
        ->post(route('impostazioni.backups.settings'), [
            'retention_keep_last'   => 5,
            'password'              => 'chiave-nuova-dei-backup',
            'password_confirmation' => 'chiave-nuova-dei-backup',
        ])
        ->assertRedirect(route('password.confirm'));

    expect(app(BackupPasswordStore::class)->has())->toBeFalse();
});

it('Coda 261 — cambiare solo la conservazione dei backup non chiede la password', function () {
    $this->actingAs($this->amministratore)
        ->from(route('impostazioni.backups'))
        ->post(route('impostazioni.backups.settings'), ['retention_keep_last' => 7])
        ->assertRedirect(route('impostazioni.backups'));

    expect(app(\App\Settings\BackupSettings::class)->retention_keep_last)->toBe(7);
});

it('Coda 261 — salvare le impostazioni della posta chiede la password', function () {
    $this->actingAs($this->amministratore)
        ->from(route('impostazioni.mail'))
        ->post(route('admin.settings.mail.update'), ['mail_driver' => 'smtp', 'mail_host' => 'smtp.altrove.example'])
        ->assertRedirect(route('password.confirm'));
});

it('Coda 261 — la prova della posta chiede la password', function () {
    $this->actingAs($this->amministratore)
        ->postJson(route('admin.settings.mail.test'), ['mail_driver' => 'smtp', 'mail_host' => 'smtp.altrove.example', 'test_email' => 'prova@example.com'])
        ->assertStatus(423);
});

// ─── L'email dal profilo ─────────────────────────────────────────────────────

it('Coda 261 — cambiare l\'email dal profilo chiede la password attuale', function () {
    $emailPrima = $this->amministratore->email;

    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com'])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com', 'current_password' => 'sbagliata'])
        ->assertSessionHasErrors('current_password');

    expect($this->amministratore->fresh()->email)->toBe($emailPrima);

    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($this->amministratore->fresh()->email)->toBe('altra@example.com');
});

it('Coda 261 — il profilo aperto e mandato senza toccare niente non chiede la password, anche con dati scomodi', function () {
    $utente = User::factory()->create(['name' => "Dell'Òrto Niccolò", 'email' => 'niccolo+condominio@example.com', 'email_verified_at' => now()]);

    $props = null;
    $this->actingAs($utente)->get('/settings/profile')->assertOk()
        ->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props']['auth']['user'];
        });

    // Il corpo come lo costruisce `Profile.vue`: nome ed email ricevuti, la password attuale vuota.
    $this->actingAs($utente)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $props['name'], 'email' => $props['email'], 'current_password' => ''])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    $dopo = $utente->fresh();
    expect($dopo->name)->toBe("Dell'Òrto Niccolò")
        ->and($dopo->email)->toBe('niccolo+condominio@example.com')
        ->and($dopo->email_verified_at)->not->toBeNull();
});

it('Coda 261 — cambiare solo il nome dal profilo non chiede la password', function () {
    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => 'Nome Nuovo', 'email' => $this->amministratore->email])
        ->assertSessionHasNoErrors();

    expect($this->amministratore->fresh()->name)->toBe('Nome Nuovo');
});

// La «password attuale» del profilo è la stessa barriera della conferma della password: chi ha la sessione
// e non la password non la prova a raffica. Sei prove al minuto, come la conferma e il cambio della password.
it('Coda 261 — la password attuale del profilo si prova al massimo sei volte al minuto, poi nemmeno la giusta passa', function () {
    $emailPrima = $this->amministratore->email;

    $stati = [];
    for ($i = 1; $i <= 6; $i++) {
        $risposta = $this->actingAs($this->amministratore)
            ->from('/settings/profile')
            ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com', 'current_password' => "sbagliata-{$i}"]);
        $stati[] = $risposta->status();
        $risposta->assertSessionHasErrors('current_password');
    }

    expect($stati)->toBe(array_fill(0, 6, 302));

    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com', 'current_password' => 'password'])
        ->assertStatus(429);

    expect($this->amministratore->fresh()->email)->toBe($emailPrima);
});

it('Coda 261 — eliminare l\'account dal profilo prova la password al massimo sei volte al minuto', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($utente)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => "sbagliata-{$i}"])
            ->assertSessionHasErrors('password');
    }

    $this->actingAs($utente)
        ->from('/settings/profile')
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertStatus(429);

    expect(User::find($utente->id))->not->toBeNull();
});

it('Coda 261 — il limite del profilo è per utente: le prove di un altro non lo consumano', function () {
    $altro = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($altro)
            ->from('/settings/profile')
            ->patch('/settings/profile', ['name' => $altro->name, 'email' => 'x@example.com', 'current_password' => "sbagliata-{$i}"]);
    }

    $this->actingAs($this->amministratore)
        ->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $this->amministratore->name, 'email' => 'altra@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($this->amministratore->fresh()->email)->toBe('altra@example.com');
});

// La propria email si cambia solo dal profilo, con la password attuale, anche per l'amministratore e per chi
// ha «Modifica utenti» da un ruolo su misura: sulla scheda di sé stessi l'email non si cambia, per nessuno; il
// resto della scheda si salva com'è.

/** La scheda come la manda la pagina di modifica degli utenti: intera, con il ruolo come id. */
function schedaDallaPagina(User $utente, array $modifiche = []): array
{
    return array_merge([
        'name'        => $utente->name,
        'email'       => $utente->email,
        'roles'       => $utente->roles->first()?->id,
        'permissions' => [],
        'anagrafica'  => null,
    ], $modifiche);
}

it('Coda 261 — l\'amministratore non cambia la propria email dalla scheda utenti: si cambia dal profilo, con la password', function () {
    $emailPrima = $this->amministratore->email;

    $this->actingAs($this->amministratore)
        ->from(route('utenti.edit', ['utenti' => $this->amministratore->id]))
        ->put(route('utenti.update', ['utenti' => $this->amministratore->id]), schedaDallaPagina($this->amministratore, ['email' => 'ladro@example.com']))
        ->assertSessionHasErrors(['email' => __('validation.custom.email.own_from_profile')]);

    expect($this->amministratore->fresh()->email)->toBe($emailPrima);
    Notification::assertSentOnDemandTimes(\App\Notifications\Users\AvvisoDiSicurezzaNotification::class, 0);
});

it('Coda 261 — chi ha «Modifica utenti» da un ruolo su misura non cambia la propria email dalla scheda utenti', function () {
    $ruolo = \Spatie\Permission\Models\Role::create(['name' => 'Segreteria', 'guard_name' => 'web']);
    $ruolo->givePermissionTo([\App\Enums\Permission::EDIT_USERS->value, \App\Enums\Permission::VIEW_USERS->value, \App\Enums\Permission::ACCESS_ADMIN_PANEL->value]);
    $segreteria = User::factory()->create(['email_verified_at' => now()]);
    $segreteria->assignRole($ruolo);
    $emailPrima = $segreteria->email;

    // Un ruolo su misura mette il proprio account fuori dalla propria portata: la scheda è dell'amministratore
    // (Coda 258, decisione 136.1), e il rifiuto è il 403 della scheda, prima dell'errore sull'email.
    $this->actingAs($segreteria)
        ->putJson(route('utenti.update', ['utenti' => $segreteria->id]), schedaDallaPagina($segreteria, ['email' => 'altra@example.com']))
        ->assertForbidden();

    expect($segreteria->fresh()->email)->toBe($emailPrima);
});

it('Coda 261 — chi ha «Modifica utenti» come permesso aggiuntivo e il proprio account nella portata non cambia la propria email dalla scheda utenti', function () {
    // Un condòmino con i permessi degli utenti dati uno per uno: il proprio account è nella sua portata (ha
    // tutti i suoi permessi), la scheda si apre, e la propria email resta del profilo.
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(RoleEnum::UTENTE->value);
    $condomino->givePermissionTo([\App\Enums\Permission::EDIT_USERS->value, \App\Enums\Permission::VIEW_USERS->value, \App\Enums\Permission::ACCESS_ADMIN_PANEL->value]);
    $emailPrima = $condomino->email;

    $this->actingAs($condomino)
        ->putJson(route('utenti.update', ['utenti' => $condomino->id]), schedaDallaPagina($condomino, ['email' => 'altra@example.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email' => __('validation.custom.email.own_from_profile')]);

    expect($condomino->fresh()->email)->toBe($emailPrima);
});

it('Coda 261 — sulla propria scheda il resto si salva com\'è, rimandando l\'email com\'era', function () {
    $this->actingAs($this->amministratore)
        ->from(route('utenti.edit', ['utenti' => $this->amministratore->id]))
        ->put(route('utenti.update', ['utenti' => $this->amministratore->id]), schedaDallaPagina($this->amministratore, ['name' => 'Nome Corretto']))
        ->assertSessionHasNoErrors();

    $dopo = $this->amministratore->fresh();
    expect($dopo->name)->toBe('Nome Corretto')
        ->and($dopo->email_verified_at)->not->toBeNull();
});

it('Coda 261 — l\'amministratore continua a cambiare dalla scheda l\'email di un altro', function () {
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(RoleEnum::UTENTE->value);

    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $condomino->id]), schedaDallaPagina($condomino, ['email' => 'nuova.casella@example.com']))
        ->assertSessionHasNoErrors();

    expect($condomino->fresh()->email)->toBe('nuova.casella@example.com');
});

// ─── Le sessioni ─────────────────────────────────────────────────────────────

it('Coda 261 — il cambio della password chiude le altre sessioni, e chi l\'ha cambiata resta dentro', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $hashPrima = $utente->password;

    $this->actingAs($utente)->get('/settings/password')->assertOk();
    $this->actingAs($utente)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])
        ->assertSessionHasNoErrors();

    // Chi ha cambiato la password continua a lavorare.
    $this->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($utente);

    // Un'altra sessione dello stesso utente, aperta con la password di prima, viene chiusa.
    $this->flushSession();
    Auth::forgetGuards();
    $this->actingAs($utente->fresh())
        ->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($hashPrima)])
        ->get('/settings/password')
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 261 — la password reimpostata dal link chiude le sessioni aperte prima', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $hashPrima = $utente->password;

    $this->post('/reset-password', [
        'token'                 => Password::broker()->createToken($utente),
        'email'                 => $utente->email,
        'password'              => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertSessionHasNoErrors();

    Auth::forgetGuards();
    $this->actingAs($utente->fresh())
        ->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($hashPrima)])
        ->get('/settings/password')
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 261 — dopo il cambio della password il cookie «ricordami» di un altro dispositivo non fa più entrare', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $nome = Auth::guard('web')->getRecallerName();

    $accesso = $this->post('/login', ['email' => $utente->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();
    $tokenPrima = $utente->fresh()->remember_token;

    $this->actingAs($utente)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])
        ->assertSessionHasNoErrors();

    expect($utente->fresh()->remember_token)->not->toBe($tokenPrima);

    // L'altro dispositivo: niente sessione, solo il cookie di prima.
    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordami)->get('/settings/password')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('Coda 261 — il cookie «ricordami» di chi non ha cambiato niente continua a far entrare', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $nome = Auth::guard('web')->getRecallerName();

    $accesso = $this->post('/login', ['email' => $utente->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();

    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $ricordami)->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($utente);
});

it('Coda 261 — chi cambia la password con «ricordami» acceso lo conserva su questo dispositivo', function () {
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $nome = Auth::guard('web')->getRecallerName();

    $accesso = $this->post('/login', ['email' => $utente->email, 'password' => 'password', 'remember' => 'on']);
    $ricordami = $accesso->getCookie($nome)->getValue();

    $cambio = $this->withCookie($nome, $ricordami)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password'      => 'password',
            'password'              => 'una-password-nuova',
            'password_confirmation' => 'una-password-nuova',
        ])
        ->assertSessionHasNoErrors();

    // Il cookie di questo dispositivo è riscritto con il token e la password nuovi.
    $nuovo = $cambio->getCookie($nome);
    expect($nuovo)->not->toBeNull();

    $this->flushSession();
    Auth::forgetGuards();
    $this->withCookie($nome, $nuovo->getValue())->get('/settings/password')->assertOk();
    $this->assertAuthenticatedAs($utente);
});


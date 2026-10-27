<?php

use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Services\System\SystemFinalizer;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Test dello strato HTTP del ripristino: middleware modalità (503 statico),
 * autenticazione a token degli step (senza sessione), permessi e sudo mode
 * all'avvio. Non esercita il motore (già coperto altrove): verifica la
 * superficie di rete.
 */
function restoreAdmin(): User
{
    $permission = SpatiePermission::firstOrCreate([
        'name' => Permission::MANAGE_GENERAL_SETTINGS->value,
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create(['password' => bcrypt('la-mia-password')]);
    $user->givePermissionTo($permission);

    return $user;
}

afterEach(function () {
    app(RestoreState::class)->clear();
    app(RestoreMode::class)->exit();
});

test('la modalità ripristino blocca le rotte normali con un 503 statico', function () {
    app(RestoreMode::class)->enter('uuid-di-prova');

    $this->get('/admin/dashboard')
        ->assertStatus(503)
        ->assertSee('Ripristino in corso', false);
});

test('la modalità ripristino lascia passare le rotte di ripristino e l health check', function () {
    app(RestoreMode::class)->enter('uuid-di-prova');

    // /up health check resta raggiungibile. Dalla 1.11.0-beta.32 risponde 200 solo su
    // un'installazione fatta (lock o amministratore esistente): qui l'amministratore.
    Role::findOrCreate('amministratore', 'web');
    User::factory()->create()->assignRole('amministratore');

    $this->get('/up')->assertOk()->assertJsonPath('stato', 'ok');

    // La rotta di stato risponde (senza token → 403, ma NON 503: è passata
    // dal blocco di modalità)
    $this->getJson('/ripristino/stato')->assertStatus(403);
});

test('lo step del ripristino richiede un token valido', function () {
    // Nessuno stato/token → 403
    $this->postJson('/ripristino/step')->assertStatus(403);

    // Emettiamo un token valido nello stato
    $state = app(RestoreState::class);
    $state->put(['uuid' => 'x', 'phase' => 'pending']);
    $token = $state->issueToken(3600);

    // Token errato → 403
    $this->postJson('/ripristino/step', [], ['X-Restore-Token' => 'sbagliato'])->assertStatus(403);

    // Token giusto → passa il middleware (200, lo stato non è "running" reale
    // ma il manager risponde comunque lo stato corrente)
    $this->postJson('/ripristino/step', [], ['X-Restore-Token' => $token])->assertOk();
});

test('avviare un ripristino richiede il permesso', function () {
    // Il permesso esiste nel sistema, ma questo utente non ce l'ha
    SpatiePermission::firstOrCreate(['name' => Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $user = User::factory()->create();

    $backup = Backup::create([
        'uuid' => (string) Str::uuid(),
        'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);

    $this->actingAs($user)
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
            'account_password' => 'qualsiasi',
        ])
        ->assertForbidden();
});

test('avviare un ripristino richiede la password corretta dell account (sudo)', function () {
    $user = restoreAdmin();

    $backup = Backup::create([
        'uuid' => (string) Str::uuid(),
        'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);

    // Password account sbagliata → errore di validazione, nessun avvio
    $this->actingAs($user)
        ->from('/impostazioni/backups')
        ->post("/impostazioni/backups/{$backup->uuid}/ripristina", [
            'account_password' => 'password-sbagliata',
        ])
        ->assertSessionHasErrors('account_password');

    expect(app(RestoreMode::class)->active())->toBeFalse();
});

test('Coda 261 — l\'avvio del ripristino prova la password dell\'account al massimo sei volte al minuto', function () {
    $user = restoreAdmin();

    $backup = Backup::create([
        'uuid' => (string) Str::uuid(),
        'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($user)
            ->from('/impostazioni/backups')
            ->post("/impostazioni/backups/{$backup->uuid}/ripristina", ['account_password' => "sbagliata-{$i}"])
            ->assertSessionHasErrors('account_password');
    }

    $this->actingAs($user)
        ->from('/impostazioni/backups')
        ->post("/impostazioni/backups/{$backup->uuid}/ripristina", ['account_password' => 'la-mia-password'])
        ->assertStatus(429);

    expect(app(RestoreMode::class)->active())->toBeFalse();
});

test('la pagina di esito è raggiungibile senza autenticazione', function () {
    // Con l'ora di chiusura, come la scrive il ripristino: senza, dalla Coda 343 un ripristino concluso
    // conta come più vecchio di 24 ore e un ospite non lo vede più.
    app(RestoreState::class)->put([
        'uuid' => 'abc', 'phase' => 'completed',
        'outcome' => ['reregistered_backups' => 2],
        'completed_at' => time(),
    ]);

    $this->get('/ripristino/esito')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('impostazioni/RestoreResult')
            ->where('restore.phase', 'completed')
        );
});

test('la 503 in corso mostra una sola lingua e si auto-aggiorna', function () {
    app(RestoreState::class)->put(['uuid' => 'r1', 'phase' => 'importing_database']);
    app(RestoreMode::class)->enter('r1', 'it');

    $res = $this->get('/admin/dashboard')->assertStatus(503);
    $res->assertSee('Ripristino in corso', false);              // IT
    $res->assertDontSee('A backup is being restored', false);   // niente EN impilato
    $res->assertSee('http-equiv="refresh"', false);             // spinner → refresh attivo
    expect($res->headers->get('Retry-After'))->toBe('30');
});

test('la 503 mostra il recupero (non lo spinner) quando il ripristino è fallito', function () {
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'error' => 'Errore-di-prova-XYZ', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');

    $res = $this->get('/admin/dashboard')->assertStatus(503);
    $res->assertSee('Ripristino non riuscito', false);   // pagina di recupero
    $res->assertSee('Riprendi il ripristino', false);    // pulsante
    $res->assertSee('id="restoreLog"', false);           // log tecnico copiabile
    // Il messaggio d'errore no: la pagina è per chiunque, l'errore solo con il token (Coda 343).
    $res->assertDontSee('Errore-di-prova-XYZ', false);
    $res->assertDontSee('http-equiv="refresh"', false);  // niente auto-refresh
    expect($res->headers->get('Retry-After'))->toBeNull();
});

test('riprendi richiede un token o la password dell account', function () {
    $admin = restoreAdmin();
    // Lo stato come lo scrive l'avvio: con l'impronta della password di chi avvia, su cui il recupero la verifica
    // (decisione 134.2: durante l'import la tabella degli utenti è a metà).
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword(),
        'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');

    // il finalizer di sistema è già coperto altrove: qui isoliamo l'auth
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    // Senza credenziali → 422
    $this->postJson('/ripristino/riprendi')->assertStatus(422);

    // Password sbagliata → 422
    $this->postJson('/ripristino/riprendi', ['account_password' => 'errata'])->assertStatus(422);

    // Password corretta → autorizzato (l'endpoint avanza uno step)
    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->assertOk();
});

test('annulla sblocca l applicazione con un token valido', function () {
    $admin = restoreAdmin();
    $state = app(RestoreState::class);
    $state->put(['uuid' => 'r1', 'phase' => 'failed', 'created_by' => $admin->id]);
    $token = $state->issueToken(3600);
    app(RestoreMode::class)->enter('r1', 'it');

    expect(app(RestoreMode::class)->active())->toBeTrue();

    $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])->assertOk();

    expect(app(RestoreMode::class)->active())->toBeFalse(); // sbloccata
});

// --- giro di sicurezza della 1.11.0-beta.39 -----------------------------------------------------
// Il recupero (riprendi, annulla) non ha login né CSRF e prova una password. Lo stato resta su file
// anche a ripristino completato o annullato (lo legge la pagina dell'esito), ma la rotta risponde
// solo con la modalità ripristino attiva, e con un limite di tentativi. Fuori dal ripristino
// risponde 404: lì la password dell'account passa dal login, con il suo blocco e il secondo
// fattore.

test('riprendi e annulla non rispondono a ripristino completato, nemmeno con la password giusta', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'completed', 'created_by' => $admin->id,
    ]);
    expect(app(RestoreMode::class)->active())->toBeFalse();

    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->assertNotFound();
    $this->postJson('/ripristino/annulla', ['account_password' => 'la-mia-password'])->assertNotFound();

    expect(app(RestoreState::class)->get()['phase'] ?? null)->toBe('completed');
});

test('riprendi non risponde dopo «annulla e sblocca», con la modalità spenta', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'aborted' => true, 'created_by' => $admin->id,
    ]);

    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->assertNotFound();
});

test('con la modalità attiva, dopo cinque password sbagliate il recupero si ferma', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'created_by' => $admin->id, 'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');

    $esiti = [];
    for ($i = 0; $i < 6; $i++) {
        $esiti[] = $this->postJson('/ripristino/riprendi', ['account_password' => 'errata-'.$i])->status();
    }

    expect($esiti)->toBe([422, 422, 422, 422, 422, 429]);
});

test('Code 262 e 259 — il limite del recupero conta la rete /64 di un indirizzo IPv6, anche con la password giusta', function () {
    $admin = restoreAdmin();
    // Lo stato come lo scrive l'avvio, con l'impronta: la sesta password è davvero quella giusta.
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword(),
        'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    // Cinque password sbagliate, ognuna da un indirizzo diverso della stessa /64.
    $esiti = [];
    for ($i = 1; $i <= 5; $i++) {
        $esiti[] = $this->withServerVariables(['REMOTE_ADDR' => sprintf('2a01:4f8:1:2::%x', $i)])
            ->postJson('/ripristino/riprendi', ['account_password' => 'errata-'.$i])->status();
    }

    $esiti[] = $this->withServerVariables(['REMOTE_ADDR' => '2a01:4f8:1:2::6'])
        ->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->status();

    expect($esiti)->toBe([422, 422, 422, 422, 422, 429]);
});

test('le password sbagliate di chiunque non bloccano il recupero a chi ha il token', function () {
    $admin = restoreAdmin();
    $state = app(RestoreState::class);
    // Lo stato come lo scrive l'avvio, con l'impronta: la sesta password è davvero quella giusta.
    $state->put(['uuid' => 'r1', 'phase' => 'failed', 'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword()]);
    $token = $state->issueToken(3600);
    app(RestoreMode::class)->enter('r1', 'it');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/ripristino/annulla', ['account_password' => 'errata-'.$i])->assertStatus(422);
    }
    // La sesta password è bloccata, anche se giusta, e anche su «riprendi»: il limite è unico.
    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->assertStatus(429);

    // Il token passa lo stesso.
    $this->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])->assertOk();
    expect(app(RestoreMode::class)->active())->toBeFalse();
});

test('il tentativo si conta prima di verificare la password, e la password giusta lo restituisce', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword(),
        'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    // Il contatore letto DENTRO la verifica: se si contasse dopo, richieste in parallelo lo
    // troverebbero tutte a zero e proverebbero ciascuna la sua password.
    $letti = [];
    $chiave = 'ripristino-recupero|127.0.0.1';
    Hash::partialMock()->shouldReceive('check')->andReturnUsing(function ($valore) use (&$letti, $chiave) {
        $letti[] = RateLimiter::attempts($chiave);

        return $valore === 'la-mia-password';
    });

    $this->postJson('/ripristino/riprendi', ['account_password' => 'errata'])->assertStatus(422);
    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])->assertOk();

    expect($letti)->toBe([1, 2])
        ->and(RateLimiter::attempts($chiave))->toBe(1); // la password giusta ha restituito il suo
});

test('la pagina 503 dice di aspettare quando i tentativi sono troppi', function () {
    app(RestoreState::class)->put(['uuid' => 'r1', 'phase' => 'failed', 'created_by' => 1]);
    app(RestoreMode::class)->enter('r1', 'it');

    $this->get('/')->assertStatus(503)
        ->assertSee('Troppi tentativi: attendi un minuto e riprova.', false)
        ->assertSee('r.status === 429', false);
});

// --- Le rotte autenticate dal token non guardano la sessione ---------------------------------------
// Step, stato, «riprendi» e «annulla» si autenticano con il token del ripristino (o con la password
// dell'account, per «riprendi» e «annulla»), non con la sessione, che l'import del database sostituisce.
// Il gruppo `web` chiude una sessione quando la password del suo utente non è più quella con cui è nata
// (`auth.session`, Coda 261): con una sessione aperta prima dell'import, e nel database importato quel
// utente con un'altra password o senza, lo step rispondeva 401 e la pagina dava il ripristino per
// fallito a metà. Queste quattro rotte stanno fuori da `auth.session`; tutte le altre no.

/** Una sessione vera aperta col login, e il token del ripristino; poi il browser senza stato in memoria. */
function sessioneDiPrimaETokenDelRipristino($test, User $utente, array $stato): array
{
    $test->post('/login', ['email' => $utente->email, 'password' => 'la-mia-password'])->assertSessionHasNoErrors();
    $test->get('/settings/password')->assertOk();
    $sessione = session()->all();

    $store = app(RestoreState::class);
    $store->put($stato + ['created_by' => $utente->id]);
    $token = $store->issueToken(3600);

    $test->flushSession();
    \Illuminate\Support\Facades\Auth::forgetGuards();

    return [$sessione, $token];
}

dataset('password nel database importato', [
    'un\'altra password' => [fn () => Hash::make('quella-del-backup')],
    'nessuna password' => [fn () => null],
]);

test('lo step e lo stato del ripristino rispondono al token anche con la sessione di prima di un utente cambiato dall\'import', function (Closure $password) {
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    [$sessione, $token] = sessioneDiPrimaETokenDelRipristino($this, $admin, ['uuid' => 'r1', 'phase' => 'pending']);

    $admin->forceFill(['password' => $password()])->save();

    $this->withSession($sessione)
        ->postJson('/ripristino/step', [], ['X-Restore-Token' => $token])
        ->assertOk();
    $this->withSession($sessione)
        ->getJson('/ripristino/stato', ['X-Restore-Token' => $token])
        ->assertOk()
        ->assertJsonPath('restore.uuid', 'r1');
})->with('password nel database importato');

test('riprendi e annulla rispondono al token anche con la sessione di prima di un utente cambiato dall\'import', function (Closure $password) {
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    [$sessione, $token] = sessioneDiPrimaETokenDelRipristino($this, $admin, [
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing', 'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    $admin->forceFill(['password' => $password()])->save();

    // «Riprendi» emette un token nuovo, che sostituisce il primo.
    $token = $this->withSession($sessione)
        ->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token])
        ->assertOk()
        ->json('token');

    // Di nuovo fallito, per poterlo annullare.
    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'finalizing']);
    app(RestoreMode::class)->enter('r1', 'it');

    $this->withSession($sessione)
        ->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])
        ->assertOk();
    expect(app(RestoreMode::class)->active())->toBeFalse();
})->with('password nel database importato');

test('le altre rotte delle impostazioni restano sotto auth.session, le quattro del token no', function () {
    $router = app('router');
    $sotto = fn (string $nome) => in_array(
        \App\Http\Middleware\AutenticaSessione::class,
        $router->gatherRouteMiddleware($router->getRoutes()->getByName($nome)),
        true
    );

    foreach (['ripristino.step', 'ripristino.status', 'ripristino.resume', 'ripristino.abort'] as $nome) {
        expect($sotto($nome))->toBeFalse("{$nome} non deve passare da auth.session");
    }

    // La pagina dell'esito resta sotto: mostra il dettaglio a chi ha l'accesso, e una sessione nata con
    // un'altra password non vale come accesso.
    foreach ([
        'ripristino.result', 'impostazioni', 'impostazioni.mail', 'admin.settings.mail.update', 'admin.settings.mail.test',
        'impostazioni.backups', 'impostazioni.backups.restore.inspect', 'impostazioni.backups.restore.start',
        'impostazioni.backups.download', 'profile.edit', 'profile.update', 'password.update', 'two-factor.show', 'two-factor.disable',
    ] as $nome) {
        expect($sotto($nome))->toBeTrue("{$nome} deve passare da auth.session");
    }
});

test('una pagina delle impostazioni con la sessione di prima di un utente cambiato porta al login', function () {
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    [$sessione] = sessioneDiPrimaETokenDelRipristino($this, $admin, ['uuid' => 'r1', 'phase' => 'pending']);

    $admin->forceFill(['password' => Hash::make('quella-del-backup')])->save();

    $this->withSession($sessione)->get('/settings/profile')->assertRedirect(route('login'));
});

// Gli altri middleware del gruppo `web` che leggono l'utente della sessione: la sospensione
// (`CheckSuspendedUser`) e l'aggiornamento in sospeso (`CheckForPendingUpdates`). Nel database importato
// l'utente della sessione può essere sospeso, e un backup di una versione precedente fa risultare un
// aggiornamento da completare: le quattro rotte del token rispondevano con un rimando (302) al login o alla
// pagina dell'aggiornamento, e la pagina del ripristino si fermava a metà senza dire niente. A metà
// dell'import la tabella degli utenti può non esserci ancora: leggere l'utente della sessione dava un
// errore 500. Queste rotte non leggono l'utente della sessione, da nessuna parte.

dataset('utente della sessione nel database importato', [
    'sospeso' => [function (User $utente) {
        $utente->forceFill(['suspended_at' => now()])->save();
    }],
    'amministratore, con il database di una versione precedente' => [function (User $utente) {
        $impostazioni = app(GeneralSettings::class);
        $impostazioni->version = '1.9.0';
        $impostazioni->save();
        Cache::forget('system.needs_upgrade');
    }],
    'assente: la tabella degli utenti non c\'è ancora' => [function (User $utente) {
        DB::statement('ALTER TABLE users RENAME TO users_non_ancora_importati');
    }],
]);

/** L'amministratore della sessione, con gli aggiornamenti dall'applicazione accesi e il database alla versione del programma. */
function amministratoreConAggiornamentiAccesi(): User
{
    Role::findOrCreate('amministratore', 'web');
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    $admin->assignRole('amministratore');

    config(['installer.run_installer' => true]);
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->version = config('app.version');
    $impostazioni->save();

    return $admin;
}

test('lo step e lo stato del ripristino rispondono al token qualunque sia, nel database importato, l\'utente della sessione', function (Closure $importato) {
    $admin = amministratoreConAggiornamentiAccesi();
    [$sessione, $token] = sessioneDiPrimaETokenDelRipristino($this, $admin, ['uuid' => 'r1', 'phase' => 'pending']);

    $importato($admin);

    $this->withSession($sessione)
        ->postJson('/ripristino/step', [], ['X-Restore-Token' => $token])
        ->assertOk();
    $this->withSession($sessione)
        ->getJson('/ripristino/stato', ['X-Restore-Token' => $token])
        ->assertOk()
        ->assertJsonPath('restore.uuid', 'r1');
})->with('utente della sessione nel database importato');

test('riprendi e annulla rispondono al token qualunque sia, nel database importato, l\'utente della sessione', function (Closure $importato) {
    $admin = amministratoreConAggiornamentiAccesi();
    [$sessione, $token] = sessioneDiPrimaETokenDelRipristino($this, $admin, [
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing', 'error' => 'x', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andReturnNull());

    $importato($admin);

    $token = $this->withSession($sessione)
        ->postJson('/ripristino/riprendi', [], ['X-Restore-Token' => $token])
        ->assertOk()
        ->json('token');

    app(RestoreState::class)->merge(['phase' => 'failed', 'failed_phase' => 'finalizing']);
    app(RestoreMode::class)->enter('r1', 'it');

    $this->withSession($sessione)
        ->postJson('/ripristino/annulla', [], ['X-Restore-Token' => $token])
        ->assertOk();
    expect(app(RestoreMode::class)->active())->toBeFalse();
})->with('utente della sessione nel database importato');

test('le altre rotte delle impostazioni restano sotto sospensione e aggiornamento in sospeso, le quattro del token no', function () {
    $router = app('router');
    $middleware = fn (string $nome) => $router->gatherRouteMiddleware($router->getRoutes()->getByName($nome));
    $chiLeggeLaSessione = [
        \App\Http\Middleware\CheckSuspendedUser::class,
        \App\Http\Middleware\CheckForPendingUpdates::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
    ];

    foreach (['ripristino.step', 'ripristino.status', 'ripristino.resume', 'ripristino.abort'] as $nome) {
        expect(array_values(array_intersect($chiLeggeLaSessione, $middleware($nome))))->toBe([], "{$nome} non deve leggere l'utente della sessione");
    }

    foreach (['ripristino.result', 'impostazioni.backups', 'impostazioni.backups.restore.start', 'profile.edit', 'profile.update'] as $nome) {
        expect(array_values(array_intersect($chiLeggeLaSessione, $middleware($nome))))->toBe($chiLeggeLaSessione, "{$nome} deve passare dai tre");
    }
});

test('la pagina dell\'esito, con la sessione di un utente sospeso nel database importato, porta al login', function () {
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    [$sessione] = sessioneDiPrimaETokenDelRipristino($this, $admin, ['uuid' => 'r1', 'phase' => 'completed', 'completed_at' => time()]);

    $admin->forceFill(['suspended_at' => now()])->save();

    $this->withSession($sessione)->get('/ripristino/esito')->assertRedirect(route('login'));
});

// --- Coda 343: la pagina dell'esito e la 503 a chi non ha l'accesso --------------------------------
// La pagina dell'esito si apre senza accesso, perché dopo un ripristino le sessioni sono azzerate. A un
// ospite dà solo l'esito e che cosa riconfigurare (decisione 127.5): niente manifest del backup (nome e
// indirizzo dell'installazione, versioni, righe per tabella), niente messaggio d'errore (può contenere
// dati di persone), niente identificativo e niente versione. Chi gestisce i backup, con l'accesso, vede
// tutto. La parte pubblica sparisce 24 ore dopo la chiusura; un ripristino fallito con la modalità ancora
// attiva resta. La 503 di un ripristino fermo stampa versione ed errore solo a chi ha il token.

/** Uno stato chiuso come lo scrive il ripristino, con tutto ciò che un ospite non deve vedere. */
function statoRipristinoCompleto(array $cambi = []): array
{
    return array_merge([
        'uuid' => 'uuid-del-ripristino-segreto',
        'phase' => 'completed',
        'manifest' => [
            'app' => ['name' => 'Studio Bianchi Condomini', 'url' => 'https://gestione.studiobianchi.example', 'version' => '1.10.0'],
            'database' => ['driver' => 'mysql', 'rows' => 123456, 'rows_per_table' => ['anagrafiche' => 812]],
        ],
        'outcome' => [
            'cleanup' => ['two_factor_reset_users' => 3, 'smtp_password_cleared' => true],
            'app_key_adopted' => true,
            'reregistered_backups' => 2,
        ],
        'created_by' => 1,
        'completed_at' => time(),
    ], $cambi);
}

test('Coda 343 — a un ospite la pagina dell\'esito dà solo l\'esito e che cosa riconfigurare', function () {
    app(RestoreState::class)->put(statoRipristinoCompleto());

    $risposta = $this->get('/ripristino/esito')->assertOk();

    $risposta->assertInertia(fn ($page) => $page
        ->component('impostazioni/RestoreResult')
        ->where('restore.phase', 'completed')
        ->where('restore.outcome', [
            'cleanup' => ['two_factor_reset_users' => 3, 'smtp_password_cleared' => true],
            'app_key_adopted' => true,
        ])
        ->missing('restore.manifest')
        ->missing('restore.uuid')
        ->missing('restore.error')
        ->missing('restore.app_version')
        ->where('dettaglio', false)
    );

    expect($risposta->getContent())
        ->not->toContain('Studio Bianchi Condomini')
        ->not->toContain('studiobianchi.example')
        ->not->toContain('uuid-del-ripristino-segreto')
        ->not->toContain('rows_per_table');
});

test('Coda 343 — a un ospite un ripristino annullato dà l\'esito, senza errore né fase', function () {
    app(RestoreState::class)->put(statoRipristinoCompleto([
        'phase' => 'failed', 'failed_phase' => 'importing_database', 'failed_at' => time() - 60,
        'error' => 'SQLSTATE[23000]: Duplicate entry mario.rossi@example.it', 'aborted' => true, 'aborted_at' => time(),
        'completed_at' => null, 'outcome' => [],
    ]));

    $risposta = $this->get('/ripristino/esito')->assertOk();

    $risposta->assertInertia(fn ($page) => $page
        ->where('restore.phase', 'failed')
        ->where('restore.aborted', true)
        ->missing('restore.error')
        ->missing('restore.failed_phase')
        ->missing('restore.failed_at')
        ->missing('restore.uuid')
    );
    expect($risposta->getContent())->not->toContain('mario.rossi@example.it');
});

test('Coda 343 — la parte pubblica sparisce 24 ore dopo la chiusura', function (array $chiusura, bool $ancoraVisibile) {
    // Il dataset dice quanti secondi fa, e l'ora si calcola qui: un dataset si legge quando il file si carica,
    // e nella suite intera il test gira minuti dopo (con «23 ore e 59 minuti» scritto come ora fissa, scadeva).
    foreach (['completed_at', 'aborted_at'] as $chiave) {
        if (is_int($chiusura[$chiave] ?? null)) {
            $chiusura[$chiave] = time() - $chiusura[$chiave];
        }
    }
    app(RestoreState::class)->put(statoRipristinoCompleto($chiusura));

    $this->get('/ripristino/esito')->assertOk()->assertInertia(fn ($page) => $ancoraVisibile
        ? $page->where('restore.phase', $chiusura['phase'] ?? 'completed')
        : $page->where('restore', null)
    );
})->with([
    'completato da 23 ore e 59 minuti' => [['completed_at' => 86340], true],
    'completato da 24 ore e un minuto' => [['completed_at' => 86460], false],
    'annullato da 24 ore e un minuto' => [['phase' => 'failed', 'aborted' => true, 'aborted_at' => 86460, 'completed_at' => null], false],
    'annullato da un\'ora' => [['phase' => 'failed', 'aborted' => true, 'aborted_at' => 3600, 'completed_at' => null], true],
    'completato senza l\'ora di chiusura' => [['completed_at' => null], false],
]);

test('Coda 343 — con la modalità ripristino attiva un ripristino fallito resta, anche dopo 24 ore', function () {
    app(RestoreState::class)->put(statoRipristinoCompleto([
        'phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time() - 2 * 86400, 'error' => 'x', 'completed_at' => null,
    ]));
    app(RestoreMode::class)->enter('uuid-del-ripristino-segreto', 'it');

    $this->get('/ripristino/esito')->assertOk()->assertInertia(fn ($page) => $page
        ->where('restore.phase', 'failed')
        ->missing('restore.error')
    );
});

test('Coda 343 — chi gestisce i backup, con l\'accesso, vede tutto, anche dopo 24 ore', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put(statoRipristinoCompleto(['completed_at' => time() - 3 * 86400, 'error' => null]));

    $this->actingAs($admin)->get('/ripristino/esito')->assertOk()->assertInertia(fn ($page) => $page
        ->where('dettaglio', true)
        ->where('restore.uuid', 'uuid-del-ripristino-segreto')
        ->where('restore.manifest.app.version', '1.10.0')
        ->where('restore.outcome.reregistered_backups', 2)
        ->where('restore.app_version', config('app.version'))
    );
});

test('Coda 343 — con l\'accesso ma senza il permesso dei backup si vede come un ospite', function () {
    SpatiePermission::firstOrCreate(['name' => Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    app(RestoreState::class)->put(statoRipristinoCompleto());

    $this->actingAs($condomino)->get('/ripristino/esito')->assertOk()->assertInertia(fn ($page) => $page
        ->where('dettaglio', false)
        ->where('restore.phase', 'completed')
        ->missing('restore.manifest')
    );
});

test('Coda 343 — un ospite che vede l\'esito, dopo l\'accesso torna alla pagina dell\'esito', function () {
    Role::findOrCreate('amministratore', 'web');
    Role::findOrCreate('collaboratore', 'web');
    $admin = restoreAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();
    $admin->assignRole('amministratore');
    app(RestoreState::class)->put(statoRipristinoCompleto());

    $this->get('/ripristino/esito')->assertOk();

    $this->post('/login', ['email' => $admin->email, 'password' => 'la-mia-password'])
        ->assertRedirect(route('ripristino.result'));
});

test('Coda 343 — con il token, e dopo «Riprendi» con la password, versione ed errore ci sono', function () {
    $admin = restoreAdmin();
    $store = app(RestoreState::class);
    $store->put(statoRipristinoCompleto([
        'phase' => 'failed', 'failed_phase' => 'finalizing', 'failed_at' => time(), 'error' => 'Errore-di-prova-XYZ',
        'created_by' => $admin->id, 'created_by_password_hash' => $admin->getAuthPassword(), 'completed_at' => null,
    ]));
    $token = $store->issueToken(3600);
    app(RestoreMode::class)->enter('uuid-del-ripristino-segreto', 'it');

    $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token])
        ->assertOk()
        ->assertJsonPath('restore.error', 'Errore-di-prova-XYZ')
        ->assertJsonPath('restore.app_version', config('app.version'));

    // «Riprendi» con la password giusta: il ripristino si ferma di nuovo, e la risposta dice perché.
    $this->mock(SystemFinalizer::class, fn ($m) => $m->shouldReceive('finalize')->andThrow(new RuntimeException('Errore-di-prova-dopo')));

    $this->postJson('/ripristino/riprendi', ['account_password' => 'la-mia-password'])
        ->assertOk()
        ->assertJsonPath('restore.phase', 'failed')
        ->assertJsonPath('restore.error', 'Errore-di-prova-dopo')
        ->assertJsonPath('restore.app_version', config('app.version'));
});

test('Coda 343 — la 503 di un ripristino fermo non stampa versione ed errore, e li scrive dopo «Riprendi»', function () {
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'error' => 'Errore-di-prova-XYZ', 'failed_at' => time(),
    ]);
    app(RestoreMode::class)->enter('r1', 'it');

    $res = $this->get('/admin/dashboard')->assertStatus(503);
    $res->assertSee('Ripristino non riuscito', false)
        ->assertDontSee('Errore-di-prova-XYZ', false)
        ->assertDontSee(config('app.version'), false)
        // Un nuovo fallimento dopo «Riprendi» scrive nel log ciò che la risposta col token porta.
        ->assertSee('scriviLog(data.restore)', false);

    preg_match('#<pre id="restoreLog">(.*?)</pre>#s', $res->getContent(), $log);
    expect($log[1] ?? null)->not->toBeNull()
        ->toContain('uuid: r1')
        ->toContain('failed_phase: finalizing')
        ->not->toContain('version:')
        ->not->toContain('error:');
});

test('Coda 343 — se il permesso dei backup non si può controllare, la pagina resta quella pubblica e si apre', function () {
    // Dopo un ripristino il database può essere a metà, o senza la tabella dei permessi: la pagina
    // dell'esito deve aprirsi lo stesso. Qui il permesso non esiste proprio.
    $utente = User::factory()->create(['email_verified_at' => now()]);
    app(RestoreState::class)->put(statoRipristinoCompleto());

    $this->actingAs($utente)->get('/ripristino/esito')->assertOk()->assertInertia(fn ($page) => $page
        ->where('dettaglio', false)
        ->where('restore.phase', 'completed')
        ->missing('restore.manifest')
    );
});

function backupDaRipristinare(): Backup
{
    return Backup::create([
        'uuid' => (string) Str::uuid(),
        'filename' => 'x.zip', 'disk' => 'backups',
        'status' => BackupStatus::COMPLETED, 'type' => 'full',
    ]);
}

test('Coda 261 — all\'avvio del ripristino la password dell\'account sbagliata si legge nella lingua dell\'installazione', function (string $lingua) {
    $impostazioni = app(GeneralSettings::class);
    $impostazioni->language = $lingua;
    $impostazioni->save();

    $user = restoreAdmin();
    $backup = backupDaRipristinare();

    // Come la manda la finestra dei backup (`impostazioniBackups.vue`): JSON, con gli stessi campi.
    $this->actingAs($user)
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
            'account_password' => 'password-sbagliata', 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.account_password.0', __('impostazioni.restore_error_account_password', [], $lingua));

    expect(__('impostazioni.restore_error_account_password', [], $lingua))->not->toBe('impostazioni.restore_error_account_password')
        ->and(app(RestoreMode::class)->active())->toBeFalse();
})->with(['it', 'en', 'pt', 'es']);

test('Coda 261 — l\'ispezione di un archivio prova la sua password al massimo sei volte al minuto, senza consumare le prove della password dell\'account', function () {
    $user = restoreAdmin();
    $backup = backupDaRipristinare();
    $ispeziona = fn (string $password) => $this->actingAs($user)
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina/ispeziona", ['password' => $password]);

    for ($i = 1; $i <= 6; $i++) {
        expect($ispeziona("prova-{$i}")->status())->not->toBe(429);
    }
    $ispeziona('prova-7')->assertStatus(429);

    // L'avvio prova ancora la password dell'account: risponde sulla password, non con il limite.
    $this->actingAs($user)
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
            'account_password' => 'password-sbagliata', 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_password']);

    // Passato il minuto, l'ispezione riprende.
    $this->travel(61)->seconds();
    expect($ispeziona('prova-8')->status())->not->toBe(429);
});

test('Coda 261 — le prove della password dell\'account non consumano quelle dell\'ispezione di un archivio', function () {
    $user = restoreAdmin();
    $backup = backupDaRipristinare();

    for ($i = 1; $i <= 6; $i++) {
        $this->actingAs($user)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
            'account_password' => "sbagliata-{$i}", 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false,
        ]);
    }
    $this->actingAs($user)->postJson("/impostazioni/backups/{$backup->uuid}/ripristina", [
        'account_password' => 'la-mia-password', 'password' => null, 'safety_backup' => true, 'adopt_app_key' => false,
    ])->assertStatus(429);

    expect($this->actingAs($user)
        ->postJson("/impostazioni/backups/{$backup->uuid}/ripristina/ispeziona", ['password' => 'prova'])
        ->status())->not->toBe(429);
});

test('le rotte del ripristino a token rispondono anche quando le impostazioni generali non si leggono, a metà dell\'import', function () {
    // La lingua dell'installazione si legge nel database; a metà dell'import la tabella può non esserci. La lingua
    // gira prima dei limiti di rotta (`bootstrap/app.php`), e sulle rotte del token resta un ripiego, non un errore.
    $store = app(RestoreState::class);
    $store->put(['uuid' => 'r1', 'phase' => 'pending']);
    $token = $store->issueToken(3600);

    \Illuminate\Support\Facades\Schema::drop('settings');

    $this->getJson('/ripristino/stato', ['X-Restore-Token' => $token])
        ->assertOk()
        ->assertJsonPath('restore.uuid', 'r1');
});

<?php

use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Services\System\SystemFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('la pagina di esito è raggiungibile senza autenticazione', function () {
    app(RestoreState::class)->put([
        'uuid' => 'abc', 'phase' => 'completed',
        'outcome' => ['reregistered_backups' => 2],
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
    $res->assertSee('Errore-di-prova-XYZ', false);       // log tecnico copiabile
    $res->assertDontSee('http-equiv="refresh"', false);  // niente auto-refresh
    expect($res->headers->get('Retry-After'))->toBeNull();
});

test('riprendi richiede un token o la password dell account', function () {
    $admin = restoreAdmin();
    app(RestoreState::class)->put([
        'uuid' => 'r1', 'phase' => 'failed', 'failed_phase' => 'finalizing',
        'created_by' => $admin->id, 'error' => 'x', 'failed_at' => time(),
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
// Il recupero (riprendi, annulla) non ha login né CSRF e prova una password. Fino alla beta.38 lo
// stato restava su file anche a ripristino completato o annullato, e la rotta restava aperta per
// sempre: un modo per provare la password dell'amministratore senza il blocco del login e senza il
// secondo fattore. Ora risponde solo con la modalità ripristino attiva, e con un limite di tentativi.

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

test('le password sbagliate di chiunque non bloccano il recupero a chi ha il token', function () {
    $admin = restoreAdmin();
    $state = app(RestoreState::class);
    $state->put(['uuid' => 'r1', 'phase' => 'failed', 'created_by' => $admin->id]);
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
        'created_by' => $admin->id, 'error' => 'x', 'failed_at' => time(),
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

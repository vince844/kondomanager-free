<?php

use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use App\Services\System\SystemFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

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

    // /up health check resta raggiungibile
    $this->get('/up')->assertOk();

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

<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeBackupDumper;

/**
 * Test HTTP della pagina backup: autorizzazioni, flusso store → step →
 * download, eliminazione e impostazioni di retention.
 */
function backupAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value);

    return $user;
}

function backupFixture(string $relative = ''): string
{
    return base_path('storage/framework/testing/backup-http-fixture'.($relative !== '' ? '/'.$relative : ''));
}

beforeEach(function () {
    Storage::fake('backups');

    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper);

    // I permessi devono esistere come righe anche per i test "senza permesso":
    // hasPermissionTo() lancia un'eccezione se il permesso non esiste affatto.
    foreach ([Permission::MANAGE_GENERAL_SETTINGS, Permission::ACCESS_ADMIN_PANEL] as $permission) {
        Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => $permission->value,
            'guard_name' => 'web',
        ]);
    }

    File::ensureDirectoryExists(backupFixture());
    File::put(backupFixture('documento.pdf'), str_repeat('PDF', 500));

    config()->set('backup.include', ['storage/framework/testing/backup-http-fixture']);
    config()->set('backup.exclude', []);
});

afterEach(function () {
    File::deleteDirectory(backupFixture());
    File::deleteDirectory(storage_path('app/backups/tmp'));
});

test('un ospite viene rediretto al login', function () {
    $this->get('/impostazioni/backups')->assertRedirect('/login');
});

test('un utente senza permesso riceve 403', function () {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user)->get('/impostazioni/backups')->assertForbidden();
    $this->actingAs($user)->post('/impostazioni/backups')->assertForbidden();
});

test('un amministratore vede la pagina con preflight e lista backup', function () {
    $this->actingAs(backupAdmin())
        ->get('/impostazioni/backups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('impostazioni/impostazioniBackups')
            ->has('backups')
            ->has('preflight.checks', 4)
            ->where('preflight.ok', true)
            ->where('retention_keep_last', 5)
        );
});

test('il flusso completo store → step → download funziona', function () {
    $admin = backupAdmin();

    // Avvio: crea il backup ed esegue il primo step
    $response = $this->actingAs($admin)->postJson('/impostazioni/backups');
    $response->assertOk();

    $uuid = $response->json('backup.uuid');
    expect($uuid)->not->toBeNull();

    // Con il dumper finto e pochi file il primo step completa già tutto;
    // in ogni caso si continua a chiamare step finché non è terminale.
    $status = $response->json('backup.status');
    $guard = 0;

    while (! in_array($status, ['completed', 'failed'], true) && $guard < 10) {
        $step = $this->actingAs($admin)->postJson("/impostazioni/backups/{$uuid}/step");
        $step->assertOk();
        $status = $step->json('backup.status');
        $guard++;
    }

    expect($status)->toBe('completed');

    $backup = Backup::where('uuid', $uuid)->firstOrFail();

    // Download consentito all'amministratore
    $this->actingAs($admin)
        ->get("/impostazioni/backups/{$uuid}/download")
        ->assertOk()
        ->assertDownload($backup->filename);

    // Download negato a chi non ha il permesso
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get("/impostazioni/backups/{$uuid}/download")
        ->assertForbidden();
});

test('un secondo store mentre un backup è in corso restituisce 409', function () {
    $admin = backupAdmin();

    // Un backup in corso (bloccato a metà, non completato)
    $backup = Backup::create(['status' => BackupStatus::DUMPING_DATABASE, 'started_at' => now()]);

    $this->actingAs($admin)->postJson('/impostazioni/backups')->assertStatus(409);

    $backup->delete();
});

test('destroy elimina il backup e reindirizza con flash', function () {
    $admin = backupAdmin();

    $this->actingAs($admin)->postJson('/impostazioni/backups');
    $backup = Backup::firstOrFail();

    // Completa il backup
    $guard = 0;
    while ($backup->refresh()->isRunning() && $guard < 10) {
        $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/step");
        $guard++;
    }

    $filename = $backup->refresh()->filename;

    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->delete("/impostazioni/backups/{$backup->uuid}")
        ->assertRedirect('/impostazioni/backups');

    expect(Backup::count())->toBe(0);
    Storage::disk('backups')->assertMissing($filename);
});

test('con i backup disabilitati (demo/gestito) tutte le rotte rispondono 404 e la card risulta disabilitata', function () {
    config()->set('backup.enabled', false);

    $admin = backupAdmin();

    // Anche un amministratore con tutti i permessi riceve 404: la funzione non esiste
    $this->actingAs($admin)->get('/impostazioni/backups')->assertNotFound();
    $this->actingAs($admin)->postJson('/impostazioni/backups')->assertNotFound();
    $this->actingAs($admin)->post('/impostazioni/backups/settings', ['retention_keep_last' => 3])->assertNotFound();

    $backup = Backup::create(['status' => \App\Enums\BackupStatus::COMPLETED, 'filename' => 'x.zip']);
    $this->actingAs($admin)->postJson("/impostazioni/backups/{$backup->uuid}/step")->assertNotFound();
    $this->actingAs($admin)->get("/impostazioni/backups/{$backup->uuid}/download")->assertNotFound();
    $this->actingAs($admin)->delete("/impostazioni/backups/{$backup->uuid}")->assertNotFound();

    // La hub impostazioni segnala la card come disabilitata
    $this->actingAs($admin)
        ->get('/impostazioni')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('backups_enabled', false));
});

test('con i backup abilitati la hub espone backups_enabled true e lo stato di esecuzione', function () {
    $this->actingAs(backupAdmin())
        ->get('/impostazioni')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('backups_enabled', true)
            ->where('backup_running', false));

    // Con un backup in corso la hub lo segnala (badge sulla card)
    Backup::create(['status' => \App\Enums\BackupStatus::DUMPING_DATABASE, 'started_at' => now()]);

    $this->actingAs(backupAdmin())
        ->get('/impostazioni')
        ->assertInertia(fn ($page) => $page->where('backup_running', true));
});

test('le impostazioni di retention vengono validate e salvate', function () {
    $admin = backupAdmin();

    // Valore non valido
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 0])
        ->assertSessionHasErrors('retention_keep_last');

    // Valore valido
    $this->actingAs($admin)
        ->from('/impostazioni/backups')
        ->post('/impostazioni/backups/settings', ['retention_keep_last' => 10])
        ->assertRedirect('/impostazioni/backups');

    expect(app(BackupSettings::class)->retention_keep_last)->toBe(10);
});

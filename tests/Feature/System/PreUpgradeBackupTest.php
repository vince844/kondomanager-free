<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupPasswordStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeBackupDumper;

uses(RefreshDatabase::class);

/**
 * Backup di sicurezza PRIMA dell'aggiornamento: endpoint dedicati nel flusso
 * di upgrade (SystemUpgradeController), guidati a step dalla pagina di
 * conferma. Protetti dal ruolo amministratore, indipendenti dal permesso e
 * dal kill-switch della feature backup.
 */
function upgradeAdmin(): User
{
    Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);

    /** @var User $user */
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('amministratore');

    return $user;
}

beforeEach(function () {
    Storage::fake('backups');
    app(BackupPasswordStore::class)->clear();
    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper);

    foreach ([Permission::MANAGE_GENERAL_SETTINGS, Permission::ACCESS_ADMIN_PANEL] as $permission) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
    }
});

afterEach(function () {
    app(BackupPasswordStore::class)->clear();
    File::deleteDirectory(config('backup.tmp_path'));
});

test('la pagina di conferma segnala se il backup automatico è disponibile', function () {
    $this->actingAs(upgradeAdmin())
        ->get('/system/upgrade/finalize')
        ->assertInertia(fn ($page) => $page
            ->component('system/upgrade/Confirm')
            ->where('canBackup', true) // la tabella backups esiste dopo le migrazioni
        );
});

test('solo un amministratore può avviare il backup pre-aggiornamento', function () {
    // Ospite
    $this->post('/system/upgrade/backup')->assertRedirect('/login');

    // Utente senza ruolo amministratore
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->post('/system/upgrade/backup')
        ->assertForbidden();
});

test('il backup pre-aggiornamento è di tipo solo-database e arriva a completamento', function () {
    $admin = upgradeAdmin();

    $response = $this->actingAs($admin)->post('/system/upgrade/backup');
    $response->assertOk();

    $uuid = $response->json('backup.uuid');
    expect($uuid)->not->toBeEmpty();

    $backup = Backup::firstWhere('uuid', $uuid);
    expect($backup->type)->toBe(Backup::TYPE_DB_ONLY);
    expect($backup->encrypted)->toBeFalse();

    // Guida gli step come farà il frontend
    $status = $response->json('backup.status');
    $guard = 0;
    while (in_array($status, ['pending', 'dumping_database', 'archiving_files', 'finalizing'], true) && $guard < 50) {
        $step = $this->actingAs($admin)->post("/system/upgrade/backup/{$uuid}/step");
        $status = $step->json('backup.status');
        $guard++;
    }

    expect($status)->toBe('completed');
    expect(Backup::firstWhere('uuid', $uuid)->status->value)->toBe('completed');
});

test('un secondo avvio riusa il backup di sicurezza già in corso', function () {
    $admin = upgradeAdmin();

    $first = $this->actingAs($admin)->post('/system/upgrade/backup')->json('backup.uuid');
    // Prima di completarlo, un secondo avvio (es. reload della pagina) NON ne crea un altro
    $second = $this->actingAs($admin)->post('/system/upgrade/backup')->json('backup.uuid');

    expect($second)->toBe($first);
    expect(Backup::count())->toBe(1);
});

test('senza la tabella backups l endpoint risponde 409', function () {
    Schema::dropIfExists('backups');

    $this->actingAs(upgradeAdmin())
        ->post('/system/upgrade/backup')
        ->assertStatus(409);
});

test('il backup pre-aggiornamento funziona anche se manca type/encrypted (upgrade da < beta.12)', function () {
    // Simula lo schema di una versione anteriore alla beta.12: il backup di
    // sicurezza gira PRIMA delle migrazioni, quindi le colonne type/encrypted
    // (aggiunte in beta.12) potrebbero non esistere ancora.
    Schema::table('backups', function (Blueprint $table) {
        $table->dropColumn(['type', 'encrypted']);
    });
    expect(Schema::hasColumn('backups', 'type'))->toBeFalse();

    $admin = upgradeAdmin();

    // Prima della fix: 500 "Unknown column 'type'". Dopo: lo schema viene
    // allineato al volo (solo infrastruttura) e il backup parte regolarmente.
    $response = $this->actingAs($admin)->post('/system/upgrade/backup');
    $response->assertOk();

    expect(Schema::hasColumn('backups', 'type'))->toBeTrue();
    expect(Schema::hasColumn('backups', 'encrypted'))->toBeTrue();

    $uuid = $response->json('backup.uuid');
    expect(Backup::firstWhere('uuid', $uuid)->type)->toBe(Backup::TYPE_DB_ONLY);

    $status = $response->json('backup.status');
    $guard = 0;
    while (in_array($status, ['pending', 'dumping_database', 'archiving_files', 'finalizing'], true) && $guard < 50) {
        $status = $this->actingAs($admin)->post("/system/upgrade/backup/{$uuid}/step")->json('backup.status');
        $guard++;
    }

    expect($status)->toBe('completed');
});

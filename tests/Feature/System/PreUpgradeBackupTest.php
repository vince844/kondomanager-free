<?php

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Enums\Permission;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backup\BackupPasswordStore;
use App\Settings\GeneralSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 *
 * Il backup gira prima delle migrazioni, quindi è disponibile solo se la
 * tabella `backups` esiste GIÀ nel database che si sta per migrare: chi
 * aggiorna da una 1.9.x non ce l'ha e aggiorna senza rete automatica
 * (SystemUpgradeController::preUpgradeBackupAvailable).
 */
function upgradeAdmin(): User
{
    Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);

    /** @var User $user */
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('amministratore');

    return $user;
}

/**
 * Versione registrata nel database, cioè quella DA CUI si sta aggiornando.
 * Spatie tiene i settings come singleton nel container: senza dimenticare
 * l'istanza, il controller continuerebbe a leggere il valore caricato prima.
 */
function setDbVersion(string $version): void
{
    DB::table('settings')
        ->where('group', 'general')
        ->where('name', 'version')
        ->update(['payload' => json_encode($version)]);

    app()->forgetInstance(GeneralSettings::class);
}

beforeEach(function () {
    Storage::fake('backups');
    app(BackupPasswordStore::class)->clear();
    $this->app->bind(DatabaseDumperInterface::class, fn () => new FakeBackupDumper);

    foreach ([Permission::MANAGE_GENERAL_SETTINGS, Permission::ACCESS_ADMIN_PANEL] as $permission) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
    }

    // Salvo diverso avviso, questi test descrivono il comportamento a gate
    // aperto: si aggiorna partendo da una 1.10.0 o successiva.
    setDbVersion('1.10.0');
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

test('il backup pre-aggiornamento funziona anche se manca type/encrypted', function () {
    // Difesa in profondità: se per qualsiasi motivo la tabella backups
    // esistesse senza le colonne type/encrypted (aggiunte in un secondo
    // momento), lo schema viene allineato al volo invece di far fallire il
    // backup con "Unknown column".
    Schema::table('backups', function (Blueprint $table) {
        $table->dropColumn(['type', 'encrypted']);
    });
    expect(Schema::hasColumn('backups', 'type'))->toBeFalse();

    $admin = upgradeAdmin();

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

/**
 * Aggiornamento da una versione anteriore alla 1.10: il backup di sicurezza
 * non è disponibile, ma l'aggiornamento deve restare possibile.
 *
 * È il caso 1.9.1 → 1.10.0, cioè il percorso di ogni installazione ufficiale.
 */
test('aggiornando da una 1.9.x il backup pre-aggiornamento non è disponibile', function () {
    setDbVersion('1.9.1');

    $this->actingAs(upgradeAdmin())
        ->get('/system/upgrade/finalize')
        ->assertInertia(fn ($page) => $page
            ->component('system/upgrade/Confirm')
            ->where('currentVersion', '1.9.1')
            ->where('needsUpgrade', true)
            ->where('canBackup', false)
        );
});

test('aggiornando da una 1.9.x l endpoint del backup risponde 409', function () {
    setDbVersion('1.9.1');

    // La tabella esiste (RefreshDatabase ha eseguito tutte le migrazioni), ma
    // il gate di versione la considera comunque non disponibile: su
    // un'installazione reale a 1.9.1 quella tabella non ci sarebbe.
    expect(Schema::hasTable('backups'))->toBeTrue();

    $this->actingAs(upgradeAdmin())
        ->post('/system/upgrade/backup')
        ->assertStatus(409);
});

test('anche una versione illeggibile nel database disattiva il backup senza bloccare nulla', function () {
    setDbVersion('');

    $this->actingAs(upgradeAdmin())
        ->get('/system/upgrade/finalize')
        ->assertInertia(fn ($page) => $page->where('canBackup', false));
});

/**
 * L'invariante che conta più di tutte: la finalizzazione deve restare
 * raggiungibile anche quando il backup non è disponibile.
 *
 * Regressione beta.29: la pagina di conferma disabilitava il pulsante quando
 * canBackup era falso, perché la conferma esplicita necessaria a sbloccarlo
 * era renderizzata solo nel ramo in cui il backup ERA disponibile. Ogni
 * aggiornamento da 1.9.1 restava murato sulla schermata di conferma.
 */
test('la finalizzazione resta raggiungibile quando il backup non è disponibile', function () {
    setDbVersion('1.9.1');

    $this->actingAs(upgradeAdmin())
        ->post('/system/upgrade/run')
        ->assertRedirect(route('system.upgrade.changelog'));

    $version = json_decode(
        DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload'),
        true
    );

    expect($version)->toBe(config('app.version'));
});

test('il backup torna disponibile aggiornando da una 1.10 o successiva', function () {
    foreach (['1.10.0', '1.10.3', '1.11.0'] as $from) {
        setDbVersion($from);

        $this->actingAs(upgradeAdmin())
            ->get('/system/upgrade/finalize')
            ->assertInertia(fn ($page) => $page->where('canBackup', true));
    }
});

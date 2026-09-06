<?php

use App\Enums\RestoreStatus;
use App\Services\Backup\Database\MySqlDumper;
use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\RestoreManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * E2E della catena CROSS-VERSIONE: ripristinare un backup più VECCHIO su codice
 * più NUOVO deve, in finalizzazione, eseguire le migrazioni mancanti e
 * riallineare la versione — esattamente come un aggiornamento normale
 * (docs/ripristino_backup_design.md §7-bis).
 *
 * Il RestoreManagerTest "puro" mocka il SystemFinalizer; QUI il finalizer gira
 * DAVVERO, con un vero Artisan::call('migrate'). Per rendere il test fedele ma
 * controllato: si registra una micro-migrazione "sonda" in una cartella
 * temporanea, si migra il database completo su uno schema usa-e-getta, si crea
 * il "gap" rimuovendo SOLO la sonda (simula un backup precedente alla sonda),
 * si fa il dump = backup vecchio, e lo si ripristina. Il migrate reale trova
 * ESATTAMENTE una migrazione pendente — la sonda — e la esegue; le migrazioni
 * reali (già nella tabella migrations) non vengono ritoccate.
 *
 * ⚠ Solo schema usa-e-getta km_restore_chain (design doc §12). MAI il DB condiviso.
 */
uses(TestCase::class);

// I database di prova portano un suffisso unico per checkout: senza, lanciare la suite
// in TEST e in ufficiale insieme fa sfilare il database all'altra esecuzione. Vedi
// `kmDatabaseDiProva()` in tests/Pest.php.
defined('KM_CHAIN_DB') || define('KM_CHAIN_DB', kmDatabaseDiProva('km_restore_chain'));
const KM_CHAIN_PROBE_TABLE = 'km_restore_chain_probe';

function kmChainAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function kmChainUseConnection(): void
{
    config()->set('database.connections.restore_chain', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
        'database' => KM_CHAIN_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'restore_chain');
    DB::purge('restore_chain');
    DB::reconnect('restore_chain');
}

beforeEach(function () {
    if (kmChainAdminPdo() === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }

    $admin = kmChainAdminPdo();
    $admin->exec('DROP DATABASE IF EXISTS '.KM_CHAIN_DB);
    $admin->exec('CREATE DATABASE '.KM_CHAIN_DB.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Cartelle isolate: archivi, tmp, stato ripristino tutti usa-e-getta.
    $this->chainRoot = sys_get_temp_dir().'/km-restore-chain-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->chainRoot.'/backups');
    config()->set('filesystems.disks.backups.root', $this->chainRoot.'/backups');
    config()->set('backup.disk', 'backups');
    config()->set('backup.tmp_path', $this->chainRoot.'/backups/tmp');
    config()->set('backup.restore.state_file', $this->chainRoot.'/restore-state.json');
    config()->set('backup.restore.lock_file', $this->chainRoot.'/restore-lock');
    config()->set('backup.restore.marker_file', $this->chainRoot.'/restore-marker');

    // Migrazione "sonda" in una cartella temporanea, registrata nel migrator:
    // così Artisan::call('migrate') (senza --path) la include, senza toccare
    // database/migrations reale.
    $this->probeDir = $this->chainRoot.'/probe-migrations';
    File::ensureDirectoryExists($this->probeDir);
    $probeTable = KM_CHAIN_PROBE_TABLE;
    File::put($this->probeDir.'/2099_01_01_000000_create_km_restore_chain_probe_table.php', <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('{$probeTable}', function (Blueprint \$table) {
                \$table->id();
                \$table->string('nota')->default('creata dalla sonda');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('{$probeTable}');
        }
    };
    PHP);

    app('migrator')->path($this->probeDir);
});

afterEach(function () {
    kmChainAdminPdo()?->exec('DROP DATABASE IF EXISTS '.KM_CHAIN_DB);
    if (isset($this->chainRoot)) {
        File::deleteDirectory($this->chainRoot);
    }
});

test('un backup più vecchio: la finalizzazione esegue la migrazione mancante e riallinea la versione', function () {
    kmChainUseConnection();

    // 1) Migra il DB completo (migrazioni reali + sonda) sullo schema throwaway.
    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable(KM_CHAIN_PROBE_TABLE))->toBeTrue();
    expect(Schema::hasTable('settings'))->toBeTrue();

    // Marcatore di dati reali + versione "vecchia" nel DB.
    DB::table('settings')->insert(['group' => 'test_chain', 'name' => 'marker', 'locked' => 0, 'payload' => json_encode('sopravvissuto')]);
    DB::table('settings')->where('group', 'general')->where('name', 'version')->update(['payload' => json_encode('1.0.0')]);

    // 2) Crea il GAP: rimuovi SOLO la sonda → il DB torna "prima" di quella
    //    migrazione, come un backup di una versione precedente.
    Schema::drop(KM_CHAIN_PROBE_TABLE);
    DB::table('migrations')->where('migration', 'like', '%km_restore_chain_probe%')->delete();
    expect(Schema::hasTable(KM_CHAIN_PROBE_TABLE))->toBeFalse();

    // 3) Dump di questo stato = "backup vecchio".
    $dumpPath = $this->chainRoot.'/database.sql';
    $dumper = new MySqlDumper;
    $state = [];
    do {
        $done = $dumper->dump($dumpPath, $state, new StepBudget(5.0));
        $state = json_decode((string) json_encode($state), true);
    } while (! $done);

    $manifest = [
        'manifest_format' => 1,
        'contents' => 'db_only',
        'encrypted' => false,
        'app' => ['name' => 'Origine', 'version' => '1.0.0', 'url' => 'https://origine.test'],
        'database' => ['driver' => 'mysql', 'dump_sha256' => hash_file('sha256', $dumpPath)],
    ];

    $archivePath = $this->chainRoot.'/backups/backup-vecchio.zip';
    $zip = new ZipArchive;
    $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->addFile($dumpPath, 'db/database.sql');
    $zip->close();

    // 4) Ripristino con SystemFinalizer REALE (niente mock): migrate deve girare.
    $manager = app(RestoreManager::class);
    $result = $manager->start($archivePath, null, ['safety_backup' => false, 'adopt_app_key' => false]);

    $steps = 0;
    do {
        $st = $manager->runStep();
        $steps++;
    } while (RestoreStatus::from($st['phase'])->isRunning() && $steps < 2000);

    expect($st['phase'])->toBe(RestoreStatus::COMPLETED->value, 'Errore: '.($st['error'] ?? 'nessuno'));

    // 5) Verifiche: la migrazione mancante è stata eseguita, la versione riallineata.
    kmChainUseConnection();

    // La tabella della sonda è tornata (la migrazione pendente è stata eseguita da finalize)
    expect(Schema::hasTable(KM_CHAIN_PROBE_TABLE))->toBeTrue();

    // La tabella migrations ora include di nuovo la sonda
    $probeRegistered = DB::table('migrations')->where('migration', 'like', '%km_restore_chain_probe%')->exists();
    expect($probeRegistered)->toBeTrue();

    // La versione nel DB è stata riallineata a quella del codice (§7-bis)
    $version = json_decode(DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload'), true);
    expect($version)->toBe(config('app.version'));

    // I dati reali importati sono sopravvissuti
    $marker = json_decode(DB::table('settings')->where('group', 'test_chain')->where('name', 'marker')->value('payload'), true);
    expect($marker)->toBe('sopravvissuto');
})->group('mysql-heavy');

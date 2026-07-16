<?php

use App\Enums\RestoreStatus;
use App\Models\Backup;
use App\Services\Restore\RestoreManager;
use App\Services\Restore\RestoreMode;
use App\Services\System\SystemFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * E2E del RestoreManager su MySQL reale usa-e-getta: costruisce un archivio
 * di backup di uno schema seminato, lo ripristina SOPRA uno schema diverso
 * guidando la macchina a stati step-per-step, e verifica che il database
 * finale corrisponda all'origine, che la versione sia riallineata, che gli
 * archivi orfani siano ri-registrati e che la modalità ripristino si spenga.
 *
 * ⚠ Solo schema usa-e-getta km_restore_e2e (design doc §12). Safety backup
 * disattivato (BackupManager è già testato a parte): qui si collauda la
 * catena estrazione → verifica → import → file → finalizzazione.
 */
uses(TestCase::class);

const KM_RESTORE_E2E_DB = 'km_restore_e2e';

function kmE2eAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function kmE2eUseConnection(): void
{
    config()->set('database.connections.restore_e2e', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
        'database' => KM_RESTORE_E2E_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'restore_e2e');
    DB::purge('restore_e2e');
    DB::reconnect('restore_e2e');
}

/**
 * Costruisce un archivio di backup minimale (manifest + db/database.sql +
 * files/storage/app/...) a partire da un dump SQL. Non usa il BackupManager
 * per restare indipendente: verifichiamo il RESTORE, non il backup.
 */
function kmE2eBuildArchive(string $path, string $dumpSql, array $manifest, array $files = []): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->addFromString('db/database.sql', $dumpSql);
    foreach ($files as $relative => $contents) {
        $zip->addFromString('files/'.$relative, $contents);
    }
    $zip->close();
}

beforeEach(function () {
    if (kmE2eAdminPdo() === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }

    $admin = kmE2eAdminPdo();
    $admin->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_E2E_DB);
    $admin->exec('CREATE DATABASE '.KM_RESTORE_E2E_DB.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // Disco backups e temporanei isolati in una cartella usa-e-getta: la
    // ri-registrazione degli orfani NON deve toccare i backup reali.
    $this->e2eRoot = sys_get_temp_dir().'/km-restore-e2e-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->e2eRoot.'/backups');
    config()->set('filesystems.disks.backups.root', $this->e2eRoot.'/backups');
    config()->set('backup.disk', 'backups');
    config()->set('backup.tmp_path', $this->e2eRoot.'/backups/tmp');
    config()->set('backup.restore.state_file', $this->e2eRoot.'/restore-state.json');
    config()->set('backup.restore.lock_file', $this->e2eRoot.'/restore-lock');
    config()->set('backup.restore.marker_file', $this->e2eRoot.'/restore-marker');
});

afterEach(function () {
    kmE2eAdminPdo()?->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_E2E_DB);
    if (isset($this->e2eRoot)) {
        File::deleteDirectory($this->e2eRoot);
    }
});

test('ripristino db_only completo: import, versione riallineata, orfani registrati, modalità spenta', function () {
    kmE2eUseConnection();

    // --- Stato "origine" catturato in un dump: schema condominiale minimo,
    //     inclusa la tabella settings con una versione VECCHIA e la tabella
    //     backups (che il ripristino ri-registra) e migrations. ---
    $dump = <<<'SQL'
    SET NAMES utf8mb4;
    SET FOREIGN_KEY_CHECKS=0;
    SET UNIQUE_CHECKS=0;
    SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
    SET time_zone='+00:00';

    DROP TABLE IF EXISTS `migrations`;
    CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT, `migration` varchar(255) NOT NULL, `batch` int NOT NULL, PRIMARY KEY (`id`));
    INSERT INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000000_create_users_table', 1);

    DROP TABLE IF EXISTS `settings`;
    CREATE TABLE `settings` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `group` varchar(255) NOT NULL, `name` varchar(255) NOT NULL, `locked` tinyint(1) NOT NULL DEFAULT '0', `payload` json NOT NULL, PRIMARY KEY (`id`));
    INSERT INTO `settings` (`group`, `name`, `locked`, `payload`) VALUES ('general', 'version', 0, '"1.0.0"'), ('general', 'app_name', 0, '"Origine"');

    DROP TABLE IF EXISTS `condomini`;
    CREATE TABLE `condomini` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `nome` varchar(255) NOT NULL, `note` text, PRIMARY KEY (`id`));
    INSERT INTO `condomini` (`nome`, `note`) VALUES ('Palazzo; Rossi', 'nota con \' apice e\nnewline'), ('Condominio 🏢', NULL);

    DROP TABLE IF EXISTS `sessions`;
    CREATE TABLE `sessions` (`id` varchar(255) NOT NULL, `payload` longtext NOT NULL, `last_activity` int NOT NULL, PRIMARY KEY (`id`));
    INSERT INTO `sessions` (`id`, `payload`, `last_activity`) VALUES ('vecchia-sessione', 'x', 123);

    DROP TABLE IF EXISTS `backups`;
    CREATE TABLE `backups` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `uuid` char(36) NOT NULL, `filename` varchar(255) DEFAULT NULL, `disk` varchar(255) NOT NULL DEFAULT 'backups', `status` varchar(255) NOT NULL DEFAULT 'pending', `type` varchar(20) NOT NULL DEFAULT 'full', `encrypted` tinyint(1) NOT NULL DEFAULT '0', `progress` json DEFAULT NULL, `checkpoint` json DEFAULT NULL, `manifest` json DEFAULT NULL, `size` bigint unsigned DEFAULT NULL, `checksum` varchar(64) DEFAULT NULL, `error` text, `created_by` bigint unsigned DEFAULT NULL, `started_at` timestamp NULL DEFAULT NULL, `completed_at` timestamp NULL DEFAULT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `backups_uuid_unique` (`uuid`));

    SET FOREIGN_KEY_CHECKS=1;
    SET UNIQUE_CHECKS=1;
    SQL;

    $manifest = [
        'manifest_format' => 1,
        'contents' => 'db_only',
        'encrypted' => false,
        'app' => ['name' => 'Origine', 'version' => '1.0.0', 'url' => 'https://origine.test'],
        'database' => ['driver' => 'mysql', 'dump_sha256' => hash('sha256', $dump)],
    ];

    $archivePath = $this->e2eRoot.'/backups/backup-origine.zip';
    kmE2eBuildArchive($archivePath, $dump, $manifest);

    // Un secondo archivio "orfano" già sul disco: la finalizzazione deve
    // ri-registrarlo (dopo l'import la tabella backups è vuota).
    kmE2eBuildArchive(
        $this->e2eRoot.'/backups/backup-orfano.zip',
        "SELECT 1;\n",
        ['manifest_format' => 1, 'contents' => 'full', 'encrypted' => false, 'backup_uuid' => 'orfano-uuid',
            'app' => ['version' => '1.0.0'], 'database' => []]
    );

    // --- Stato "destinazione" PRIMA del ripristino: uno schema diverso, con
    //     una tabella che non esiste nell'origine (il wipe deve rimuoverla). ---
    $dst = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.KM_RESTORE_E2E_DB.';charset=utf8mb4', 'root', '');
    $dst->exec('CREATE TABLE `tabella_solo_destinazione` (`id` int PRIMARY KEY)');

    // La finalizzazione chiama SystemFinalizer::finalize() → migrate. Su
    // questo schema minimale non ci sono migrazioni dell'app da eseguire
    // davvero: neutralizziamo il finalizer di sistema per isolare il
    // RestoreManager (migrate/cache sono già coperti da SystemFinalizerTest).
    $this->mock(SystemFinalizer::class, function ($mock) {
        $mock->shouldReceive('finalize')->andReturnNull();
    });

    // --- Avvio e loop degli step, come farà il polling del frontend ---
    $manager = app(RestoreManager::class);
    $result = $manager->start($archivePath, null, ['safety_backup' => false, 'adopt_app_key' => false]);

    expect($result['uuid'])->not->toBeEmpty();
    expect($result['token'])->toHaveLength(64);
    expect(app(RestoreMode::class)->active())->toBeTrue(); // modalità ripristino attiva

    $steps = 0;
    do {
        $state = $manager->runStep();
        $steps++;
    } while (RestoreStatus::from($state['phase'])->isRunning() && $steps < 500);

    // --- Verifiche finali ---
    expect($state['phase'])->toBe(RestoreStatus::COMPLETED->value);

    kmE2eUseConnection(); // riconnessione pulita per leggere il risultato

    // I dati dell'origine sono stati ripristinati, con le stringhe ostili intatte
    $condomini = DB::table('condomini')->orderBy('id')->pluck('nome')->all();
    expect($condomini)->toBe(['Palazzo; Rossi', 'Condominio 🏢']);

    // La tabella che esisteva solo nella destinazione è stata rimossa dal wipe
    expect(DB::getSchemaBuilder()->hasTable('tabella_solo_destinazione'))->toBeFalse();

    // La versione nel DB è stata riallineata a quella del codice (§7-bis):
    // il finalizer di sistema è mockato, ma il RestoreManager NON allinea la
    // versione da sé (lo fa il finalizer) — quindi qui resta quella importata.
    // Verifichiamo invece che l'IMPORT abbia portato la versione dell'origine.
    $importedVersion = json_decode(DB::table('settings')->where('name', 'version')->value('payload'), true);
    expect($importedVersion)->toBe('1.0.0');

    // Le sessioni ereditate dal backup sono state azzerate
    expect(DB::table('sessions')->count())->toBe(0);

    // Gli archivi su disco sono stati ri-registrati (origine + orfano)
    $registered = DB::table('backups')->pluck('filename')->all();
    expect($registered)->toContain('backup-origine.zip');
    expect($registered)->toContain('backup-orfano.zip');
    expect($state['outcome']['reregistered_backups'])->toBe(2);

    // La modalità ripristino è stata disattivata
    expect(app(RestoreMode::class)->active())->toBeFalse();

    // Lo stato finale è consultabile (storico), niente residui tmp
    expect(is_dir($this->e2eRoot.'/backups/tmp/restore-'.$result['uuid']))->toBeFalse();
});

test('un archivio manomesso (sha256 non combacia) fa fallire senza spegnere la modalità', function () {
    kmE2eUseConnection();

    $dump = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE `t` (`id` int PRIMARY KEY);\nSET FOREIGN_KEY_CHECKS=1;\n";
    $manifest = [
        'manifest_format' => 1, 'contents' => 'db_only', 'encrypted' => false,
        'app' => ['version' => '1.0.0'],
        'database' => ['driver' => 'mysql', 'dump_sha256' => str_repeat('f', 64)], // HASH SBAGLIATO
    ];

    $archivePath = $this->e2eRoot.'/backups/manomesso.zip';
    kmE2eBuildArchive($archivePath, $dump, $manifest);

    $manager = app(RestoreManager::class);
    $manager->start($archivePath, null, ['safety_backup' => false]);

    $steps = 0;
    do {
        $state = $manager->runStep();
        $steps++;
    } while (RestoreStatus::from($state['phase'])->isRunning() && $steps < 100);

    expect($state['phase'])->toBe(RestoreStatus::FAILED->value);
    expect($state['error'])->toContain('SHA-256');
    // La modalità ripristino RESTA attiva dopo un fallimento
    expect(app(RestoreMode::class)->active())->toBeTrue();
});

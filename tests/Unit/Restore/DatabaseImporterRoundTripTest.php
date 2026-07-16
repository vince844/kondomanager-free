<?php

use App\Services\Backup\Database\MySqlDumper;
use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\DatabaseImporter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round-trip del DatabaseImporter su MySQL reale: è la productizzazione del
 * round-trip del tokenizer (wipe + import a step riprendibile). Verifica che
 * DatabaseImporter, guidato dalla connessione DEFAULT di Laravel come farà il
 * RestoreManager, ricostruisca fedelmente il database dell'origine.
 *
 * ⚠ Solo schema usa-e-getta km_restore_imp_src/dst (design doc §12).
 */
uses(TestCase::class);

const KM_RESTORE_IMP_SRC = 'km_restore_imp_src';
const KM_RESTORE_IMP_DST = 'km_restore_imp_dst';

function kmImpAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function kmImpDbPdo(string $database): PDO
{
    return new PDO("mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function kmImpUseConnection(string $database): void
{
    config()->set('database.connections.restore_imp_test', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => $database,
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ]);
    config()->set('database.default', 'restore_imp_test');
    DB::purge('restore_imp_test');
    DB::reconnect('restore_imp_test');
}

beforeEach(function () {
    if (kmImpAdminPdo() === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }

    $admin = kmImpAdminPdo();
    $admin->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_IMP_SRC);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_IMP_DST);
    $admin->exec('CREATE DATABASE '.KM_RESTORE_IMP_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('CREATE DATABASE '.KM_RESTORE_IMP_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
});

afterEach(function () {
    $admin = kmImpAdminPdo();
    $admin?->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_IMP_SRC);
    $admin?->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_IMP_DST);
});

test('wipe azzera tabelle e viste della destinazione', function () {
    $dst = kmImpDbPdo(KM_RESTORE_IMP_DST);
    $dst->exec('CREATE TABLE `t1` (`id` int PRIMARY KEY)');
    $dst->exec('CREATE TABLE `t2` (`id` int PRIMARY KEY)');
    $dst->exec('CREATE VIEW `v1` AS SELECT 1 AS n');

    kmImpUseConnection(KM_RESTORE_IMP_DST);
    app(DatabaseImporter::class)->wipe();

    $remaining = kmImpDbPdo(KM_RESTORE_IMP_DST)
        ->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);

    expect($remaining)->toBe([]);
});

test('round-trip: dump origine → wipe+import destinazione a step → dati identici', function () {
    // Origine con dati ostili
    $src = kmImpDbPdo(KM_RESTORE_IMP_SRC);
    $src->exec('CREATE TABLE `movimenti` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `descrizione` varchar(255),
        `importo` decimal(12,2),
        `blob_dati` longblob,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB');
    $ins = $src->prepare('INSERT INTO `movimenti` (`descrizione`, `importo`, `blob_dati`) VALUES (?, ?, ?)');
    $ins->execute(["Rata; con ';' e \nnewline", '1500.00', random_bytes(128)]);
    $ins->execute(['Emoji 🏢 e "virgolette"', '-42.50', null]);
    $src->exec('CREATE VIEW `v_positivi` AS SELECT `id`, `importo` FROM `movimenti` WHERE `importo` > 0');

    // Metti nella destinazione una tabella che NON esiste nell'origine:
    // il wipe deve rimuoverla (altrimenti resterebbe orfana).
    $dst = kmImpDbPdo(KM_RESTORE_IMP_DST);
    $dst->exec('CREATE TABLE `tabella_orfana` (`id` int PRIMARY KEY)');

    // Dump dell'origine
    kmImpUseConnection(KM_RESTORE_IMP_SRC);
    $dumpPath = tempnam(sys_get_temp_dir(), 'km-imp-dump-');
    $dumper = new MySqlDumper;
    $state = [];
    do {
        $done = $dumper->dump($dumpPath, $state, new StepBudget(5.0));
        $state = json_decode((string) json_encode($state), true);
    } while (! $done);

    try {
        // Import nella destinazione, a step da budget minuscolo per forzare
        // la ripresa (checkpoint offset round-trippato in JSON tra gli step).
        kmImpUseConnection(KM_RESTORE_IMP_DST);
        $importer = app(DatabaseImporter::class);
        $importer->wipe();

        $checkpoint = ['offset' => 0, 'delimiter' => ';'];
        $steps = 0;
        do {
            $result = $importer->importStep($dumpPath, $checkpoint, new StepBudget(0.0));
            $checkpoint = json_decode((string) json_encode($result['checkpoint']), true);
            $steps++;
        } while (! $result['done'] && $steps < 10000);

        expect($result['done'])->toBeTrue();
        expect($steps)->toBeGreaterThan(1); // davvero ripreso a più riprese

        // Verifica: dati identici, vista presente, tabella orfana sparita
        $out = kmImpDbPdo(KM_RESTORE_IMP_DST);

        $srcRows = kmImpDbPdo(KM_RESTORE_IMP_SRC)->query('SELECT * FROM `movimenti` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        $dstRows = $out->query('SELECT * FROM `movimenti` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        expect($dstRows)->toBe($srcRows);

        $view = $out->query('SELECT * FROM `v_positivi`')->fetchAll(PDO::FETCH_ASSOC);
        expect($view)->toHaveCount(1);

        $tables = $out->query("SHOW TABLES LIKE 'tabella_orfana'")->fetchAll();
        expect($tables)->toBe([]);
    } finally {
        @unlink($dumpPath);
    }
});

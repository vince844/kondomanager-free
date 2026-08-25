<?php

use App\Services\Backup\Database\MySqlDumper;
use App\Services\Backup\Support\StepBudget;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test round-trip del dumper MySQL: dump → import su un database pulito →
 * confronto dato-per-dato. È il gate di rilascio del motore di backup.
 *
 * Richiede un server MySQL locale (root senza password su 127.0.0.1, come
 * l'ambiente di sviluppo): se non disponibile i test vengono saltati.
 *
 * Vive in tests/Unit con il TestCase Laravel ma SENZA RefreshDatabase:
 * il test cambia la connessione di default a MySQL, cosa incompatibile
 * con la gestione delle transazioni di RefreshDatabase.
 */
uses(TestCase::class);

const KM_BACKUP_TEST_SRC = 'km_backup_test_src';
const KM_BACKUP_TEST_DST = 'km_backup_test_dst';

function mysqlAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function mysqlDbPdo(string $database): PDO
{
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function useMysqlConnection(string $database): void
{
    config()->set('database.connections.backup_test', [
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
    config()->set('database.default', 'backup_test');
    DB::purge('backup_test');
}

/**
 * Esegue il dump a step con budget minuscoli, serializzando lo stato in JSON
 * tra uno step e l'altro: simula esattamente il checkpoint che in produzione
 * passa dalla colonna json della tabella backups.
 */
function runDumpInSteps(string $path, float $budgetSeconds = 0.0): array
{
    $dumper = new MySqlDumper;
    $state = [];
    $steps = 0;

    do {
        $done = $dumper->dump($path, $state, new StepBudget($budgetSeconds));
        $state = json_decode(json_encode($state), true);
        $steps++;
    } while (! $done && $steps < 20000);

    return [$done, $steps, $state];
}

/**
 * Importa il dump in un database: il grosso viene eseguito come blocco
 * multi-statement (il server MySQL fa il parsing, le stringhe con ";" o
 * newline non possono romperlo); l'eventuale blocco DELIMITER dei trigger
 * viene estratto ed eseguito statement per statement.
 */
function importDump(PDO $pdo, string $sql): void
{
    $triggerBlock = null;
    $start = strpos($sql, "DELIMITER ;;\n");

    if ($start !== false) {
        $end = strpos($sql, "DELIMITER ;\n", $start);
        $triggerBlock = substr($sql, $start + strlen("DELIMITER ;;\n"), $end - $start - strlen("DELIMITER ;;\n"));
        $sql = substr($sql, 0, $start).substr($sql, $end + strlen("DELIMITER ;\n"));
    }

    $statement = $pdo->query($sql);

    // Con le query multi-statement gli errori degli statement successivi al
    // primo emergono solo iterando i rowset.
    while ($statement->nextRowset()) {
        // no-op
    }

    $statement->closeCursor();

    if ($triggerBlock !== null) {
        foreach (preg_split('/;;\s*\n/', $triggerBlock) as $triggerStatement) {
            $triggerStatement = trim($triggerStatement);

            if ($triggerStatement !== '') {
                $pdo->exec($triggerStatement);
            }
        }
    }
}

function assertTableDataEqual(PDO $src, PDO $dst, string $table): void
{
    $columns = $src->query(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '.$src->quote($table).'
         ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);

    $orderBy = implode(', ', array_map(fn ($column) => "`{$column}`", $columns));
    $query = "SELECT * FROM `{$table}` ORDER BY {$orderBy}";

    expect($dst->query($query)->fetchAll(PDO::FETCH_NUM))
        ->toEqual($src->query($query)->fetchAll(PDO::FETCH_NUM));
}

afterEach(function () {
    if ($admin = mysqlAdminPdo()) {
        $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
        $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
    }
});

test('round-trip MySQL: schema, dati edge-case, viste e trigger identici dopo dump e import', function () {
    $admin = mysqlAdminPdo();

    if (! $admin) {
        $this->markTestSkipped('Server MySQL locale non disponibile.');
    }

    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $src = mysqlDbPdo(KM_BACKUP_TEST_SRC);

    // Tabella con tutti i tipi problematici
    $src->exec('CREATE TABLE tipi (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        testo TEXT NULL,
        numero DECIMAL(12,4) NULL,
        reale DOUBLE NULL,
        dati BLOB NULL,
        quando DATETIME NULL,
        registrato TIMESTAMP NULL DEFAULT NULL,
        flag TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $insert = $src->prepare('INSERT INTO tipi (testo, numero, reale, dati, quando, registrato, flag) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $binary = random_bytes(64)."\0\0".random_bytes(16);
    $rows = [
        ["l'amministratore \"grande\"", '1234.5678', 0.1, null, '2026-01-15 10:30:00', '2026-01-15 10:30:00', 1],
        ["percorso C:\\condominio\\rossi\ncon newline e tab\t", null, -273.15, $binary, null, null, 0],
        ['emoji 🏢 e accènti àèìòù', '0.0001', null, '', '1999-12-31 23:59:59', '2026-07-01 00:00:00', 1],
        ["stringa con ; punto e virgola;\ne DROP TABLE finto; --", '99999999.9999', 1.5e10, "\x00\x01\x02", '2026-06-30 12:00:00', null, 0],
        [null, null, null, null, null, null, 0],
    ];

    foreach ($rows as $i => $row) {
        $insert->bindValue(1, $row[0]);
        $insert->bindValue(2, $row[1]);
        $insert->bindValue(3, $row[2]);
        $insert->bindValue(4, $row[3], $row[3] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->bindValue(5, $row[4]);
        $insert->bindValue(6, $row[5]);
        $insert->bindValue(7, $row[6]);
        $insert->execute();
    }

    // Tante righe per esercitare chunking e ripresa da checkpoint
    $src->exec('CREATE TABLE molte_righe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, valore VARCHAR(100) NOT NULL)');
    $src->beginTransaction();
    $bulk = $src->prepare('INSERT INTO molte_righe (valore) VALUES (?)');

    for ($i = 1; $i <= 2500; $i++) {
        $bulk->execute(["riga numero {$i} con testo 'quotato'"]);
    }

    $src->commit();

    // Tabella senza chiave primaria (modalità offset)
    $src->exec('CREATE TABLE senza_pk (colonna_a INT NOT NULL, colonna_b VARCHAR(50) NOT NULL)');
    $src->exec("INSERT INTO senza_pk VALUES (1,'uno'),(2,'due'),(3,'tre'),(4,'quattro'),(5,'cinque')");

    // Chiave primaria composta (modalità offset con ORDER BY)
    $src->exec('CREATE TABLE pk_composta (a INT NOT NULL, b INT NOT NULL, etichetta VARCHAR(20) NULL, PRIMARY KEY (a, b))');

    for ($a = 1; $a <= 3; $a++) {
        for ($b = 1; $b <= 4; $b++) {
            $src->exec("INSERT INTO pk_composta VALUES ({$a}, {$b}, 'cella {$a}-{$b}')");
        }
    }

    // Colonna generata: non va reinserita, viene ricalcolata all'import
    $src->exec('CREATE TABLE generata (id INT NOT NULL PRIMARY KEY, base INT NOT NULL, doppio INT AS (base * 2) STORED)');
    $src->exec('INSERT INTO generata (id, base) VALUES (1, 21), (2, 100)');

    // La tabella 'backups' è in config backup.dump_exclude_data: la struttura
    // viaggia nel dump, i dati (checkpoint con password cifrate) no
    $src->exec('CREATE TABLE backups (id INT NOT NULL PRIMARY KEY, checkpoint TEXT NULL)');
    $src->exec("INSERT INTO backups VALUES (1, 'segreto-che-non-deve-viaggiare')");

    // Vista (con DEFINER da rimuovere) e trigger
    $src->exec('CREATE VIEW vista_tipi AS SELECT id, testo FROM tipi WHERE flag = 1');
    $src->exec('CREATE TABLE contatore (n INT NOT NULL)');
    $src->exec('INSERT INTO contatore VALUES (0)');
    $src->exec('CREATE TRIGGER trg_senza_pk AFTER INSERT ON senza_pk FOR EACH ROW UPDATE contatore SET n = n + 1');

    // Dump a step minuscoli con chunk piccoli e INSERT corti
    useMysqlConnection(KM_BACKUP_TEST_SRC);
    config()->set('backup.db_chunk_rows', 100);
    config()->set('backup.insert_max_bytes', 8 * 1024);

    $path = tempnam(sys_get_temp_dir(), 'km_dump_').'.sql';
    [$done, $steps] = runDumpInSteps($path);

    expect($done)->toBeTrue();
    expect($steps)->toBeGreaterThan(3);

    $sql = file_get_contents($path);
    expect($sql)->not->toContain('DEFINER');

    // Import nel database di destinazione pulito
    $dst = mysqlDbPdo(KM_BACKUP_TEST_DST);
    importDump($dst, $sql);

    // Stesse tabelle e viste
    $listTables = fn (PDO $pdo) => $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $srcTables = $listTables($src);
    $dstTables = $listTables($dst);
    sort($srcTables);
    sort($dstTables);
    expect($dstTables)->toEqual($srcTables);

    // Dati identici tabella per tabella
    foreach (['tipi', 'molte_righe', 'senza_pk', 'pk_composta', 'generata', 'contatore'] as $table) {
        assertTableDataEqual($src, $dst, $table);
    }

    // La tabella 'backups' arriva con la struttura ma SENZA dati
    expect((int) $dst->query('SELECT COUNT(*) FROM backups')->fetchColumn())->toBe(0);
    expect($sql)->not->toContain('segreto-che-non-deve-viaggiare');

    // La vista funziona e restituisce gli stessi dati
    expect($dst->query('SELECT * FROM vista_tipi ORDER BY id')->fetchAll(PDO::FETCH_NUM))
        ->toEqual($src->query('SELECT * FROM vista_tipi ORDER BY id')->fetchAll(PDO::FETCH_NUM));

    // Il trigger esiste e funziona nel database ripristinato
    expect($dst->query('SHOW TRIGGERS')->fetchAll())->toHaveCount(1);
    $dst->exec("INSERT INTO senza_pk VALUES (6, 'sei')");
    expect((int) $dst->query('SELECT n FROM contatore')->fetchColumn())->toBe(1);

    // AUTO_INCREMENT preservato: un nuovo inserimento non collide
    $dst->exec("INSERT INTO tipi (testo) VALUES ('nuova riga')");
    expect((int) $dst->query('SELECT MAX(id) FROM tipi')->fetchColumn())->toBeGreaterThan(5);

    @unlink($path);
});

test('round-trip MySQL: il database di sviluppo reale si reimporta con gli stessi conteggi', function () {
    $admin = mysqlAdminPdo();

    if (! $admin) {
        $this->markTestSkipped('Server MySQL locale non disponibile.');
    }

    $devDatabase = 'kondomanager-free';
    $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$devDatabase]);

    if ((int) $exists->fetchColumn() === 0) {
        $this->markTestSkipped('Database di sviluppo non presente.');
    }

    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    useMysqlConnection($devDatabase);

    $path = tempnam(sys_get_temp_dir(), 'km_dump_real_').'.sql';
    [$done, $steps] = runDumpInSteps($path, 2.0);

    expect($done)->toBeTrue();

    $dst = mysqlDbPdo(KM_BACKUP_TEST_DST);
    importDump($dst, file_get_contents($path));

    $src = mysqlDbPdo($devDatabase);

    $baseTables = $src->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    // Tabelle che l'applicazione può toccare mentre il test gira (es. il dev
    // server attivo scrive sessioni/cache/job): escluse dal confronto per non
    // rendere il test intermittente.
    $volatile = ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'mail_logs', 'backups'];

    foreach ($baseTables as $table) {
        if (in_array($table, $volatile, true)) {
            continue;
        }

        $count = fn (PDO $pdo) => (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

        expect($count($dst))->toBe($count($src));
    }

    @unlink($path);
});

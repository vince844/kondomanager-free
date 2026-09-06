<?php

use App\Services\Backup\Database\MySqlDumper;
use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\SqlDumpTokenizer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round-trip del TOKENIZER su MySQL reale: dump generato da MySqlDumper →
 * import statement-per-statement col tokenizer (a batch, con connessioni
 * NUOVE tra i batch e preambolo di sessione ri-emesso, esattamente come
 * farà il RestoreManager tra uno step HTTP e l'altro) → confronto
 * dato-per-dato con l'origine.
 *
 * È il gate della Fase 1 del ripristino (beta.13): se questo test è verde,
 * l'import riprendibile è affidabile quanto l'import multi-statement dei
 * test round-trip del dumper.
 *
 * ⚠ Usa ESCLUSIVAMENTE gli schemi usa-e-getta km_restore_tok_src/dst
 * (regola del design doc §12: mai il database di sviluppo condiviso).
 * Richiede MySQL locale root senza password; altrimenti skip.
 */
uses(TestCase::class);

// I database di prova portano un suffisso unico per checkout: senza, lanciare la suite
// in TEST e in ufficiale insieme fa sfilare il database all'altra esecuzione. Vedi
// `kmDatabaseDiProva()` in tests/Pest.php.
defined('KM_RESTORE_TOK_SRC') || define('KM_RESTORE_TOK_SRC', kmDatabaseDiProva('km_restore_tok_src'));
defined('KM_RESTORE_TOK_DST') || define('KM_RESTORE_TOK_DST', kmDatabaseDiProva('km_restore_tok_dst'));

/**
 * Preambolo di sessione che il RestoreManager ri-emetterà a ogni step
 * (ogni richiesta HTTP = connessione MySQL nuova): replica l'intestazione
 * del dump, che vale solo per la sessione che l'ha eseguita.
 */
const KM_RESTORE_SESSION_PREAMBLE = [
    'SET NAMES utf8mb4',
    'SET FOREIGN_KEY_CHECKS=0',
    'SET UNIQUE_CHECKS=0',
    "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'",
    "SET time_zone='+00:00'",
];

function kmRestoreTokAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function kmRestoreTokDbPdo(string $database): PDO
{
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function kmRestoreTokUseConnection(string $database): void
{
    config()->set('database.connections.restore_tok_test', [
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
    config()->set('database.default', 'restore_tok_test');
    DB::purge('restore_tok_test');
}

function kmRestoreTokDropSchemas(): void
{
    $admin = kmRestoreTokAdminPdo();
    $admin?->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_TOK_SRC);
    $admin?->exec('DROP DATABASE IF EXISTS '.KM_RESTORE_TOK_DST);
}

/**
 * Importa il dump nel database indicato usando SOLO il tokenizer, a batch
 * di pochi statement: ogni batch apre una connessione PDO nuova, ri-emette
 * il preambolo di sessione e riparte dal checkpoint (offset + delimiter)
 * serializzato/deserializzato in JSON — la simulazione fedele degli step
 * HTTP del futuro RestoreManager.
 */
function kmRestoreTokImportInBatches(string $dumpPath, string $database, int $batchSize = 3): int
{
    $tokenizer = new SqlDumpTokenizer($dumpPath);
    $checkpoint = ['offset' => 0, 'delimiter' => ';'];
    $executed = 0;

    while (true) {
        // Round-trip JSON del checkpoint, come farà lo state file su disco
        $checkpoint = json_decode((string) json_encode($checkpoint), true);

        $pdo = kmRestoreTokDbPdo($database);
        foreach (KM_RESTORE_SESSION_PREAMBLE as $set) {
            $pdo->exec($set);
        }

        $inBatch = 0;

        foreach ($tokenizer->statements($checkpoint['offset'], $checkpoint['delimiter']) as $statement) {
            $pdo->exec($statement['sql']);
            $executed++;
            $inBatch++;

            $checkpoint = [
                'offset' => $statement['nextOffset'],
                'delimiter' => $statement['delimiter'],
            ];

            if ($inBatch >= $batchSize) {
                break; // budget del "passo" esaurito: connessione nuova al giro dopo
            }
        }

        if ($inBatch === 0) {
            return $executed; // generatore esaurito: import completato
        }

        $pdo = null; // chiusura esplicita della connessione del batch
    }
}

function kmRestoreTokTableData(PDO $pdo, string $table): array
{
    $columns = $pdo->query(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '.$pdo->quote($table).'
         ORDER BY ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_COLUMN);

    $orderBy = implode(', ', array_map(fn ($column) => "`{$column}`", $columns));

    return $pdo->query("SELECT * FROM `{$table}` ORDER BY {$orderBy}")->fetchAll(PDO::FETCH_ASSOC);
}

beforeEach(function () {
    if (kmRestoreTokAdminPdo() === null) {
        $this->markTestSkipped('MySQL locale non disponibile (127.0.0.1, root senza password).');
    }

    kmRestoreTokDropSchemas();

    $admin = kmRestoreTokAdminPdo();
    $admin->exec('CREATE DATABASE '.KM_RESTORE_TOK_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('CREATE DATABASE '.KM_RESTORE_TOK_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
});

afterEach(function () {
    kmRestoreTokDropSchemas();
});

test('import col tokenizer: round-trip completo con dati ostili, viste e trigger', function () {
    $src = kmRestoreTokDbPdo(KM_RESTORE_TOK_SRC);

    // --- Fixture ostile: gli stessi nemici del round-trip del dumper ---
    $src->exec('CREATE TABLE `documenti` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `titolo` varchar(255) NOT NULL,
        `contenuto` longtext,
        `allegato` longblob,
        `importo` decimal(12,2) DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB');

    $insert = $src->prepare('INSERT INTO `documenti` (`titolo`, `contenuto`, `allegato`, `importo`) VALUES (?, ?, ?, ?)');
    $insert->execute(['Verbale; con punto e virgola', "riga1\nriga2 con 'apici' e \\ backslash", random_bytes(256), '1234.56']);
    $insert->execute(['Emoji 🏠 e "doppi apici"', str_repeat('x;', 500), null, '-99.01']);
    $insert->execute(["Chiusura '); DROP TABLE finta; --", 'contenuto con ;; doppio', random_bytes(64), null]);

    // Tabella senza PK (percorso OFFSET del dumper)
    $src->exec('CREATE TABLE `senza_pk` (`nota` varchar(50), `valore` int)');
    $src->exec("INSERT INTO `senza_pk` VALUES ('a;b', 1), ('c\\nd', 2), ('e', 3)");

    // PK composta
    $src->exec('CREATE TABLE `pivot_test` (`a_id` int NOT NULL, `b_id` int NOT NULL, `extra` varchar(20), PRIMARY KEY (`a_id`, `b_id`))');
    $src->exec("INSERT INTO `pivot_test` VALUES (1, 1, 'x'), (1, 2, 'y;z'), (2, 1, NULL)");

    // Vista (il dump la scrive come stub + CREATE VIEW)
    $src->exec('CREATE VIEW `v_documenti_importi` AS SELECT `id`, `importo` FROM `documenti` WHERE `importo` IS NOT NULL');

    // Trigger con più statement e ';' nelle stringhe del body
    $src->exec('CREATE TABLE `log_eventi` (`id` int NOT NULL AUTO_INCREMENT, `messaggio` varchar(200), PRIMARY KEY (`id`))');
    $src->exec("CREATE TRIGGER `traccia_documenti` AFTER INSERT ON `documenti` FOR EACH ROW BEGIN
        INSERT INTO `log_eventi` (`messaggio`) VALUES (CONCAT('nuovo doc; id=', NEW.`id`));
        INSERT INTO `log_eventi` (`messaggio`) VALUES ('secondo insert; del trigger');
    END");

    // --- Dump con il motore reale, a step minuscoli ---
    kmRestoreTokUseConnection(KM_RESTORE_TOK_SRC);

    $dumpPath = tempnam(sys_get_temp_dir(), 'km-restore-dump-');
    $dumper = new MySqlDumper;
    $state = [];
    $steps = 0;

    do {
        $done = $dumper->dump($dumpPath, $state, new StepBudget(0.0));
        $state = json_decode((string) json_encode($state), true);
        $steps++;
    } while (! $done && $steps < 20000);

    expect($done)->toBeTrue();

    try {
        // --- Import nel database di destinazione: SOLO tokenizer, a batch ---
        $executed = kmRestoreTokImportInBatches($dumpPath, KM_RESTORE_TOK_DST, 3);

        expect($executed)->toBeGreaterThan(10);

        $srcPdo = kmRestoreTokDbPdo(KM_RESTORE_TOK_SRC);
        $dstPdo = kmRestoreTokDbPdo(KM_RESTORE_TOK_DST);

        // Stessi dati, tabella per tabella
        foreach (['documenti', 'senza_pk', 'pivot_test', 'log_eventi'] as $table) {
            expect(kmRestoreTokTableData($dstPdo, $table))
                ->toBe(kmRestoreTokTableData($srcPdo, $table), "Dati divergenti nella tabella {$table}");
        }

        // La vista esiste e restituisce gli stessi risultati
        $viewSrc = $srcPdo->query('SELECT * FROM `v_documenti_importi` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        $viewDst = $dstPdo->query('SELECT * FROM `v_documenti_importi` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        expect($viewDst)->toBe($viewSrc);

        // Il trigger è stato ricreato e SCATTA nella destinazione
        $logPrima = (int) $dstPdo->query('SELECT COUNT(*) FROM `log_eventi`')->fetchColumn();
        $dstPdo->exec("INSERT INTO `documenti` (`titolo`) VALUES ('doc post-ripristino')");
        $logDopo = (int) $dstPdo->query('SELECT COUNT(*) FROM `log_eventi`')->fetchColumn();
        expect($logDopo)->toBe($logPrima + 2);

        // AUTO_INCREMENT coerente: un nuovo insert non collide con gli id importati
        $maxId = (int) $dstPdo->query('SELECT MAX(`id`) FROM `documenti`')->fetchColumn();
        expect($maxId)->toBeGreaterThan(3);
    } finally {
        @unlink($dumpPath);
    }
});

test('import col tokenizer: riprendibile con batch da UN solo statement', function () {
    $src = kmRestoreTokDbPdo(KM_RESTORE_TOK_SRC);

    $src->exec('CREATE TABLE `conti` (`id` int NOT NULL AUTO_INCREMENT, `saldo` decimal(10,2), PRIMARY KEY (`id`))');
    $src->exec('INSERT INTO `conti` (`saldo`) VALUES (10.50), (20.00), (-3.25)');
    $src->exec('CREATE TRIGGER `blocca_negativi` BEFORE UPDATE ON `conti` FOR EACH ROW BEGIN
        SET NEW.`saldo` = GREATEST(NEW.`saldo`, 0);
    END');

    kmRestoreTokUseConnection(KM_RESTORE_TOK_SRC);

    $dumpPath = tempnam(sys_get_temp_dir(), 'km-restore-dump-');
    $dumper = new MySqlDumper;
    $state = [];

    do {
        $done = $dumper->dump($dumpPath, $state, new StepBudget(5.0));
        $state = json_decode((string) json_encode($state), true);
    } while (! $done);

    try {
        // Il caso estremo: connessione NUOVA per OGNI statement
        kmRestoreTokImportInBatches($dumpPath, KM_RESTORE_TOK_DST, 1);

        $dstPdo = kmRestoreTokDbPdo(KM_RESTORE_TOK_DST);

        expect(kmRestoreTokTableData($dstPdo, 'conti'))
            ->toBe(kmRestoreTokTableData(kmRestoreTokDbPdo(KM_RESTORE_TOK_SRC), 'conti'));

        // Il trigger funziona: un update negativo viene azzerato
        $dstPdo->exec('UPDATE `conti` SET `saldo` = -50 WHERE `id` = 1');
        expect($dstPdo->query('SELECT `saldo` FROM `conti` WHERE `id` = 1')->fetchColumn())->toBe('0.00');
    } finally {
        @unlink($dumpPath);
    }
});

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

// I database di prova portano un suffisso unico per checkout: senza, lanciare la suite
// in TEST e in ufficiale insieme fa sfilare il database all'altra esecuzione. Vedi
// `kmDatabaseDiProva()` in tests/Pest.php.
defined('KM_BACKUP_TEST_SRC') || define('KM_BACKUP_TEST_SRC', kmDatabaseDiProva('km_backup_test_src'));
defined('KM_BACKUP_TEST_DST') || define('KM_BACKUP_TEST_DST', kmDatabaseDiProva('km_backup_test_dst'));

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

/**
 * Uno step ucciso a metà non deve gonfiare il dump.
 *
 * ## Il caso, misurato il 27/08/2026
 *
 * `BackupManager::advanceDump()` salva il checkpoint **dopo** che `dump()` è tornato. Se il
 * processo muore prima — timeout di PHP, `request_terminate_timeout` di php-fpm, riavvio, memoria
 * esaurita — tutto ciò che quello step ha scritto è nel file e in **nessun** checkpoint. Il passo
 * successivo riprende dall'ultimo checkpoint buono e riscrive lo stesso segmento.
 *
 * Riprodotto sul database di sviluppo prima della correzione: il dump valeva **2.865.272** byte
 * invece di 2.333.722, e `mysql <` moriva con `ERROR 1062 Duplicate entry … for key
 * 'cache_locks.PRIMARY'`. Un archivio che si dichiara completato e non si reimporta non è una rete.
 *
 * La correzione apre il file in `r+b` e lo tronca a `$state['bytes_written']`, così checkpoint e
 * file dicono sempre la stessa cosa.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non uccide un processo vero.** Simula la morte scartando lo stato che `dump()` ha
 *   restituito, che è esattamente ciò che il database vedrebbe. Un `kill -9` in mezzo a una
 *   `fwrite` può lasciare un blocco parziale che questo test non produce.
 * - **Non copre SQLite.** `SqliteDumper` usa `VACUUM INTO` e non ha né step né checkpoint: il caso
 *   non esiste lì, ma non è questo test a dirlo.
 * - **Non prova che il checkpoint sia salvato dal manager.** Prova il dumper. Che
 *   `advanceDump()` persista `bytes_written` insieme al resto dello stato è coperto dal fatto che
 *   salva `$state` intero, non da un'asserzione qui.
 */
test('uno step ucciso non duplica il segmento già scritto, e il dump resta reimportabile', function () {
    $admin = mysqlAdminPdo();

    if (! $admin) {
        $this->markTestSkipped('Server MySQL locale non disponibile.');
    }

    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $src = mysqlDbPdo(KM_BACKUP_TEST_SRC);

    for ($t = 1; $t <= 6; $t++) {
        $src->exec("CREATE TABLE t{$t} (id INT AUTO_INCREMENT PRIMARY KEY, testo VARCHAR(120), chiave VARCHAR(60) UNIQUE)");
        for ($r = 1; $r <= 40; $r++) {
            $src->exec("INSERT INTO t{$t} (testo, chiave) VALUES ('riga {$r} della tabella {$t}', 't{$t}_r{$r}')");
        }
    }

    useMysqlConnection(KM_BACKUP_TEST_SRC);

    $dumper = new MySqlDumper;

    // Riferimento: lo stesso dump senza interruzioni.
    $riferimento = tempnam(sys_get_temp_dir(), 'km_rif_').'.sql';
    @unlink($riferimento);
    [$fatto] = runDumpInSteps($riferimento, 0.0);
    expect($fatto)->toBeTrue();

    // Lo stesso dump, con uno step ucciso. Il budget a 0.0 rende il tutto
    // DETERMINISTICO: `exceeded()` è vero subito, quindi ogni chiamata a `dump()`
    // fa esattamente un'operazione. Niente dipende dalla velocità della macchina.
    $percorso = tempnam(sys_get_temp_dir(), 'km_ucciso_').'.sql';
    @unlink($percorso);

    $passo = fn (array $stato): array => (function () use ($dumper, $percorso, $stato) {
        $concluso = $dumper->dump($percorso, $stato, new StepBudget(0.0));

        return [json_decode(json_encode($stato), true), $concluso];
    })();

    // Tre operazioni buone: il checkpoint le registra.
    $stato = [];
    for ($i = 0; $i < 3; $i++) {
        [$stato] = $passo($stato);
    }

    $checkpointBuono = $stato;
    $byteRegistrati = filesize($percorso);

    // Due operazioni che il processo NON fa in tempo a registrare: muore prima.
    // Il file le riceve, il checkpoint no.
    $statoPerso = $checkpointBuono;
    for ($i = 0; $i < 2; $i++) {
        [$statoPerso] = $passo($statoPerso);
    }

    expect(filesize($percorso))->toBeGreaterThan(
        $byteRegistrati,
        'Lo step "ucciso" non ha scritto niente: lo scenario non si è riprodotto e il test non prova nulla.'
    );

    // Si riprende dal checkpoint buono, come farebbe il passo successivo.
    $stato = $checkpointBuono;
    $giri = 0;

    do {
        [$stato, $concluso] = $passo($stato);
        $giri++;
    } while (! $concluso && $giri < 20000);

    expect($concluso)->toBeTrue('Il dump non è arrivato in fondo dopo lo step ucciso.');

    // 1. Nessun segmento riscritto: il file coincide con quello mai interrotto.
    expect(filesize($percorso))->toBe(
        filesize($riferimento),
        sprintf(
            'Il dump con lo step ucciso vale %d byte contro i %d del riferimento: il segmento '.
            'scritto e non registrato è stato riscritto.',
            filesize($percorso),
            filesize($riferimento)
        )
    );

    // 2. E si reimporta. È la prova che conta: prima moriva con ERROR 1062.
    $dst = mysqlDbPdo(KM_BACKUP_TEST_DST);
    importDump($dst, file_get_contents($percorso));

    for ($t = 1; $t <= 6; $t++) {
        assertTableDataEqual($src, $dst, "t{$t}");
    }

    @unlink($percorso);
    @unlink($riferimento);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
});

/**
 * Un checkpoint scritto da una versione precedente non deve far troncare il dump a zero.
 *
 * ## Il caso, trovato dalla Fase 1-bis della beta.2 e misurato
 *
 * `bytes_written` nasce nella 1.11.0-beta.2: ogni checkpoint prodotto da una 1.10.x o dalla
 * beta.1 ne è privo. Con un `?? 0` quel checkpoint faceva troncare il file a zero e proseguire
 * dallo `stage` di metà percorso — un dump senza intestazione e senza le prime tabelle, che però
 * conteneva «Dump completato»: `dump()` restituiva `true`, il backup veniva marcato COMPLETED con
 * checksum e manifest, e il ripristino moriva.
 *
 * Misurato sul database di sviluppo prima della correzione: **68 tabelle su 85**, intestazione
 * assente, file dichiarato completo. **Era un peggioramento** rispetto al difetto che la beta
 * stava correggendo: la duplicazione moriva rumorosamente con `ERROR 1062`, questo perdeva
 * tabelle in silenzio.
 *
 * Non è teorico: `storage` è escluso dal deploy dell'aggiornatore, quindi un dump interrotto
 * sopravvive alla sostituzione dei file; il record resta «running» per due ore; e
 * `SystemUpgradeController::backupStart()` riusa esplicitamente un backup in corso. Cioè capitava
 * proprio alla rete di sicurezza pre-aggiornamento.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non prova che ricominciare sia la scelta migliore** fra le due possibili (l'altra era
 *   fallire rumorosamente). È una decisione di prodotto: sul percorso pre-aggiornamento fallire
 *   significherebbe lasciare l'aggiornamento senza rete.
 * - **Non copre un checkpoint corrotto in altri modi** — uno `stage` sconosciuto, un
 *   `data_index` fuori intervallo. Qui si simula la sola forma prodotta dalla versione
 *   precedente, che è l'assenza della chiave.
 */
test('un checkpoint senza bytes_written fa ricominciare il dump invece di troncarlo a zero', function () {
    $admin = mysqlAdminPdo();

    if (! $admin) {
        $this->markTestSkipped('Server MySQL locale non disponibile.');
    }

    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_DST.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $src = mysqlDbPdo(KM_BACKUP_TEST_SRC);

    for ($t = 1; $t <= 6; $t++) {
        $src->exec("CREATE TABLE t{$t} (id INT AUTO_INCREMENT PRIMARY KEY, testo VARCHAR(120), chiave VARCHAR(60) UNIQUE)");
        for ($r = 1; $r <= 40; $r++) {
            $src->exec("INSERT INTO t{$t} (testo, chiave) VALUES ('riga {$r} tabella {$t}', 't{$t}_r{$r}')");
        }
    }

    useMysqlConnection(KM_BACKUP_TEST_SRC);

    $dumper = new MySqlDumper;

    $riferimento = tempnam(sys_get_temp_dir(), 'km_rif_').'.sql';
    @unlink($riferimento);
    [$fatto] = runDumpInSteps($riferimento, 0.0);
    expect($fatto)->toBeTrue();

    // Alcune operazioni, poi si toglie la chiave: è la forma esatta del checkpoint
    // che scriveva la versione precedente.
    $percorso = tempnam(sys_get_temp_dir(), 'km_vecchio_').'.sql';
    @unlink($percorso);

    $stato = [];
    for ($i = 0; $i < 4; $i++) {
        $dumper->dump($percorso, $stato, new StepBudget(0.0));
        $stato = json_decode(json_encode($stato), true);
    }

    expect($stato)->toHaveKey('bytes_written')
        ->and($stato['stage'] ?? null)->not->toBe('init', 'Il dump non è avanzato: lo scenario non si è riprodotto.');

    unset($stato['bytes_written']);

    $giri = 0;

    do {
        $concluso = $dumper->dump($percorso, $stato, new StepBudget(0.0));
        $stato = json_decode(json_encode($stato), true);
        $giri++;
    } while (! $concluso && $giri < 20000);

    expect($concluso)->toBeTrue();

    // 1. Il file coincide con un dump mai interrotto: si è ricominciato, non troncato a metà.
    expect(filesize($percorso))->toBe(
        filesize($riferimento),
        sprintf(
            'Il dump ripreso da un checkpoint senza bytes_written vale %d byte contro i %d di uno '.
            'pulito: il file è stato troncato e la ripresa è partita da metà.',
            filesize($percorso),
            filesize($riferimento)
        )
    );

    // 2. L'intestazione c'è. Era la prima cosa che spariva, e senza di lei l'import muore
    //    sui vincoli di chiave esterna anche quando tutte le tabelle ci sono.
    expect(file_get_contents($percorso))->toContain('SET FOREIGN_KEY_CHECKS=0');

    // 3. E si reimporta, con tutti i dati.
    $dst = mysqlDbPdo(KM_BACKUP_TEST_DST);
    importDump($dst, file_get_contents($percorso));

    for ($t = 1; $t <= 6; $t++) {
        assertTableDataEqual($src, $dst, "t{$t}");
    }

    @unlink($percorso);
    @unlink($riferimento);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_DST);
});

/**
 * Il troncamento serve a togliere la **coda** che la ripresa non riscrive.
 *
 * ## Perché questo test esiste separato dall'altro
 *
 * La Fase 1-bis della beta.2 ha trovato che il test «uno step ucciso» resta verde anche togliendo
 * `ftruncate` e lasciando solo il `fseek`: la ripresa riscrive sopra gli stessi byte, quindi il
 * file finisce comunque della lunghezza giusta. Cioè il difetto della duplicazione lo corregge il
 * riposizionamento, non il troncamento — e il troncamento restava **non provato**.
 *
 * Il caso che protegge è reale ma più stretto: quando oltre il punto in cui la ripresa termina
 * resta della coda che nessuno riscrive. Succede se fra lo step ucciso e la ripresa il contenuto
 * cambia — righe cancellate, una tabella svuotata — cosa che su un'installazione viva capita,
 * perché il dump non blocca il database.
 *
 * Qui la coda viene scritta a mano: è il modo deterministico di riprodurre quella forma senza
 * dipendere da una corsa fra due processi.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non riproduce la causa**, solo la forma. Che una riga cancellata a metà dump produca una
 *   coda più corta è una deduzione: qui si simula il risultato.
 * - **Non copre il fallimento di `ftruncate`** su un filesystem che non lo supporta. Il codice in
 *   quel caso solleva un'eccezione e il backup fallisce rumorosamente, che è la scelta giusta, ma
 *   non c'è un filesystem simile a portata di test.
 */
test('la ripresa toglie la coda rimasta oltre il punto in cui il dump ricomincia', function () {
    $admin = mysqlAdminPdo();

    if (! $admin) {
        $this->markTestSkipped('Server MySQL locale non disponibile.');
    }

    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
    $admin->exec('CREATE DATABASE '.KM_BACKUP_TEST_SRC.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $src = mysqlDbPdo(KM_BACKUP_TEST_SRC);
    $src->exec('CREATE TABLE t1 (id INT AUTO_INCREMENT PRIMARY KEY, testo VARCHAR(120))');

    for ($r = 1; $r <= 30; $r++) {
        $src->exec("INSERT INTO t1 (testo) VALUES ('riga {$r}')");
    }

    useMysqlConnection(KM_BACKUP_TEST_SRC);

    $dumper = new MySqlDumper;

    $riferimento = tempnam(sys_get_temp_dir(), 'km_rif_').'.sql';
    @unlink($riferimento);
    [$fatto] = runDumpInSteps($riferimento, 0.0);
    expect($fatto)->toBeTrue();

    $percorso = tempnam(sys_get_temp_dir(), 'km_coda_').'.sql';
    @unlink($percorso);

    $stato = [];
    for ($i = 0; $i < 3; $i++) {
        $dumper->dump($percorso, $stato, new StepBudget(0.0));
        $stato = json_decode(json_encode($stato), true);
    }

    // La coda: byte che stanno nel file oltre quanto il checkpoint dichiara, e che la ripresa
    // non riscriverà perché finisce prima. Senza troncamento restano dentro l'archivio.
    $coda = str_repeat("-- coda che nessuno riscrivera'
", 40);
    file_put_contents($percorso, $coda, FILE_APPEND);

    expect(filesize($percorso))->toBeGreaterThan(
        $stato['bytes_written'],
        'La coda non è stata scritta: lo scenario non si è riprodotto.'
    );

    $giri = 0;

    do {
        $concluso = $dumper->dump($percorso, $stato, new StepBudget(0.0));
        $stato = json_decode(json_encode($stato), true);
        $giri++;
    } while (! $concluso && $giri < 20000);

    expect($concluso)->toBeTrue();

    expect(file_get_contents($percorso))->not->toContain(
        "coda che nessuno riscrivera'",
        'La coda è sopravvissuta alla ripresa: il file non è stato troncato alla lunghezza del checkpoint.'
    );

    expect(filesize($percorso))->toBe(filesize($riferimento));

    @unlink($percorso);
    @unlink($riferimento);
    $admin->exec('DROP DATABASE IF EXISTS '.KM_BACKUP_TEST_SRC);
});

<?php

namespace App\Services\Backup\Database;

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Services\Backup\Support\StepBudget;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Dump MySQL/MariaDB in puro PHP via PDO.
 *
 * Nessun binario esterno (mysqldump) e nessun proc_open: è il requisito
 * degli hosting condivisi su cui gira KondoManager. Il dump è riprendibile:
 * lo stato (tabella corrente, ultima chiave primaria, offset) vive nel
 * checkpoint e ogni step scrive in append sul file di destinazione.
 *
 * L'SQL prodotto è pensato per essere reimportato ovunque, incluso
 * phpMyAdmin: nessun DEFINER, statement INSERT sotto 1MB, valori binari
 * in esadecimale, FOREIGN_KEY_CHECKS disattivati in testa.
 *
 * Nota: il dump è "a caldo" e non transazionale tra uno step e l'altro
 * (ogni richiesta HTTP apre una connessione nuova). La UI raccomanda di
 * eseguire il backup quando non ci sono operazioni in corso.
 */
class MySqlDumper implements DatabaseDumperInterface
{
    private const BINARY_TYPES = [
        'binary', 'varbinary', 'bit',
        'blob', 'tinyblob', 'mediumblob', 'longblob',
        'geometry', 'point', 'linestring', 'polygon',
        'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    public function dumpFilename(): string
    {
        return 'database.sql';
    }

    public function dump(string $targetPath, array &$state, StepBudget $budget): bool
    {
        $pdo = DB::connection()->getPdo();

        // Ogni step usa una connessione nuova: la time zone di sessione va
        // riallineata a UTC ad ogni chiamata, così i valori TIMESTAMP vengono
        // letti e reimportati senza slittamenti (stesso approccio di mysqldump).
        $pdo->exec("SET time_zone = '+00:00'");

        // ⚠️ `r+b` e non `ab`, per poter troncare: alla ripresa il file va riportato
        // a **quanto il checkpoint sa di avere scritto**, non lasciato com'è.
        //
        // Il checkpoint è salvato da `BackupManager::advanceDump()` **dopo** che questo
        // metodo è tornato. Se il processo muore prima — timeout di PHP, kill del worker,
        // memoria esaurita — tutto ciò che è stato scritto in quello step è nel file e in
        // nessun checkpoint. Il passo successivo riprende dall'ultimo checkpoint buono e
        // **riscrive lo stesso segmento**: il dump contiene due volte le stesse istruzioni
        // e non si reimporta più.
        //
        // Misurato il 27/08/2026 riproducendolo: passo 1 registra 273 byte, il passo
        // ucciso ne scrive 12.917 che nessuno registra, la ripresa li riscrive. Il file
        // finale valeva 2.865.272 byte invece di 2.333.722, e `mysql <` moriva con
        // `ERROR 1062 Duplicate entry ... for key 'cache_locks.PRIMARY'`.
        //
        // È il difetto che colpisce **le macchine deboli**, cioè quelle per cui lo
        // step-runner esiste: dove il dump sta in un solo step non si riprende mai.
        $out = @fopen($targetPath, file_exists($targetPath) ? 'r+b' : 'w+b');
        if ($out === false) {
            throw new RuntimeException("Impossibile aprire il file di dump {$targetPath} in scrittura.");
        }

        // ⚠️ «Assente» e «zero» non sono la stessa cosa, e confonderli costa un archivio.
        //
        // `bytes_written` nasce in questa versione: ogni checkpoint scritto da una precedente ne è
        // privo. Con un `?? 0` quel checkpoint faceva troncare il file a zero e proseguire dallo
        // `stage` di metà percorso — producendo un dump senza intestazione e senza le prime tabelle
        // che però contiene «Dump completato», quindi `dump()` restituiva `true` e il backup veniva
        // marcato COMPLETED, con checksum e manifest. Misurato sul database di sviluppo: **68
        // tabelle su 85**, e il ripristino muore.
        //
        // Non è un caso di laboratorio: `storage` è escluso dal deploy dell'aggiornatore, quindi un
        // dump interrotto sopravvive alla sostituzione dei file, il record resta «running» per due
        // ore, e `SystemUpgradeController::backupStart()` **riusa esplicitamente** un backup in
        // corso. Cioè capita proprio alla rete di sicurezza pre-aggiornamento.
        //
        // Quando il checkpoint non conosce la chiave si **ricomincia da capo**: è l'unico stato in
        // cui checkpoint e file tornano a coincidere con certezza. Costa il tempo di un dump; le
        // alternative costano un archivio che mente. Non si solleva un'eccezione perché su quel
        // percorso significherebbe lasciare l'aggiornamento senza rete.
        if ($state !== [] && ! array_key_exists('bytes_written', $state)) {
            $state = [];
        }

        $scrittiSecondoIlCheckpoint = (int) ($state['bytes_written'] ?? 0);

        if (ftruncate($out, $scrittiSecondoIlCheckpoint) === false) {
            fclose($out);

            throw new RuntimeException("Impossibile riportare il file di dump {$targetPath} alla lunghezza del checkpoint.");
        }

        fseek($out, $scrittiSecondoIlCheckpoint);

        try {
            while (true) {
                switch ($state['stage'] ?? 'init') {
                    case 'init':
                        $this->initialize($pdo, $out, $state);
                        break;
                    case 'schema':
                        $this->dumpNextTableSchema($pdo, $out, $state);
                        break;
                    case 'data':
                        $this->dumpNextDataChunk($pdo, $out, $state);
                        break;
                    case 'views':
                        $this->dumpViews($pdo, $out, $state);
                        break;
                    case 'triggers':
                        $this->dumpTriggers($pdo, $out, $state);
                        break;
                    case 'footer':
                        fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n\n-- Dump completato\n");
                        $state['stage'] = 'done';
                        break;
                    case 'done':
                        return true;
                }

                if (($state['stage'] ?? '') !== 'done' && $budget->exceeded()) {
                    return false;
                }
            }
        } finally {
            // Registrato nel `finally` e non prima di ogni `return`: così vale per
            // entrambe le uscite — dump concluso e budget esaurito — e non c'è una
            // terza via che possa dimenticarlo. Su eccezione il valore non serve, perché
            // `BackupManager` marca il backup fallito e il checkpoint viene azzerato.
            $posizione = ftell($out);

            if ($posizione !== false) {
                $state['bytes_written'] = $posizione;
            }

            fclose($out);
        }
    }

    private function initialize(PDO $pdo, $out, array &$state): void
    {
        $tables = [];
        $views = [];

        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (strcasecmp((string) $row[1], 'VIEW') === 0) {
                $views[] = $row[0];
            } else {
                $tables[] = $row[0];
            }
        }

        // Stima delle righe totali (information_schema è approssimato per
        // InnoDB): serve solo alla percentuale di avanzamento in UI.
        $estimates = $pdo->query(
            'SELECT TABLE_NAME, COALESCE(TABLE_ROWS, 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $charset = (string) config('database.connections.'.config('database.default').'.charset', 'utf8mb4');

        fwrite($out, implode("\n", [
            '-- KondoManager — backup database',
            '-- Versione applicazione: '.config('app.version'),
            "-- Database: {$database}",
            '-- Generato il: '.now()->toIso8601String(),
            '',
            "SET NAMES {$charset};",
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET UNIQUE_CHECKS=0;',
            "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';",
            "SET time_zone='+00:00';",
            '',
            '',
        ]));

        $state = [
            'stage' => 'schema',
            'tables' => $tables,
            'views' => $views,
            'schema_index' => 0,
            'data_index' => 0,
            'table' => null,
            'rows_written' => [],
            'rows_written_total' => 0,
            'rows_estimated_total' => (int) array_sum(array_intersect_key($estimates, array_flip($tables))),
            'warnings' => [],
        ];
    }

    private function dumpNextTableSchema(PDO $pdo, $out, array &$state): void
    {
        $index = $state['schema_index'];

        if ($index >= count($state['tables'])) {
            $state['stage'] = 'data';

            return;
        }

        $table = $state['tables'][$index];
        $create = $pdo->query('SHOW CREATE TABLE '.$this->quoteIdentifier($table))->fetch(PDO::FETCH_NUM);

        fwrite($out, sprintf(
            "-- Struttura tabella %s\nDROP TABLE IF EXISTS %s;\n%s;\n\n",
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($table),
            $create[1]
        ));

        $state['schema_index'] = $index + 1;
    }

    private function dumpNextDataChunk(PDO $pdo, $out, array &$state): void
    {
        if ($state['table'] === null) {
            $index = $state['data_index'];

            if ($index >= count($state['tables'])) {
                $state['stage'] = 'views';

                return;
            }

            $table = $state['tables'][$index];

            // Tabelle con struttura ma senza dati nel dump (es. 'backups':
            // contiene checkpoint con la password cifrata e righe che dopo un
            // ripristino punterebbero ad archivi inesistenti).
            if (in_array($table, config('backup.dump_exclude_data', []), true)) {
                fwrite($out, '-- Dati esclusi dal backup per la tabella '.$this->quoteIdentifier($table)."\n\n");
                $state['rows_written'][$table] = 0;
                $state['data_index'] = $index + 1;

                return;
            }

            $state['table'] = $this->tableMeta($pdo, $table);
            fwrite($out, '-- Dati tabella '.$this->quoteIdentifier($state['table']['name'])."\n");
        }

        $meta = $state['table'];
        $chunkSize = max(1, (int) config('backup.db_chunk_rows', 500));

        // Tabella senza colonne esportabili (caso limite: solo colonne generate)
        if (empty($meta['select_columns'])) {
            $this->finishTable($state, 0);

            return;
        }

        $columnList = implode(', ', array_map($this->quoteIdentifier(...), $meta['select_columns']));
        $tableName = $this->quoteIdentifier($meta['name']);

        if ($meta['mode'] === 'keyset') {
            $pkColumn = $this->quoteIdentifier($meta['pk_column']);
            $where = '';

            if ($meta['last_pk'] !== null) {
                $where = " WHERE {$pkColumn} > ".$this->valueLiteral($pdo, $meta['last_pk'], false);
            }

            $sql = "SELECT {$columnList} FROM {$tableName}{$where} ORDER BY {$pkColumn} LIMIT {$chunkSize}";
        } else {
            // Fallback per tabelle senza chiave primaria a colonna singola:
            // paginazione a OFFSET, ordinata sulle colonne della PK composta
            // quando esiste (senza ORDER BY la paginazione non è stabile).
            $orderBy = '';

            if (! empty($meta['order_columns'])) {
                $orderBy = ' ORDER BY '.implode(', ', array_map($this->quoteIdentifier(...), $meta['order_columns']));
            }

            $sql = "SELECT {$columnList} FROM {$tableName}{$orderBy} LIMIT {$chunkSize} OFFSET {$meta['offset']}";
        }

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);

        if ($rows !== []) {
            $this->writeInsertStatements($pdo, $out, $meta, $rows);

            $written = count($rows);
            $state['rows_written_total'] += $written;
            $meta['rows_written'] = ($meta['rows_written'] ?? 0) + $written;

            if ($meta['mode'] === 'keyset') {
                $lastRow = end($rows);
                $meta['last_pk'] = $lastRow[$meta['pk_index']];
            } else {
                $meta['offset'] += $written;
            }

            $state['table'] = $meta;
        }

        if (count($rows) < $chunkSize) {
            fwrite($out, "\n");
            $this->finishTable($state, $meta['rows_written'] ?? 0);
        }
    }

    private function finishTable(array &$state, int $rowsWritten): void
    {
        $state['rows_written'][$state['table']['name']] = $rowsWritten;
        $state['data_index']++;
        $state['table'] = null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  Righe in FETCH_NUM, allineate a select_columns.
     */
    private function writeInsertStatements(PDO $pdo, $out, array $meta, array $rows): void
    {
        $maxBytes = (int) config('backup.insert_max_bytes', 900 * 1024);
        $columnList = implode(', ', array_map($this->quoteIdentifier(...), $meta['select_columns']));
        $prefix = 'INSERT INTO '.$this->quoteIdentifier($meta['name'])." ({$columnList}) VALUES ";

        $statement = '';

        foreach ($rows as $row) {
            $values = [];

            foreach ($row as $i => $value) {
                $values[] = $this->valueLiteral($pdo, $value, $meta['binary_flags'][$i]);
            }

            $tuple = '('.implode(',', $values).')';

            if ($statement === '') {
                $statement = $prefix.$tuple;
            } elseif (strlen($statement) + strlen($tuple) + 2 > $maxBytes) {
                fwrite($out, $statement.";\n");
                $statement = $prefix.$tuple;
            } else {
                $statement .= ','.$tuple;
            }
        }

        if ($statement !== '') {
            fwrite($out, $statement.";\n");
        }
    }

    /**
     * @return array{name: string, select_columns: array<string>, binary_flags: array<bool>, mode: string, pk_column: ?string, pk_index: ?int, order_columns: array<string>, last_pk: mixed, offset: int, rows_written: int}
     */
    private function tableMeta(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$table]);

        $selectColumns = [];
        $binaryFlags = [];

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $type, $extra]) {
            // Le colonne generate (VIRTUAL/STORED) non si possono reinserire
            if (stripos((string) $extra, 'GENERATED') !== false) {
                continue;
            }

            $selectColumns[] = $name;
            $binaryFlags[] = in_array(strtolower((string) $type), self::BINARY_TYPES, true);
        }

        $pkColumns = [];

        foreach ($pdo->query('SHOW KEYS FROM '.$this->quoteIdentifier($table)." WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC) as $key) {
            $key = array_change_key_case($key, CASE_LOWER);
            $pkColumns[(int) $key['seq_in_index']] = $key['column_name'];
        }

        ksort($pkColumns);
        $pkColumns = array_values($pkColumns);

        $pkIndex = count($pkColumns) === 1 ? array_search($pkColumns[0], $selectColumns, true) : false;

        return [
            'name' => $table,
            'select_columns' => $selectColumns,
            'binary_flags' => $binaryFlags,
            'mode' => $pkIndex !== false ? 'keyset' : 'offset',
            'pk_column' => $pkIndex !== false ? $pkColumns[0] : null,
            'pk_index' => $pkIndex !== false ? $pkIndex : null,
            'order_columns' => $pkColumns,
            'last_pk' => null,
            'offset' => 0,
            'rows_written' => 0,
        ];
    }

    private function dumpViews(PDO $pdo, $out, array &$state): void
    {
        $views = $state['views'];

        if ($views !== []) {
            fwrite($out, "-- Viste\n");

            // Prima gli stub: ogni vista viene creata come tabella fittizia con
            // le stesse colonne, così le viste che dipendono da altre viste si
            // possono ricreare in qualsiasi ordine (stessa tecnica di mysqldump).
            foreach ($views as $view) {
                $statement = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
                );
                $statement->execute([$view]);
                $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

                if ($columns === []) {
                    $state['warnings'][] = "Vista `{$view}` senza colonne leggibili: esclusa dal dump.";

                    continue;
                }

                $stubColumns = implode(', ', array_map(
                    fn (string $column) => $this->quoteIdentifier($column).' tinyint NOT NULL',
                    $columns
                ));

                fwrite($out, sprintf(
                    "DROP TABLE IF EXISTS %s;\nDROP VIEW IF EXISTS %s;\nCREATE TABLE %s (%s);\n",
                    $this->quoteIdentifier($view),
                    $this->quoteIdentifier($view),
                    $this->quoteIdentifier($view),
                    $stubColumns
                ));
            }

            foreach ($views as $view) {
                try {
                    $create = $pdo->query('SHOW CREATE VIEW '.$this->quoteIdentifier($view))->fetch(PDO::FETCH_NUM);
                } catch (\Throwable $e) {
                    $state['warnings'][] = "Impossibile leggere la definizione della vista `{$view}`: ".$e->getMessage();

                    continue;
                }

                fwrite($out, sprintf(
                    "DROP TABLE IF EXISTS %s;\n%s;\n",
                    $this->quoteIdentifier($view),
                    $this->stripDefiner((string) $create[1])
                ));
            }

            fwrite($out, "\n");
        }

        $state['stage'] = 'triggers';
    }

    private function dumpTriggers(PDO $pdo, $out, array &$state): void
    {
        $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);

        if ($triggers !== []) {
            fwrite($out, "-- Trigger\nDELIMITER ;;\n");

            foreach ($triggers as $trigger) {
                $trigger = array_change_key_case($trigger, CASE_LOWER);
                $name = $trigger['trigger'];

                $create = $pdo->query('SHOW CREATE TRIGGER '.$this->quoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);
                $create = array_change_key_case($create ?: [], CASE_LOWER);
                $definition = $create['sql original statement'] ?? null;

                if ($definition === null) {
                    $state['warnings'][] = "Impossibile leggere la definizione del trigger `{$name}`.";

                    continue;
                }

                fwrite($out, sprintf(
                    "DROP TRIGGER IF EXISTS %s;;\n%s;;\n",
                    $this->quoteIdentifier($name),
                    $this->stripDefiner($definition)
                ));
            }

            fwrite($out, "DELIMITER ;\n\n");
        }

        $state['stage'] = 'footer';
    }

    private function valueLiteral(PDO $pdo, mixed $value, bool $isBinary): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($isBinary) {
            $value = (string) $value;

            return $value === '' ? "''" : '0x'.bin2hex($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    private function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function stripDefiner(string $sql): string
    {
        // Rimuove DEFINER=utente@host (in tutte le forme di quoting) e
        // l'eventuale SQL SECURITY DEFINER: l'import funziona così con
        // qualsiasi utente MySQL, senza privilegi SUPER.
        // SQL SECURITY INVOKER (scelta esplicita) viene invece preservato.
        $sql = (string) preg_replace(
            '/DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[0-9A-Za-z$_]+)@(?:`[^`]*`|\'[^\']*\'|[0-9A-Za-z$_.%\-]+)\s*/i',
            '',
            $sql
        );
        $sql = (string) preg_replace('/DEFINER\s*=\s*CURRENT_USER(?:\(\))?\s*/i', '', $sql);

        return (string) preg_replace('/\s*SQL SECURITY DEFINER/i', '', $sql);
    }
}

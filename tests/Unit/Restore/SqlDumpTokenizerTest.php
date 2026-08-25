<?php

use App\Services\Restore\Exceptions\MalformedDumpException;
use App\Services\Restore\SqlDumpTokenizer;

/**
 * Test del tokenizer del dump SQL: è il cuore dell'import riprendibile del
 * ripristino, quindi ogni costrutto del formato generato da MySqlDumper ha
 * il suo caso, più la proprietà fondamentale: da QUALSIASI checkpoint
 * (nextOffset + delimiter) la ripresa produce esattamente la coda attesa.
 *
 * Test puri (nessun database, nessun framework): il tokenizer legge file.
 */
function tokenizerDumpFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'km-tok-');
    file_put_contents($path, $contents);

    return $path;
}

function tokenizeAll(string $contents, int $offset = 0, string $delimiter = ';'): array
{
    $path = tokenizerDumpFile($contents);

    try {
        return iterator_to_array((new SqlDumpTokenizer($path))->statements($offset, $delimiter), false);
    } finally {
        @unlink($path);
    }
}

function sqlOnly(array $statements): array
{
    return array_column($statements, 'sql');
}

test('statement semplici separati dal terminatore', function () {
    $statements = tokenizeAll("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSELECT 1;\n");

    expect(sqlOnly($statements))->toBe([
        'SET NAMES utf8mb4',
        'SET FOREIGN_KEY_CHECKS=0',
        'SELECT 1',
    ]);

    foreach ($statements as $statement) {
        expect($statement['delimiter'])->toBe(';');
    }
});

test('le stringhe ostili non spezzano lo statement', function () {
    // ';' e newline dentro la stringa, escape backslash dell'apice, backslash
    // doppio in coda, quote raddoppiata, stringa con doppi apici
    $sql = "INSERT INTO `note` (`testo`) VALUES ('riga1;\nriga2 \\' apice \\\\'), ('raddoppio '' interno'), (\"doppi; apici\")";
    $statements = tokenizeAll($sql.";\nSELECT 2;\n");

    expect(sqlOnly($statements))->toBe([$sql, 'SELECT 2']);
});

test('identificatori backtick con backtick raddoppiato e terminatore interno', function () {
    $sql = 'CREATE TABLE `tabella``strana;` (`id` int)';
    $statements = tokenizeAll($sql.";\n");

    expect(sqlOnly($statements))->toBe([$sql]);
});

test('CREATE TABLE multiriga resta un unico statement', function () {
    $create = "CREATE TABLE `utenti` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `nota` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
    $statements = tokenizeAll("-- Struttura tabella `utenti`\nDROP TABLE IF EXISTS `utenti`;\n{$create};\n\n");

    expect(sqlOnly($statements))->toBe(['DROP TABLE IF EXISTS `utenti`', $create]);
});

test('letterali binari esadecimali passano indenni', function () {
    $sql = "INSERT INTO `blob_test` (`payload`) VALUES (0x001122eeff), ('')";
    $statements = tokenizeAll($sql.";\n");

    expect(sqlOnly($statements))->toBe([$sql]);
});

test('i commenti fuori dagli statement vengono scartati, dentro preservati', function () {
    $dump = "-- KondoManager — backup database\n-- Versione applicazione: x\n\n"
        ."SELECT 1 -- nota inline\n+ 2;\n"
        ."# commento hash\n"
        ."/* commento blocco */\n"
        ."SELECT 3;\n"
        ."\n-- Dump completato\n";

    $statements = tokenizeAll($dump);

    expect(sqlOnly($statements))->toBe([
        "SELECT 1 -- nota inline\n+ 2",
        'SELECT 3',
    ]);
});

test('doppio meno senza spazio non è un commento', function () {
    // "--2" è doppia negazione: lo statement NON deve essere troncato lì
    $sql = 'SELECT 5 --2';
    $statements = tokenizeAll($sql.";\n");

    expect(sqlOnly($statements))->toBe([$sql]);
});

test('blocco DELIMITER: i trigger con punti e virgola interni restano interi', function () {
    $trigger = "CREATE TRIGGER `traccia` AFTER UPDATE ON `conti` FOR EACH ROW BEGIN\n"
        ."INSERT INTO `log` (`nota`) VALUES ('a;b');\nINSERT INTO `log` (`nota`) VALUES ('c');\nEND";

    $dump = "SET FOREIGN_KEY_CHECKS=0;\n"
        ."-- Trigger\nDELIMITER ;;\n"
        ."DROP TRIGGER IF EXISTS `traccia`;;\n"
        ."{$trigger};;\n"
        ."DELIMITER ;\n\n"
        ."SET FOREIGN_KEY_CHECKS=1;\n";

    $statements = tokenizeAll($dump);

    expect(sqlOnly($statements))->toBe([
        'SET FOREIGN_KEY_CHECKS=0',
        'DROP TRIGGER IF EXISTS `traccia`',
        $trigger,
        'SET FOREIGN_KEY_CHECKS=1',
    ]);

    // Il delimitatore riportato è quello in vigore DOPO ogni statement:
    // dentro il blocco ';;', fuori ';'
    expect(array_column($statements, 'delimiter'))->toBe([';', ';;', ';;', ';']);
});

test('la ripresa da ogni checkpoint produce esattamente la coda rimanente', function () {
    $trigger = "CREATE TRIGGER `t` AFTER INSERT ON `a` FOR EACH ROW BEGIN\nUPDATE `b` SET `n` = 'x;y';\nEND";

    $dump = "-- intestazione\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n"
        ."DROP TABLE IF EXISTS `a`;\nCREATE TABLE `a` (\n `id` int,\n `s` text\n);\n"
        ."INSERT INTO `a` (`id`, `s`) VALUES (1, 'v;\\'w\\nz');\n"
        ."DELIMITER ;;\nDROP TRIGGER IF EXISTS `t`;;\n{$trigger};;\nDELIMITER ;\n\n"
        ."SET FOREIGN_KEY_CHECKS=1;\n\n-- Dump completato\n";

    $path = tokenizerDumpFile($dump);

    try {
        $tokenizer = new SqlDumpTokenizer($path);
        $all = iterator_to_array($tokenizer->statements(), false);

        // SET NAMES, SET FK=0, DROP TABLE, CREATE TABLE, INSERT,
        // DROP TRIGGER, CREATE TRIGGER, SET FK=1
        expect($all)->toHaveCount(8);

        // Da ogni checkpoint k (offset+delimiter dello statement k) la
        // ripresa deve restituire gli statement k+1..n identici.
        foreach ($all as $k => $checkpoint) {
            $resumed = iterator_to_array(
                $tokenizer->statements($checkpoint['nextOffset'], $checkpoint['delimiter']),
                false
            );

            expect($resumed)->toBe(
                array_slice($all, $k + 1),
                "Ripresa dal checkpoint {$k} (offset {$checkpoint['nextOffset']}) divergente"
            );
        }

        // Ripartire dall'ultimo checkpoint (fine dump) non produce nulla
        $last = end($all);
        expect(iterator_to_array($tokenizer->statements($last['nextOffset'], $last['delimiter']), false))
            ->toBe([]);
    } finally {
        @unlink($path);
    }
});

test('uno statement più grande del chunk di lettura viene ricomposto intero', function () {
    // La finestra di lettura interna è 64KB: 200KB di valori in un solo INSERT
    $values = [];
    for ($i = 0; $i < 2000; $i++) {
        $values[] = "({$i}, '".str_repeat('x', 100)."; \\' fine {$i}')";
    }
    $sql = 'INSERT INTO `grande` (`id`, `testo`) VALUES '.implode(', ', $values);

    expect(strlen($sql))->toBeGreaterThan(150_000);

    $statements = tokenizeAll("SET NAMES utf8mb4;\n{$sql};\nSELECT 99;\n");

    expect(sqlOnly($statements))->toBe(['SET NAMES utf8mb4', $sql, 'SELECT 99']);
});

test('dump troncato: statement senza terminatore finale', function () {
    tokenizeAll("SELECT 1;\nINSERT INTO `a` VALUES (1)");
})->throws(MalformedDumpException::class);

test('dump troncato: stringa mai chiusa', function () {
    tokenizeAll("INSERT INTO `a` VALUES ('mai chiusa...");
})->throws(MalformedDumpException::class);

test('direttiva DELIMITER non riconosciuta', function () {
    tokenizeAll("DELIMITER $$\nSELECT 1$$\n");
})->throws(MalformedDumpException::class);

test('file inesistente', function () {
    iterator_to_array((new SqlDumpTokenizer('/percorso/inesistente.sql'))->statements(), false);
})->throws(RuntimeException::class);

test('offset oltre la fine del file produce un generatore vuoto', function () {
    $path = tokenizerDumpFile("SELECT 1;\n");

    try {
        $statements = iterator_to_array((new SqlDumpTokenizer($path))->statements(10_000), false);
        expect($statements)->toBe([]);
    } finally {
        @unlink($path);
    }
});

test('gli offset dei checkpoint sono strettamente crescenti e coerenti col file', function () {
    $dump = "SELECT 1;\nSELECT 22;\nSELECT 333;\n";
    $statements = tokenizeAll($dump);

    $previous = 0;
    foreach ($statements as $statement) {
        expect($statement['nextOffset'])->toBeGreaterThan($previous);
        $previous = $statement['nextOffset'];
    }

    // L'ultimo checkpoint cade subito dopo l'ultimo ';' (prima del newline finale)
    expect($previous)->toBe(strlen($dump) - 1);
});

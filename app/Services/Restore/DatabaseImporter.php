<?php

namespace App\Services\Restore;

use App\Services\Backup\Support\StepBudget;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Importa un dump SQL nel database corrente, a step riprendibili.
 *
 * È la controparte in scrittura di MySqlDumper: azzera lo schema esistente
 * e riesegue gli statement del dump uno per uno tramite SqlDumpTokenizer,
 * salvando come checkpoint l'offset di byte del prossimo statement. Un import
 * interrotto (timeout, browser chiuso) riprende esattamente da lì.
 *
 * Ogni step apre una connessione PDO "fresca" (nuova richiesta HTTP =
 * sessione MySQL nuova) e ri-emette il preambolo di sessione del dump
 * (FOREIGN_KEY_CHECKS=0, ecc.): quelle direttive valgono solo per la
 * sessione che le ha eseguite, quindi vanno ripetute a ogni step.
 */
class DatabaseImporter
{
    /**
     * Preambolo ri-emesso a ogni step: replica l'header del dump, che vale
     * solo per la sessione corrente. Allineato a MySqlDumper::initialize().
     */
    private const SESSION_PREAMBLE = [
        'SET NAMES utf8mb4',
        'SET FOREIGN_KEY_CHECKS=0',
        'SET UNIQUE_CHECKS=0',
        "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'",
        "SET time_zone='+00:00'",
    ];

    /**
     * Azzera lo schema corrente: droppa tutte le viste e tutte le tabelle.
     * Necessario perché il dump ricrea le tabelle dell'ORIGINE — eventuali
     * tabelle presenti solo nella destinazione resterebbero orfane e
     * romperebbero la coerenza (es. foreign key verso dati spariti).
     *
     * NON droppa il database: le credenziali e i permessi restano quelli
     * del server corrente (il .env non viene toccato dall'import).
     */
    public function wipe(): void
    {
        $pdo = $this->freshConnection();

        // I vincoli di foreign key impediscono di droppare una tabella
        // referenziata da un'altra: vanno disattivati per la durata del wipe
        // (uno schema reale è pieno di relazioni). Ripristinati alla fine.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);

            foreach ($tables as [$name, $type]) {
                $quoted = '`'.str_replace('`', '``', $name).'`';

                if ($type === 'VIEW') {
                    $pdo->exec("DROP VIEW IF EXISTS {$quoted}");
                } else {
                    $pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Esegue gli statement del dump a partire dal checkpoint, entro il
     * budget di tempo. Restituisce il checkpoint aggiornato e se l'import è
     * completo.
     *
     * @param  array{offset?: int, delimiter?: string}  $checkpoint
     * @return array{checkpoint: array{offset: int, delimiter: string}, done: bool, executed: int}
     */
    public function importStep(string $dumpPath, array $checkpoint, StepBudget $budget): array
    {
        $offset = (int) ($checkpoint['offset'] ?? 0);
        $delimiter = (string) ($checkpoint['delimiter'] ?? ';');

        $pdo = $this->freshConnection();
        foreach (self::SESSION_PREAMBLE as $set) {
            $pdo->exec($set);
        }

        $tokenizer = new SqlDumpTokenizer($dumpPath);
        $executed = 0;
        $done = true;

        foreach ($tokenizer->statements($offset, $delimiter) as $statement) {
            $pdo->exec($statement['sql']);
            $executed++;

            $offset = $statement['nextOffset'];
            $delimiter = $statement['delimiter'];

            if ($budget->exceeded()) {
                // C'è ancora roba dopo questo statement? Il generatore lo dirà
                // al prossimo step; qui usciamo per non sforare il budget.
                $done = false;
                break;
            }
        }

        return [
            'checkpoint' => ['offset' => $offset, 'delimiter' => $delimiter],
            'done' => $done,
            'executed' => $executed,
        ];
    }

    /**
     * Connessione PDO nuova sulla connessione di default. DB::reconnect
     * garantisce che non si riusi un handle di una richiesta precedente
     * (fondamentale tra uno step HTTP e l'altro).
     */
    private function freshConnection(): PDO
    {
        DB::reconnect();

        return DB::connection()->getPdo();
    }
}

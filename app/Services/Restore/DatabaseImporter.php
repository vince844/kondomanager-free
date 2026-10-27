<?php

namespace App\Services\Restore;

use App\Services\Backup\Support\StepBudget;
use App\Services\Restore\Exceptions\ImportInterruptedException;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

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
     * Le tabelle di cui l'import non scrive i dati, anche quando il dump li porta (gli archivi fatti prima che
     * `backup.dump_exclude_data` li togliesse). La finalizzazione le svuota comunque
     * (`RestoreManager::resetVolatileState`): saltarne i dati non cambia il database ripristinato.
     *  - Sessioni e cache: durante l'import qualcuno può già averci scritto una riga con la stessa chiave di una del
     *    backup — una pagina aperta con il cookie di una sessione che è nel backup, il pianificatore da riga di
     *    comando con il suo blocco nella cache —: l'`INSERT` del dump si fermava per chiave doppia, con il database
     *    a metà.
     *  - La coda (`jobs`, `job_batches`, `failed_jobs`) e i token per reimpostare la password: dove la coda gira
     *    durante il ripristino (il worker sotto supervisord nell'immagine Docker), i lavori del backup riscritti in
     *    `jobs` si eseguirebbero sul database a metà, prima che la finalizzazione li svuoti.
     */
    private const TABELLE_SENZA_DATI = [
        'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
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
     * Su un errore lancia ImportInterruptedException con il punto di ripresa:
     * l'istruzione che non è riuscita. Le istruzioni del dump si scrivono una
     * per una, fuori da una transazione, e quelle prima dell'errore sono già
     * nel database: «Riprendi» riparte da quella, invece di rieseguire le
     * altre (un `INSERT` già scritto si fermerebbe per chiave doppia).
     *
     * @param  array{offset?: int, delimiter?: string}  $checkpoint
     * @return array{checkpoint: array{offset: int, delimiter: string}, done: bool, executed: int}
     *
     * @throws ImportInterruptedException
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

        try {
            foreach ($tokenizer->statements($offset, $delimiter) as $statement) {
                if (! $this->datiDaNonScrivere($statement['sql'])) {
                    $pdo->exec($statement['sql']);
                }
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
        } catch (Throwable $e) {
            throw new ImportInterruptedException(['offset' => $offset, 'delimiter' => $delimiter], $e);
        }

        return [
            'checkpoint' => ['offset' => $offset, 'delimiter' => $delimiter],
            'done' => $done,
            'executed' => $executed,
        ];
    }

    /** L'istruzione scrive i dati di una tabella che l'import salta (`TABELLE_SENZA_DATI`)? */
    private function datiDaNonScrivere(string $sql): bool
    {
        return preg_match('/^\s*INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s/i', $sql, $tabella) === 1
            && in_array($tabella[1], self::TABELLE_SENZA_DATI, true);
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

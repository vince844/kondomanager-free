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
 * Il checkpoint dice fin dove il dump è nel database: le istruzioni che
 * scrivono dati si confermano a gruppi, in una transazione, e un passo
 * restituisce il checkpoint solo dopo la conferma. Un processo che muore a
 * metà di un gruppo, senza un'eccezione da prendere (memoria esaurita,
 * `max_execution_time`, il limite del PHP-FPM), lascia la transazione aperta,
 * e il database la annulla quando la connessione si chiude: le righe nel
 * database sono quelle prima del checkpoint, e la ripresa riscrive le altre
 * una volta sola. Le istruzioni di struttura (DROP, CREATE) restano fuori dai
 * gruppi: MySQL conferma da sé le scritture aperte prima di eseguirle, e
 * rieseguite dalla ripresa danno lo stesso schema, perché il checkpoint non
 * cade mai dentro le istruzioni di un oggetto (dopo un `DROP … IF EXISTS` o
 * prima di un `CREATE`): la ripresa le rifà tutte, dalla prima rimozione.
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
     * Le istruzioni che scrivono dati (`INSERT`) si confermano a gruppi: un
     * gruppo si apre alla prima, e si conferma allo scadere del budget, alla
     * fine del dump, quando arriva a `backup.restore.import_group_bytes` byte
     * e prima di un'istruzione che non scrive dati. Negli ultimi due casi il
     * passo finisce lì (`done` falso), perché chi chiama salvi il checkpoint
     * dopo il gruppo prima di andare avanti: dopo, fino al prossimo salvataggio,
     * nel database c'è solo ciò che una ripresa può rifare.
     *
     * Su un errore il gruppo aperto si annulla, e ImportInterruptedException
     * porta il punto di ripresa: dove finisce l'ultima istruzione confermata.
     * Le istruzioni dopo non sono nel database, e «Riprendi» le esegue una volta
     * sola (un `INSERT` già scritto si fermerebbe per chiave doppia).
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
        $byteDelGruppo = max(1, (int) config('backup.restore.import_group_bytes', 8 * 1024 * 1024));

        // Fin dove il dump è nel database: il punto di ripresa di un errore.
        $confermato = ['offset' => $offset, 'delimiter' => $delimiter];
        $gruppoAperto = false;
        $byteNelGruppo = 0;
        // Il budget è finito fuori da un gruppo: il passo finisce prima dell'istruzione dopo, se non è una creazione.
        $budgetFinito = false;

        try {
            foreach ($tokenizer->statements($offset, $delimiter) as $statement) {
                $scriveDati = $this->scriveDati($statement['sql']);

                if ($gruppoAperto && ! $scriveDati) {
                    // MySQL conferma da sé le scritture aperte prima di un'istruzione di struttura: il gruppo si
                    // conferma qui, e il passo finisce prima di quell'istruzione, perché il punto di ripresa si salvi.
                    $pdo->commit();
                    $gruppoAperto = false;
                    $done = false;
                    break;
                }

                if ($budgetFinito && ! $this->creaUnOggetto($statement['sql'])) {
                    // Il budget è finito: il passo finisce prima di questa istruzione, e chi chiama salva il punto di
                    // ripresa. Mai dentro le istruzioni di un oggetto (vedi sotto): un `CREATE` ripetuto dalla ripresa,
                    // dopo un passo che ha già creato l'oggetto ed è morto senza errore, si fermerebbe sull'oggetto che
                    // c'è già, e così un `DROP VIEW` sulla tabella provvisoria di una vista.
                    $done = false;
                    break;
                }

                if ($scriveDati && ! $gruppoAperto) {
                    $pdo->beginTransaction();
                    $gruppoAperto = true;
                    $byteNelGruppo = 0;
                }

                if (! $this->datiDaNonScrivere($statement['sql'])) {
                    $pdo->exec($statement['sql']);
                }
                $executed++;

                $offset = $statement['nextOffset'];
                $delimiter = $statement['delimiter'];

                if ($gruppoAperto) {
                    $byteNelGruppo += strlen($statement['sql']);
                } else {
                    $confermato = ['offset' => $offset, 'delimiter' => $delimiter];
                }

                if ($gruppoAperto && ($budget->exceeded() || $byteNelGruppo >= $byteDelGruppo)) {
                    // Il budget è finito, o il gruppo è pieno: si esce, e chi chiama salva il punto di ripresa. C'è
                    // ancora roba dopo questo statement? Il generatore lo dirà al prossimo step.
                    $done = false;
                    break;
                }

                // Dopo una rimozione il passo va avanti: il dump la fa seguire dalle altre istruzioni dello stesso
                // oggetto (la vista: `DROP TABLE`, `DROP VIEW`, la tabella provvisoria), fino al `CREATE`.
                $budgetFinito = $budget->exceeded() && ! $this->toglieUnOggetto($statement['sql']);
            }

            if ($gruppoAperto) {
                $pdo->commit();
                $gruppoAperto = false;
            }
        } catch (Throwable $e) {
            if ($gruppoAperto) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // La connessione è già chiusa: il database ha già annullato il gruppo.
                }
            }

            throw new ImportInterruptedException($confermato, $e);
        }

        return [
            'checkpoint' => ['offset' => $offset, 'delimiter' => $delimiter],
            'done' => $done,
            'executed' => $executed,
        ];
    }

    /** L'istruzione scrive i dati di una tabella (`INSERT`)? Tutte le altre del dump sono di sessione o di struttura. */
    private function scriveDati(string $sql): bool
    {
        return preg_match('/^\s*INSERT\s/i', $sql) === 1;
    }

    /** L'istruzione toglie una tabella, una vista o un trigger (`DROP … IF EXISTS`)? */
    private function toglieUnOggetto(string $sql): bool
    {
        return preg_match('/^\s*DROP\s/i', $sql) === 1;
    }

    /** L'istruzione crea una tabella, un indice, una vista o un trigger (`CREATE`)? */
    private function creaUnOggetto(string $sql): bool
    {
        return preg_match('/^\s*CREATE\s/i', $sql) === 1;
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

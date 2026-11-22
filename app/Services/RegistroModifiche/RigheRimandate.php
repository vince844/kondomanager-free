<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le righe rimandate alla fine della transazione, ancora dentro la transazione (decisioni 554 e 556).
 *
 * - **Una scheda nuova** (decisione 554): la riga «Creata» si scrive quando la transazione della creazione si chiude, con i legami fatti nel frattempo
 *   (i condomini della persona, i destinatari di una comunicazione, le categorie di un documento): una riga sola, «Carlo Fontana · Creata, con 2 dati»,
 *   nel registro di Via Roma 12, non una riga di collegamento dopo una «Creata» senza condomini.
 * - **Una scrittura contabile** (decisione 556): la riga «Scrittura registrata: Incasso rata, € 120,00» si scrive quando le righe della scrittura ci sono.
 *
 * **Quando si scrivono.** Prima del commit della transazione più esterna (`TransactionCommitting`, che Laravel lancia solo al livello 1): la riga va o non
 * va con la modifica (decisione 518). Nei test la transazione della prova avvolge tutto e la più esterna del programma si chiude senza `committing`: lì
 * si scrivono subito dopo la sua chiusura, quando Laravel fa partire le funzioni «dopo il commit», ancora dentro la transazione della prova. Che cosa è
 * «la transazione del programma» lo dice il gestore delle transazioni di Laravel ({@see transazioneDelProgramma()}), lo stesso che decide quando partono
 * le funzioni «dopo il commit», nel programma e nei test. Un rollback toglie le righe rimandate dentro i livelli tornati indietro; una transazione
 * interna chiusa passa le sue a quella che la contiene.
 *
 * **L'ordine.** Prima di scrivere un'altra riga sullo stesso oggetto (una modifica, un'eliminazione nella stessa transazione) si scrive la sua rimandata
 * ({@see RegistroModifiche::scrivi()}). Senza una transazione del programma aperta la riga si scrive subito, come prima.
 *
 * **La rete.** Una riga che non va dentro una transazione del programma lascia un segno sulla connessione ({@see rigaNonScritta()}): se chi chiama prende
 * l'eccezione e va avanti, al commit della transazione la modifica non si conferma («Una riga del registro delle modifiche non è stata scritta: la
 * modifica non si conferma.»). Un rollback dei livelli dove la riga non è andata toglie il segno: lì la modifica non c'è più.
 */
final class RigheRimandate
{
    /**
     * Per chiave `tipo:id`, nell'ordine in cui sono nate.
     *
     * @var array<string, array{connessione: string, livello: int, riga: Closure(list<array<string, mixed>>): ?array<string, mixed>,
     *     legami: list<array<string, mixed>>}>
     */
    private static array $righe = [];

    /** Le righe che non sono andate, per connessione: il livello della transazione e l'errore. @var array<string, array{livello: int, errore: Throwable}> */
    private static array $nonScritte = [];

    public const COMMIT_RIFIUTATO = 'Una riga del registro delle modifiche non è stata scritta: la modifica non si conferma.';

    /**
     * Rimanda la riga di un oggetto alla fine della transazione. `$riga` riceve i legami raccolti nel frattempo e rende la riga per
     * {@see RegistroModifiche::scrivi()} (o `null`: niente da scrivere).
     *
     * @param Closure(list<array<string, mixed>>): ?array<string, mixed> $riga
     */
    public static function rimanda(string $chiave, Closure $riga): void
    {
        $connessione = DB::connection();
        if (! self::transazioneDelProgramma($connessione)) {
            $dati = $riga([]);
            if ($dati !== null) {
                RegistroModifiche::scrivi($dati);
            }

            return;
        }

        unset(self::$righe[$chiave]);
        self::$righe[$chiave] = ['connessione' => $connessione->getName(), 'livello' => $connessione->transactionLevel(), 'riga' => $riga, 'legami' => []];
    }

    /** Se sulla connessione di serie c'è una transazione aperta dal programma (nei test, non quella che avvolge la prova). */
    public static function inUnaTransazioneDelProgramma(): bool
    {
        return self::transazioneDelProgramma(DB::connection());
    }

    /** Se l'oggetto ha una riga rimandata. */
    public static function ce(string $chiave): bool
    {
        return isset(self::$righe[$chiave]);
    }

    /**
     * Un legame fatto sull'oggetto dopo la sua riga rimandata: va nella riga, non in una riga sua. Chiavi: `campo` (un {@see Campo}, o `null` per i
     * condomini, che la riga legge da sé), `soggetti` (`[[tipo, id], …]`), `aggiorna` (i dati cambiati da una modifica nella stessa transazione). Rende
     * `false` se l'oggetto non ha una riga rimandata.
     *
     * @param array{campo?: array<string, mixed>|null, soggetti?: list<array{0: string, 1: int}>, aggiorna?: list<array<string, mixed>>} $legame
     */
    public static function aggiungiLegame(string $chiave, array $legame): bool
    {
        if (! isset(self::$righe[$chiave])) {
            return false;
        }
        self::$righe[$chiave]['legami'][] = $legame;

        return true;
    }

    /** Scrive adesso la riga rimandata di quell'oggetto, se c'è. */
    public static function scriviOra(string $chiave): void
    {
        if (! isset(self::$righe[$chiave])) {
            return;
        }
        $voce = self::$righe[$chiave];
        unset(self::$righe[$chiave]);
        $dati = ($voce['riga'])($voce['legami']);
        if ($dati !== null) {
            RegistroModifiche::scrivi($dati);
        }
    }

    /** Scrive le righe rimandate di quella connessione, nell'ordine in cui sono nate. */
    public static function scriviTutte(string $connessione): void
    {
        foreach (array_keys(self::$righe) as $chiave) {
            if ((self::$righe[$chiave]['connessione'] ?? null) === $connessione) {
                self::scriviOra($chiave);
            }
        }
    }

    /** Toglie le righe rimandate nate sopra quel livello (un rollback). */
    public static function lasciaSopra(string $connessione, int $livello): void
    {
        foreach (self::$righe as $chiave => $voce) {
            if ($voce['connessione'] === $connessione && $voce['livello'] > $livello) {
                unset(self::$righe[$chiave]);
            }
        }
    }

    /**
     * {@see RegistroModifiche::scrivi()} non è andata: dentro una transazione del programma la connessione non confermerà più. Fuori, niente: la
     * transazione l'ha aperta la scrittura stessa, ed è già tornata indietro con la modifica.
     */
    public static function rigaNonScritta(Throwable $errore): void
    {
        $connessione = DB::connection();
        $gia = self::$nonScritte[$connessione->getName()] ?? null;
        // Si tiene il livello più esterno: un rollback di un livello interno non toglie il segno di una riga mancata più fuori.
        if (! self::transazioneDelProgramma($connessione) || ($gia !== null && $gia['livello'] <= $connessione->transactionLevel())) {
            return;
        }
        self::$nonScritte[$connessione->getName()] = ['livello' => $connessione->transactionLevel(), 'errore' => $errore];
    }

    /** Il commit si rifiuta se una riga non è andata: l'eccezione, con l'errore della riga dietro. */
    private static function rifiutaSeManca(string $connessione): void
    {
        $mancata = self::$nonScritte[$connessione] ?? null;
        if ($mancata === null) {
            return;
        }
        unset(self::$nonScritte[$connessione]);

        throw new \RuntimeException(self::COMMIT_RIFIUTATO, 0, $mancata['errore']);
    }

    /** Per i test e per {@see ContestoDelRegistro::azzera()}. */
    public static function svuota(): void
    {
        self::$righe = [];
        self::$nonScritte = [];
    }

    /** Quante righe aspettano (per i test). */
    public static function quante(): int
    {
        return count(self::$righe);
    }

    /**
     * Prima del commit della transazione più esterna: le righe rimandate entrano nella transazione. Se una non va, la transazione torna indietro tutta e
     * l'errore riparte (Laravel, con l'errore dentro `committing`, non la chiuderebbe da sé).
     */
    public static function primaDelCommit(TransactionCommitting $evento): void
    {
        $connessione = $evento->connection;
        try {
            self::rifiutaSeManca($connessione->getName());
            self::scriviTutte($connessione->getName());
            self::rifiutaSeManca($connessione->getName());
        } catch (Throwable $e) {
            self::lasciaSopra($connessione->getName(), 0);
            unset(self::$nonScritte[$connessione->getName()]);
            $pdo = $connessione->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Dopo la chiusura di una transazione interna le sue righe rimandate passano a quella che la contiene: un rollback di un'altra transazione interna,
     * dopo, non le deve togliere (la scheda nata nella prima resta). Nei test, poi: la transazione più esterna del programma si è chiusa dentro quella
     * della prova (vedi sopra). Nel programma, a commit fatto, niente da scrivere.
     */
    public static function dopoIlCommit(TransactionCommitted $evento): void
    {
        $connessione = $evento->connection;
        $nome = $connessione->getName();
        $livello = $connessione->transactionLevel();
        foreach (self::$righe as $chiave => $voce) {
            if ($voce['connessione'] === $nome && $voce['livello'] > $livello) {
                self::$righe[$chiave]['livello'] = $livello;
            }
        }
        if (isset(self::$nonScritte[$nome]) && self::$nonScritte[$nome]['livello'] > $livello) {
            self::$nonScritte[$nome]['livello'] = $livello;
        }
        if ($livello > 0 && ! self::transazioneDelProgramma($connessione)) {
            try {
                self::scriviTutte($nome);
                self::rifiutaSeManca($nome);
            } catch (Throwable $e) {
                // Nei test: la transazione del programma si è chiusa dentro quella della prova senza `committing`. Laravel non rilascia i savepoint
                // delle transazioni annidate: si torna a quello della transazione appena chiusa, come farebbe il rollback del commit rifiutato.
                unset(self::$nonScritte[$nome]);
                $connessione->getPdo()->exec('ROLLBACK TO SAVEPOINT trans' . ($livello + 1));

                throw $e;
            }
        }
    }

    public static function dopoIlRollback(TransactionRolledBack $evento): void
    {
        $nome = $evento->connection->getName();
        $livello = $evento->connection->transactionLevel();
        self::lasciaSopra($nome, $livello);
        // La riga non andata era dentro i livelli tornati indietro: la modifica non c'è più, niente da rifiutare.
        if (isset(self::$nonScritte[$nome]) && self::$nonScritte[$nome]['livello'] > $livello) {
            unset(self::$nonScritte[$nome]);
        }
    }

    /**
     * Se sulla connessione c'è una transazione aperta dal programma: per il gestore delle transazioni di Laravel, una a cui si legano le funzioni «dopo il
     * commit». Nel programma è ogni transazione aperta; nei test non lo è quella che avvolge la prova.
     */
    private static function transazioneDelProgramma(Connection $connessione): bool
    {
        if ($connessione->transactionLevel() === 0) {
            return false;
        }
        if (! app()->bound('db.transactions')) {
            return true;
        }

        return app('db.transactions')->callbackApplicableTransactions()
            ->contains(fn ($transazione) => $transazione->connection === $connessione->getName());
    }
}

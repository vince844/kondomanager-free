<?php

namespace App\Services\Restore;

use App\Services\Restore\Exceptions\MalformedDumpException;
use Generator;
use RuntimeException;

/**
 * Scandisce il dump SQL prodotto da MySqlDumper e restituisce gli statement
 * uno alla volta, ciascuno con l'offset di byte da cui riprendere: è il
 * componente che rende l'import del ripristino RIPRENDIBILE a step
 * (pattern checkpoint del motore di backup, ma su file).
 *
 * Perché un tokenizer e non uno split per ";" o per riga: i letterali
 * stringa del dump (PDO::quote, escape con backslash) possono contenere
 * `;`, newline e apici — spezzare lì corromperebbe l'import. E perché non
 * la query multi-statement usata nei test round-trip: non è riprendibile a
 * metà e il buffering è imprevedibile sugli hosting condivisi.
 *
 * Il formato riconosciuto è il SOTTOINSIEME che il nostro dumper genera
 * (più i costrutti che possono arrivare dai body di trigger scritti
 * dall'utente via SHOW CREATE TRIGGER):
 *  - statement terminati dal delimitatore corrente (';' oppure ';;' dentro
 *    il blocco trigger aperto da "DELIMITER ;;" e chiuso da "DELIMITER ;");
 *  - stringhe '...' e "..." con escape backslash e quote raddoppiata;
 *  - identificatori `...` con backtick raddoppiato;
 *  - commenti riga "-- ..." e "#...", commenti blocco "/* ... *\/";
 *  - letterali binari 0x... (nessun carattere ambiguo al loro interno).
 *
 * La scansione è streaming (finestra scorrevole, mai il file intero in
 * memoria) e salta in blocco tra un carattere "interessante" e l'altro
 * (strcspn/strpos): anche dump da centinaia di MB si scandiscono a
 * velocità di libreria C, non un byte per iterazione PHP.
 *
 * Contratto di ripresa: ogni statement è emesso con nextOffset (byte
 * successivo al terminatore) e delimiter (quello in vigore DOPO lo
 * statement). Ripartire da statements(nextOffset, delimiter) produce
 * esattamente la coda rimanente: il checkpoint del ripristino salva
 * solo questi due valori.
 */
class SqlDumpTokenizer
{
    private const CHUNK_SIZE = 65536;

    /**
     * Caratteri che interrompono il salto in blocco nello stato normale:
     * apertura stringhe/identificatori, possibili inizi di commento,
     * terminatore, newline (serve per riconoscere le direttive DELIMITER
     * a inizio riga).
     */
    private const NORMAL_BREAKS = "'\"`-#/;\n";

    /** @var resource */
    private $handle;

    private string $window = '';

    private int $windowStart = 0;

    private int $cursor = 0;

    private bool $eof = false;

    public function __construct(private readonly string $path) {}

    /**
     * @return Generator<int, array{sql: string, nextOffset: int, delimiter: string}>
     */
    public function statements(int $offset = 0, string $delimiter = ';'): Generator
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("Dump non trovato: {$this->path}");
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Impossibile aprire il dump in lettura: {$this->path}");
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException("Impossibile posizionarsi all'offset {$offset} del dump.");
            }

            $this->handle = $handle;
            $this->window = '';
            $this->windowStart = $offset;
            $this->cursor = 0;
            $this->eof = false;

            yield from $this->scan($delimiter);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array{sql: string, nextOffset: int, delimiter: string}>
     */
    private function scan(string $delimiter): Generator
    {
        $buffer = '';

        // Il buffer contiene SQL vero? Commenti e spazi PRIMA che uno
        // statement inizi vengono scartati (le righe "-- Struttura tabella"
        // del dump non sono statement); DENTRO uno statement vengono
        // preservati (MySQL li accetta e fanno parte del testo originale).
        $hasContent = false;

        $atLineStart = true;

        while ($this->ensure(1)) {
            // Direttiva client "DELIMITER x" (mai SQL): riconosciuta solo a
            // inizio riga e fuori da uno statement in costruzione.
            if ($atLineStart && ! $hasContent && $this->lookingAtDelimiterDirective()) {
                $delimiter = $this->consumeDelimiterDirective();
                $buffer = '';

                continue;
            }

            $char = $this->window[$this->cursor];

            if ($char === "'" || $char === '"') {
                $buffer .= $this->consumeString($char);
                $hasContent = true;
                $atLineStart = false;

                continue;
            }

            if ($char === '`') {
                $buffer .= $this->consumeIdentifier();
                $hasContent = true;
                $atLineStart = false;

                continue;
            }

            // Come da spec MySQL, "--" apre un commento solo se seguito da
            // whitespace o fine riga/file: "--x" è doppia negazione, non
            // commento (può capitare in un body di trigger scritto a mano).
            if ($char === '-' && $this->peek(1) === '-'
                && in_array($this->peek(2), [null, ' ', "\t", "\n", "\r"], true)) {
                $comment = $this->consumeLine();
                if ($hasContent) {
                    $buffer .= $comment;
                }
                $atLineStart = true;

                continue;
            }

            if ($char === '#') {
                $comment = $this->consumeLine();
                if ($hasContent) {
                    $buffer .= $comment;
                }
                $atLineStart = true;

                continue;
            }

            if ($char === '/' && $this->peek(1) === '*') {
                $comment = $this->consumeBlockComment();
                if ($hasContent) {
                    $buffer .= $comment;
                }
                $atLineStart = false;

                continue;
            }

            if ($char === ';') {
                if ($delimiter === ';' || $this->peek(1) === ';') {
                    $this->advance(strlen($delimiter));

                    $sql = trim($buffer);
                    $buffer = '';
                    $hasContent = false;

                    if ($sql !== '') {
                        yield [
                            'sql' => $sql,
                            'nextOffset' => $this->position(),
                            'delimiter' => $delimiter,
                        ];
                    }

                    continue;
                }

                // Un ';' singolo dentro il blocco "DELIMITER ;;" è contenuto
                // (i body dei trigger ne sono pieni), non un terminatore.
                $buffer .= ';';
                $hasContent = true;
                $atLineStart = false;
                $this->advance(1);

                continue;
            }

            if ($char === "\n") {
                if ($hasContent) {
                    $buffer .= "\n";
                }
                $atLineStart = true;
                $this->advance(1);

                continue;
            }

            // '-' o '/' isolati (es. numeri negativi, divisioni) e qualunque
            // run di caratteri ordinari fino al prossimo carattere speciale.
            $run = $this->consumeRun(self::NORMAL_BREAKS);

            if ($run === '') {
                // Il carattere corrente È uno dei break ma non ha aperto
                // nessun costrutto (es. '-' non seguito da '-'): consumalo.
                $run = $char;
                $this->advance(1);
            }

            $buffer .= $run;

            if (! $hasContent && trim($run) !== '') {
                $hasContent = true;
            }
            $atLineStart = false;
        }

        if (trim($buffer) !== '' && $hasContent) {
            throw new MalformedDumpException(
                'Dump troncato o malformato: statement senza terminatore alla fine del file '
                ."(offset {$this->position()})."
            );
        }
    }

    /* ------------------------------------------------------------------
     | Costrutti
     | ------------------------------------------------------------------ */

    private function lookingAtDelimiterDirective(): bool
    {
        return $this->ensure(10) && substr($this->window, $this->cursor, 10) === 'DELIMITER ';
    }

    private function consumeDelimiterDirective(): string
    {
        $line = rtrim($this->consumeLine(), "\r\n");
        $delimiter = trim(substr($line, strlen('DELIMITER')));

        if ($delimiter !== ';' && $delimiter !== ';;') {
            throw new MalformedDumpException(
                "Direttiva DELIMITER non riconosciuta nel dump: \"{$line}\" (offset {$this->position()})."
            );
        }

        return $delimiter;
    }

    private function consumeString(string $quote): string
    {
        $consumed = $quote;
        $this->advance(1);

        while (true) {
            $run = $this->consumeRun($quote.'\\');
            $consumed .= $run;

            if (! $this->ensure(1)) {
                throw new MalformedDumpException(
                    "Dump troncato: letterale stringa mai chiuso (offset {$this->position()})."
                );
            }

            $char = $this->window[$this->cursor];

            if ($char === '\\') {
                if (! $this->ensure(2)) {
                    throw new MalformedDumpException(
                        "Dump troncato: escape a fine file dentro una stringa (offset {$this->position()})."
                    );
                }
                $consumed .= substr($this->window, $this->cursor, 2);
                $this->advance(2);

                continue;
            }

            // $char === $quote: quote raddoppiata = quote letterale interna.
            if ($this->peek(1) === $quote) {
                $consumed .= $quote.$quote;
                $this->advance(2);

                continue;
            }

            $consumed .= $quote;
            $this->advance(1);

            return $consumed;
        }
    }

    private function consumeIdentifier(): string
    {
        $consumed = '`';
        $this->advance(1);

        while (true) {
            $run = $this->consumeRun('`');
            $consumed .= $run;

            if (! $this->ensure(1)) {
                throw new MalformedDumpException(
                    "Dump troncato: identificatore backtick mai chiuso (offset {$this->position()})."
                );
            }

            if ($this->peek(1) === '`') {
                $consumed .= '``';
                $this->advance(2);

                continue;
            }

            $consumed .= '`';
            $this->advance(1);

            return $consumed;
        }
    }

    private function consumeLine(): string
    {
        $consumed = '';

        while (true) {
            $newline = strpos($this->window, "\n", $this->cursor);

            if ($newline !== false) {
                $consumed .= substr($this->window, $this->cursor, $newline - $this->cursor + 1);
                $this->advance($newline - $this->cursor + 1);

                return $consumed;
            }

            $consumed .= substr($this->window, $this->cursor);
            $this->advance(strlen($this->window) - $this->cursor);

            if (! $this->ensure(1)) {
                return $consumed; // ultima riga del file senza newline finale
            }
        }
    }

    private function consumeBlockComment(): string
    {
        $consumed = '';

        while (true) {
            $end = strpos($this->window, '*/', $this->cursor);

            if ($end !== false) {
                $consumed .= substr($this->window, $this->cursor, $end - $this->cursor + 2);
                $this->advance($end - $this->cursor + 2);

                return $consumed;
            }

            // Trattieni l'ultimo byte: il "*/" potrebbe essere spezzato tra
            // due chunk. Consuma il resto e riprova dopo il refill.
            $keep = max($this->cursor, strlen($this->window) - 1);
            $consumed .= substr($this->window, $this->cursor, $keep - $this->cursor);
            $this->advance($keep - $this->cursor);

            if (! $this->ensure(2)) {
                throw new MalformedDumpException(
                    "Dump troncato: commento /* */ mai chiuso (offset {$this->position()})."
                );
            }
        }
    }

    /**
     * Consuma e restituisce la run di caratteri che NON contiene nessuno dei
     * $breakChars, attraversando i confini di chunk. Può restituire ''.
     */
    private function consumeRun(string $breakChars): string
    {
        $consumed = '';

        while (true) {
            $length = strcspn($this->window, $breakChars, $this->cursor);
            $consumed .= substr($this->window, $this->cursor, $length);
            $this->advance($length);

            // Fermo su un break reale (non a fine finestra): run completa.
            if ($this->cursor < strlen($this->window)) {
                return $consumed;
            }

            if (! $this->ensure(1)) {
                return $consumed; // EOF
            }
        }
    }

    /* ------------------------------------------------------------------
     | Finestra scorrevole
     | ------------------------------------------------------------------ */

    /**
     * Garantisce almeno $bytes byte disponibili dal cursore in poi,
     * leggendo altri chunk se servono. False se il file finisce prima.
     */
    private function ensure(int $bytes): bool
    {
        while (strlen($this->window) - $this->cursor < $bytes) {
            if ($this->eof) {
                return false;
            }

            // Butta il prefisso già consumato prima di accodare altro:
            // la finestra resta O(chunk), non cresce col file.
            if ($this->cursor > self::CHUNK_SIZE) {
                $this->windowStart += $this->cursor;
                $this->window = substr($this->window, $this->cursor);
                $this->cursor = 0;
            }

            $read = fread($this->handle, self::CHUNK_SIZE);

            if ($read === false || $read === '') {
                $this->eof = true;
            } else {
                $this->window .= $read;
            }
        }

        return true;
    }

    private function peek(int $ahead): ?string
    {
        if (! $this->ensure($ahead + 1)) {
            return null;
        }

        return $this->window[$this->cursor + $ahead];
    }

    private function advance(int $bytes): void
    {
        $this->cursor += $bytes;
    }

    /** Offset assoluto (in byte) del prossimo carattere non consumato. */
    private function position(): int
    {
        return $this->windowStart + $this->cursor;
    }
}

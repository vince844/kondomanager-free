<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Errori;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ciò che di un errore si salva nel database e si mette nel rapporto (decisione 698 (3)): senza password, senza i dati dei moduli e i valori delle query.
 *
 * - **Il messaggio** ({@see messaggio()}, {@see maschera()}): di una query restano la connessione e l'SQL con i «?» anche al posto dei valori scritti
 *   dentro l'SQL, mai l'indirizzo, la porta, il nome del database, né il pezzo di SQL di un errore di sintassi («near …»); in ogni messaggio le email, i
 *   codici fiscali, gli IBAN (anche in minuscolo), i numeri lunghi, gli utenti del database, le password, i segreti e i codici d'accesso (anche come
 *   chiavi di un JSON o di un elenco) e la coda degli indirizzi diventano segnaposto, e i percorsi partono dalla cartella del programma.
 *   **I nomi delle persone e gli indirizzi di casa scritti in un messaggio possono restare**: non si riconoscono con certezza. La finestra e il rapporto
 *   lo dicono ({@see RapportoDellErrore::TOLTI}).
 * - **La traccia** ({@see traccia()}): il file, la riga e la funzione, mai i valori passati alle funzioni.
 * - **La pagina** ({@see pagina()}): la rotta con i segnaposto («/reset-password/{token}»), mai l'indirizzo vero né la sua coda; niente contenuto dei moduli,
 *   intestazioni, cookie.
 *
 * Il file del registro (`storage/logs/laravel.log`) non passa di qui: resta com'era, sul server.
 */
final class Ripulitura
{
    /** Le righe della traccia che si tengono. */
    public const RIGHE_DELLA_TRACCIA = 60;

    /** I lavori che si riconoscono dalla traccia: il loro nome dice che cosa faceva il programma quando non c'era una pagina. */
    private const LAVORI = ['App\\Jobs\\', 'App\\Console\\Commands\\', 'App\\Listeners\\', 'App\\Notifications\\', 'App\\Mail\\'];

    public static function messaggio(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $causa = $e->getPrevious();
            $testo = $causa !== null ? $causa->getMessage() : Str::before($e->getMessage(), ' (Connection:');
            $nomiFraVirgolette = in_array(config('database.connections.'.$e->getConnectionName().'.driver'), ['sqlite', 'pgsql'], true);

            // L'SQL scritto dal programma ha i «?», ma un valore può essere scritto dentro (una query a mano): anche quello diventa «?», poi le regole di
            // ogni messaggio.
            return self::maschera($testo).' (Connection: '.$e->getConnectionName().', SQL: '
                .self::maschera(self::sqlSenzaValori(self::percorsiRelativi($e->getSql()), $nomiFraVirgolette)).')';
        }

        // Un'eccezione che ne avvolge una della query (una vista che si rompe su una query la ripete nel suo messaggio, «… (View: …)») porta l'SQL con i
        // valori già scritti dentro, senza virgolette: quel pezzo diventa il messaggio ripulito della query, il resto passa dalle regole.
        $testo = $e->getMessage();
        $pulite = [];
        for ($prima = $e->getPrevious(); $prima !== null; $prima = $prima->getPrevious()) {
            if ($prima instanceof QueryException && $prima->getMessage() !== '' && str_contains($testo, $prima->getMessage())) {
                $segnaposto = "\u{E000}".count($pulite)."\u{E001}";
                $pulite[$segnaposto] = self::messaggio($prima);
                $testo = str_replace($prima->getMessage(), $segnaposto, $testo);
            }
        }

        return strtr(self::maschera($testo), $pulite);
    }

    public static function maschera(string $testo): string
    {
        $t = self::percorsiRelativi($testo);
        // I dettagli della connessione (Laravel li aggiunge al messaggio di una query).
        $t = preg_replace('/,\s*(?:Host|Port|Database|Socket):\s*[^,)]*/i', '', $t) ?? $t;
        // I valori dentro l'SQL di un messaggio scritto a mano.
        $t = preg_replace_callback('/(SQL:\s*)(.+)$/s', fn ($m) => $m[1].self::sqlSenzaValori($m[2]), $t) ?? $t;
        // L'errore di sintassi di MySQL ripete il pezzo di SQL dal punto dell'errore, con i valori scritti dentro: si tiene solo il punto.
        $t = preg_replace('/(\bnear\s+)\'.*?\'(\s+at\s+line\s+\d+)/is', '$1\'[sql]\'$2', $t) ?? $t;
        $t = preg_replace('/(Access denied for user\s+)\'[^\']*\'@\'[^\']*\'/i', '$1\'[utente]\'@\'[server]\'', $t) ?? $t;
        $t = preg_replace('/(getaddrinfo(?: for)?\s+)\S+(\s+failed)/i', '$1[server]$2', $t) ?? $t;
        $t = preg_replace('/(Duplicate entry\s+)\'[^\']*\'/i', '$1\'[valore]\'', $t) ?? $t;
        $t = preg_replace('/(value:\s*)\'[^\']*\'/i', '$1\'[valore]\'', $t) ?? $t;
        // Le credenziali dentro un indirizzo, poi la coda degli indirizzi (porta i codici d'accesso).
        $t = preg_replace('#(\b[a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1[credenziali]@', $t) ?? $t;
        $t = preg_replace('#(\bhttps?://[^\s\'"?<>]+)\?[^\s\'"<>]*#i', '$1?…', $t) ?? $t;
        // Le password, i segreti e i codici d'accesso: «password=…», «password: …», e come chiavi di un JSON («"password":"…"») o di un elenco PHP
        // («'api_key' => '…'», «[password] => …»), anche dentro un nome più lungo («client_secret», «remember_token»). Il «?» di un SQL resta.
        $t = preg_replace_callback(
            '/((["\'\[]?)\b[\w\-]*(?:password|passwd|pwd|secret|token|api[_-]?key)[\w\-]*["\'\]]?)(\s*(?:=>|[=:])\s*)(?!\?(?:[\s,;&)}\]]|$))("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s,;&)}\]]+)/i',
            fn ($m) => $m[1].$m[3].(in_array($m[4][0], ['"', '\''], true) ? $m[4][0].'[nascosto]'.$m[4][0] : '[nascosto]'),
            $t) ?? $t;
        $t = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $t) ?? $t;
        $t = preg_replace('/\b[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]\b/i', '[codice fiscale]', $t) ?? $t;
        // L'IBAN, anche scritto in minuscolo.
        $t = preg_replace('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}\b/i', '[IBAN]', $t) ?? $t;
        $t = preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}\b/', '[codice]', $t) ?? $t;
        $t = preg_replace('/\+\d{2}[\s.]?\d{2,4}[\s.]?\d{5,8}\b/', '[numero]', $t) ?? $t;
        $t = preg_replace('/(?<![\w.\/])\d{9,}(?![\w.])/', '[numero]', $t) ?? $t;

        return trim($t);
    }

    /**
     * La traccia senza i valori: per ogni riga il file (dalla cartella del programma), la riga e la funzione.
     *
     * @return list<array{file: ?string, riga: ?int, funzione: string}>
     */
    public static function traccia(Throwable $e): array
    {
        $righe = [];
        foreach (array_slice($e->getTrace(), 0, self::RIGHE_DELLA_TRACCIA) as $passo) {
            $righe[] = [
                'file' => isset($passo['file']) ? self::percorso((string) $passo['file']) : null,
                'riga' => isset($passo['line']) ? (int) $passo['line'] : null,
                'funzione' => (isset($passo['class']) ? $passo['class'].($passo['type'] ?? '->') : '').($passo['function'] ?? '').'()',
            ];
        }

        return $righe;
    }

    /**
     * Il punto del programma dove l'errore nasce: la prima riga che non è del framework (le cartelle del programma), o quella dell'eccezione.
     *
     * @return array{file: ?string, riga: ?int}
     */
    public static function puntoDelProgramma(Throwable $e): array
    {
        $candidati = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($candidati as $passo) {
            $file = isset($passo['file']) ? self::percorso((string) $passo['file']) : null;
            if ($file !== null && ! str_starts_with($file, 'vendor/') && ! str_starts_with($file, '/')) {
                return ['file' => $file, 'riga' => isset($passo['line']) ? (int) $passo['line'] : null];
            }
        }

        return ['file' => self::percorso($e->getFile()), 'riga' => $e->getLine()];
    }

    /** La causa: l'eccezione di prima, con il suo messaggio ripulito come quello dell'errore (di una query senza i valori). */
    public static function causa(Throwable $e): ?string
    {
        $prima = $e->getPrevious();

        return $prima === null ? null : get_class($prima).': '.self::messaggio($prima);
    }

    /** L'impronta: la stessa per lo stesso errore, in ogni installazione con la stessa versione. Senza numeri né valori nel messaggio. */
    public static function impronta(Throwable $e, string $messaggio): string
    {
        $punto = self::puntoDelProgramma($e);
        $senzaValori = preg_replace(['/\'[^\']*\'|"[^"]*"/', '/\d+/'], ['?', '#'], $messaggio) ?? $messaggio;

        return sha1(implode("\n", [get_class($e), (string) $punto['file'], (string) $punto['riga'], $senzaValori, (string) config('app.version')]));
    }

    /**
     * La pagina e l'azione: la rotta con i segnaposto, il suo nome, il controller e il metodo. Senza una rotta (un lavoro in background, un comando) la
     * pagina non c'è e l'azione dice il lavoro.
     *
     * @return array{metodo: ?string, pagina: ?string, rotta: ?string, azione: ?string}
     */
    public static function pagina(Throwable $e, ?Request $richiesta): array
    {
        $rotta = $richiesta?->route();
        if ($rotta instanceof Route) {
            $azione = $rotta->getActionName();

            return [
                'metodo' => $richiesta->getMethod(),
                'pagina' => '/'.ltrim($rotta->uri(), '/'),
                'rotta' => $rotta->getName(),
                'azione' => str_contains($azione, '@') ? class_basename(Str::before($azione, '@')).'@'.Str::after($azione, '@') : class_basename($azione),
            ];
        }
        if ($richiesta !== null && ! app()->runningInConsole()) {
            // Una richiesta che si è fermata prima della rotta: il metodo sì, la pagina no (l'indirizzo vero non si salva).
            return ['metodo' => $richiesta->getMethod(), 'pagina' => null, 'rotta' => null, 'azione' => null];
        }

        return ['metodo' => null, 'pagina' => null, 'rotta' => null, 'azione' => self::lavoro($e)];
    }

    public static function percorso(string $file): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private static function percorsiRelativi(string $testo): string
    {
        return str_replace(rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, '', $testo);
    }

    /**
     * Le stringhe e i numeri di un SQL diventano «?». Fra virgolette doppie su MySQL c'è una stringa; su SQLite e PostgreSQL il nome di una tabella o di una
     * colonna, che resta (`$nomiFraVirgolette`). I numeri di `limit` e `offset` restano: li scrive il programma per le pagine, non sono dati.
     */
    private static function sqlSenzaValori(string $sql, bool $nomiFraVirgolette = false): string
    {
        $stringhe = $nomiFraVirgolette ? '/\'(?:[^\'\\\\]|\\\\.)*\'/' : '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/';
        $sql = preg_replace($stringhe, '?', $sql) ?? $sql;

        return preg_replace_callback('/\b(?:limit|offset)\s+\d+(?:\s*,\s*\d+)?|(?<![\w`.])-?\d+(?:\.\d+)?(?![\w`])/i',
            fn ($m) => ctype_digit($m[0][0]) || $m[0][0] === '-' ? '?' : $m[0], $sql) ?? $sql;
    }

    /** Che cosa faceva il programma senza una pagina: il lavoro della traccia, o il comando. */
    private static function lavoro(Throwable $e): string
    {
        foreach ($e->getTrace() as $passo) {
            $classe = (string) ($passo['class'] ?? '');
            foreach (self::LAVORI as $prefisso) {
                if (str_starts_with($classe, $prefisso)) {
                    return 'Lavoro in background: '.class_basename($classe);
                }
            }
        }
        $argomenti = $_SERVER['argv'] ?? [];
        if (isset($argomenti[0], $argomenti[1]) && str_ends_with((string) $argomenti[0], 'artisan')) {
            return 'Lavoro in background: '.Str::limit((string) $argomenti[1], 100, '');
        }

        return 'Lavoro in background';
    }
}

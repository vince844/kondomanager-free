<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use Illuminate\Validation\ValidationException;

/**
 * La struttura del testo del verbale (V1): **il testo si salva come struttura, mai come HTML libero** (decisione 515). I nodi sono quelli della barra
 * dell'editor: il documento, il paragrafo, il titolo (un livello solo, il 2), l'elenco puntato e quello numerato con le loro voci, il testo, l'a capo dentro
 * il paragrafo; più la parte «Dati della sala» (`datiDellaSala`, con la sua chiave e il testo com'era alla generazione), che il programma scrive con i voti,
 * i nomi e i millesimi. I segni: grassetto, corsivo, sottolineato. **Nient'altro**: un nodo, un segno, un attributo o un campo sconosciuto si rifiuta con la
 * sua frase, e la parte non si salva; una parte al più 1 MB, al più dodici livelli uno dentro l'altro e ventimila nodi.
 *
 * {@see valida()} rende la struttura nella forma canonica (le chiavi in un ordine fisso, i segni in ordine e una volta, il contenuto vuoto tolto, gli
 * attributi di serie tolti); {@see canonico()} il suo JSON, {@see impronta()} lo SHA-256 di quel JSON, {@see testo()} il testo semplice. Le stesse regole e
 * le stesse frasi in `resources/js/lib/assemblee/verbale/struttura.ts`, sul file di casi in comune `tests/Fixtures/verbale/struttura.json`.
 */
final class StrutturaDelVerbale
{
    public const BYTE_MASSIMI = 1048576;

    public const PROFONDITA_MASSIMA = 12;

    public const NODI_MASSIMI = 20000;

    /** I nodi che ognuno può contenere. */
    private const DENTRO = [
        'doc' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'datiDellaSala'],
        'datiDellaSala' => ['paragraph', 'heading', 'bulletList', 'orderedList'],
        'paragraph' => ['text', 'hardBreak'],
        'heading' => ['text', 'hardBreak'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'text' => [],
        'hardBreak' => [],
    ];

    /** I campi di ogni nodo. */
    private const CAMPI = [
        'doc' => ['type', 'content'],
        'paragraph' => ['type', 'content'],
        'heading' => ['type', 'attrs', 'content'],
        'bulletList' => ['type', 'content'],
        'orderedList' => ['type', 'attrs', 'content'],
        'listItem' => ['type', 'content'],
        'datiDellaSala' => ['type', 'attrs', 'content'],
        'text' => ['type', 'text', 'marks'],
        'hardBreak' => ['type'],
    ];

    /** I segni della barra, nel loro ordine. */
    public const SEGNI = ['bold', 'italic', 'underline'];

    /** Il livello del titolo: uno solo. */
    public const LIVELLO_DEL_TITOLO = 2;

    private const CHIAVE = '/^[a-z_]+(:[a-z0-9_-]+)*$/';

    public const FORMA = 'Il testo del verbale non è nella forma dell\'editor.';

    /**
     * La struttura nella forma canonica, o la frase del primo errore (in profondità, nell'ordine dei nodi).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException con la frase sul campo `documento`
     */
    public static function valida(mixed $documento): array
    {
        try {
            return self::canonica($documento);
        } catch (StrutturaNonValida $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }
    }

    /** La frase dell'errore, o null se la struttura va bene. */
    public static function errore(mixed $documento): ?string
    {
        try {
            self::canonica($documento);

            return null;
        } catch (StrutturaNonValida $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string, mixed> $documento già valido */
    public static function canonico(array $documento): string
    {
        return (string) json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** Lo SHA-256 della forma canonica. @param array<string, mixed> $documento già valido */
    public static function impronta(array $documento): string
    {
        return hash('sha256', self::canonico($documento));
    }

    /**
     * Il testo semplice: il testo dei paragrafi e dei titoli (l'a capo dentro il paragrafo è un a capo), i blocchi uno per riga, le voci degli elenchi una per
     * riga, senza segni né numeri.
     *
     * @param array<string, mixed> $nodo
     */
    public static function testo(array $nodo): string
    {
        $tipo = (string) ($nodo['type'] ?? '');
        if ($tipo === 'text') {
            return (string) ($nodo['text'] ?? '');
        }
        if ($tipo === 'hardBreak') {
            return "\n";
        }
        $figli = array_map(fn ($c) => self::testo((array) $c), (array) ($nodo['content'] ?? []));

        return implode(in_array($tipo, ['paragraph', 'heading'], true) ? '' : "\n", $figli);
    }

    /** I caratteri del testo semplice (i punti di codice, come `[...testo].length` nel browser). @param array<string, mixed> $documento */
    public static function caratteri(array $documento): int
    {
        return mb_strlen(self::testo($documento));
    }

    /**
     * Le parti «Dati della sala» del documento, chiave => testo semplice, nell'ordine: le frasi con i dati che il programma confronta con la sala.
     *
     * @param array<string, mixed> $documento
     * @return array<string, string>
     */
    public static function blocchi(array $documento): array
    {
        $blocchi = [];
        foreach ((array) ($documento['content'] ?? []) as $nodo) {
            if (($nodo['type'] ?? null) === 'datiDellaSala') {
                $blocchi[(string) $nodo['attrs']['chiave']] = self::testo((array) $nodo);
            }
        }

        return $blocchi;
    }

    // I mattoni del programma, già nella forma canonica.

    /** @param list<array<string, mixed>> $blocchi @return array<string, mixed> */
    public static function documento(array $blocchi): array
    {
        return $blocchi === [] ? ['type' => 'doc'] : ['type' => 'doc', 'content' => array_values($blocchi)];
    }

    /** Un paragrafo con il testo dato (gli a capo diventano a capo nel paragrafo); vuoto, un paragrafo vuoto. @return array<string, mixed> */
    public static function paragrafo(string $testo): array
    {
        $righe = explode("\n", str_replace("\r\n", "\n", $testo));
        $contenuto = [];
        foreach ($righe as $i => $riga) {
            if ($i > 0) {
                $contenuto[] = ['type' => 'hardBreak'];
            }
            if ($riga !== '') {
                $contenuto[] = ['type' => 'text', 'text' => str_replace("\0", '', $riga)];
            }
        }

        return $contenuto === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $contenuto];
    }

    /** @return array<string, mixed> */
    public static function titolo(string $testo): array
    {
        $paragrafo = self::paragrafo($testo);

        return ['type' => 'heading', 'attrs' => ['level' => self::LIVELLO_DEL_TITOLO]] + (isset($paragrafo['content']) ? ['content' => $paragrafo['content']] : []);
    }

    /**
     * Una parte «Dati della sala»: un paragrafo per frase, con la chiave (`costituzione:12`, `voto:7`) e il testo com'era alla generazione.
     *
     * @param list<string> $frasi
     * @return array<string, mixed>
     */
    public static function datiDellaSala(string $chiave, array $frasi): array
    {
        $paragrafi = array_map(fn (string $f) => self::paragrafo($f), $frasi === [] ? [''] : $frasi);
        $nodo = ['type' => 'datiDellaSala', 'attrs' => ['chiave' => $chiave, 'originale' => '']] + ['content' => $paragrafi];
        $nodo['attrs']['originale'] = mb_substr(self::testo($nodo), 0, 20000);

        return $nodo;
    }

    /** @return array<string, mixed> */
    private static function canonica(mixed $documento): array
    {
        if (! is_array($documento) || array_is_list($documento) && $documento !== []) {
            throw new StrutturaNonValida(self::FORMA);
        }
        // Un testo molto oltre il tetto non si percorre nemmeno: la frase è la stessa del controllo finale.
        $grezzo = json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($grezzo === false) {
            throw new StrutturaNonValida(self::FORMA);
        }
        if (strlen($grezzo) > 2 * self::BYTE_MASSIMI) {
            throw new StrutturaNonValida('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
        }
        $conta = 0;
        $canonica = self::nodo($documento, null, 1, $conta);
        if (strlen(self::canonico($canonica)) > self::BYTE_MASSIMI) {
            throw new StrutturaNonValida('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
        }

        return $canonica;
    }

    /** @return array<string, mixed> */
    private static function nodo(mixed $n, ?string $madre, int $profondita, int &$conta): array
    {
        if (! is_array($n) || ($n !== [] && array_is_list($n)) || ! is_string($n['type'] ?? null)) {
            throw new StrutturaNonValida(self::FORMA);
        }
        if ($profondita > self::PROFONDITA_MASSIMA) {
            throw new StrutturaNonValida('Il testo del verbale ha troppi livelli uno dentro l\'altro.');
        }
        $tipo = $n['type'];
        if (! isset(self::DENTRO[$tipo])) {
            throw new StrutturaNonValida('Il testo del verbale ha una parte che il programma non conosce: «' . $tipo . '».');
        }
        if ($madre === null && $tipo !== 'doc') {
            throw new StrutturaNonValida('Il testo del verbale deve cominciare con il documento.');
        }
        if ($madre !== null && ! in_array($tipo, self::DENTRO[$madre], true)) {
            throw new StrutturaNonValida('Nel testo del verbale «' . $tipo . '» non può stare dentro «' . $madre . '».');
        }
        foreach (array_keys($n) as $campo) {
            if (in_array($campo, self::CAMPI[$tipo], true)) {
                continue;
            }
            if ($campo === 'attrs' && is_array($n['attrs'])) {
                if ($n['attrs'] === []) {
                    continue;
                }
                if (! array_is_list($n['attrs'])) {
                    throw new StrutturaNonValida('Nel testo del verbale «' . $tipo . '» ha un attributo che il programma non conosce: «' . array_key_first($n['attrs']) . '».');
                }
            }
            throw new StrutturaNonValida('Nel testo del verbale «' . $tipo . '» ha un campo che il programma non conosce: «' . $campo . '».');
        }
        if (++$conta > self::NODI_MASSIMI) {
            throw new StrutturaNonValida('Il testo del verbale ha troppe parti.');
        }
        $attributi = self::attributi($tipo, $n);
        if ($tipo === 'text') {
            return self::pezzoDiTesto($n);
        }
        if ($tipo === 'hardBreak') {
            return ['type' => 'hardBreak'];
        }
        $contenuto = $n['content'] ?? [];
        if (! is_array($contenuto) || ($contenuto !== [] && ! array_is_list($contenuto))) {
            throw new StrutturaNonValida(self::FORMA);
        }
        $figli = [];
        foreach ($contenuto as $figlio) {
            $figli[] = self::nodo($figlio, $tipo, $profondita + 1, $conta);
        }
        if (in_array($tipo, ['bulletList', 'orderedList'], true) && $figli === []) {
            throw new StrutturaNonValida('Nel testo del verbale c\'è un elenco vuoto.');
        }
        if ($tipo === 'listItem' && ($figli === [] || $figli[0]['type'] !== 'paragraph')) {
            throw new StrutturaNonValida('Ogni voce di un elenco comincia con un paragrafo.');
        }
        if ($tipo === 'datiDellaSala' && $figli === []) {
            throw new StrutturaNonValida('Una parte «Dati della sala» non può essere vuota.');
        }

        return ['type' => $tipo] + ($attributi !== null ? ['attrs' => $attributi] : []) + ($figli !== [] ? ['content' => $figli] : []);
    }

    /** Gli attributi del nodo nella forma canonica, o null se non ne ha. @param array<string, mixed> $n @return ?array<string, mixed> */
    private static function attributi(string $tipo, array $n): ?array
    {
        $attrs = $n['attrs'] ?? null;
        if ($tipo === 'heading') {
            if (is_array($attrs)) {
                foreach (array_keys($attrs) as $k) {
                    if ($k !== 'level') {
                        throw new StrutturaNonValida('Nel testo del verbale «heading» ha un attributo che il programma non conosce: «' . $k . '».');
                    }
                }
            }
            if (! is_array($attrs) || ! is_int($attrs['level'] ?? null) || $attrs['level'] !== self::LIVELLO_DEL_TITOLO) {
                throw new StrutturaNonValida('Il titolo ha un livello solo: il 2.');
            }

            return ['level' => self::LIVELLO_DEL_TITOLO];
        }
        if ($tipo === 'orderedList') {
            if ($attrs === null) {
                return null;
            }
            if (! is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
                throw new StrutturaNonValida(self::FORMA);
            }
            foreach (array_keys($attrs) as $k) {
                if (! in_array($k, ['start', 'type'], true)) {
                    throw new StrutturaNonValida('Nel testo del verbale «orderedList» ha un attributo che il programma non conosce: «' . $k . '».');
                }
            }
            $da = $attrs['start'] ?? 1;
            if (! is_int($da) || $da < 1 || $da > 100000 || ($attrs['type'] ?? null) !== null) {
                throw new StrutturaNonValida('Un elenco numerato ha solo il numero da cui parte.');
            }

            return $da === 1 ? null : ['start' => $da];
        }
        if ($tipo === 'datiDellaSala') {
            if (! is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
                throw new StrutturaNonValida('Una parte «Dati della sala» deve avere la sua chiave.');
            }
            foreach (array_keys($attrs) as $k) {
                if (! in_array($k, ['chiave', 'originale'], true)) {
                    throw new StrutturaNonValida('Nel testo del verbale «datiDellaSala» ha un attributo che il programma non conosce: «' . $k . '».');
                }
            }
            $chiave = $attrs['chiave'] ?? null;
            if (! is_string($chiave) || $chiave === '') {
                throw new StrutturaNonValida('Una parte «Dati della sala» deve avere la sua chiave.');
            }
            if (mb_strlen($chiave) > 80 || preg_match(self::CHIAVE, $chiave) !== 1) {
                throw new StrutturaNonValida('La chiave di una parte «Dati della sala» non è nella forma giusta.');
            }
            $originale = $attrs['originale'] ?? '';
            if (! is_string($originale) || mb_strlen($originale) > 20000) {
                throw new StrutturaNonValida(self::FORMA);
            }

            return ['chiave' => $chiave, 'originale' => $originale];
        }

        return null;
    }

    /** @param array<string, mixed> $n @return array<string, mixed> */
    private static function pezzoDiTesto(array $n): array
    {
        $testo = $n['text'] ?? null;
        if (! is_string($testo)) {
            throw new StrutturaNonValida(self::FORMA);
        }
        if ($testo === '') {
            throw new StrutturaNonValida('Nel testo del verbale c\'è un pezzo di testo vuoto.');
        }
        if (str_contains($testo, "\0")) {
            throw new StrutturaNonValida('Il testo del verbale ha un carattere che non si può salvare.');
        }
        $segni = $n['marks'] ?? [];
        if (! is_array($segni) || ($segni !== [] && ! array_is_list($segni))) {
            throw new StrutturaNonValida(self::FORMA);
        }
        $visti = [];
        foreach ($segni as $segno) {
            if (! is_array($segno) || ! is_string($segno['type'] ?? null)) {
                throw new StrutturaNonValida(self::FORMA);
            }
            if (! in_array($segno['type'], self::SEGNI, true)) {
                throw new StrutturaNonValida('Il testo del verbale ha un formato che il programma non conosce: «' . $segno['type'] . '».');
            }
            foreach (array_keys($segno) as $k) {
                if ($k === 'type' || ($k === 'attrs' && $segno['attrs'] === [])) {
                    continue;
                }
                throw new StrutturaNonValida('Il formato «' . $segno['type'] . '» ha un campo che il programma non conosce: «' . $k . '».');
            }
            $visti[$segno['type']] = true;
        }
        $ordinati = array_values(array_filter(self::SEGNI, fn ($s) => isset($visti[$s])));

        return ['type' => 'text', 'text' => $testo] + ($ordinati !== [] ? ['marks' => array_map(fn ($s) => ['type' => $s], $ordinati)] : []);
    }
}

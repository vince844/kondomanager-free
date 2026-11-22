<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Due funzioni con lo stesso nome in due file di prova fermano la suite intera
 *
 * Le prove di Pest dichiarano spesso un aiuto fuori dalle classi (`function cdpPagina(…)`). PHP non sa che due file di prova sono due mondi: quando la suite
 * li carica tutti e due, il secondo fa «Cannot redeclare function» e la suite si ferma lì, senza dire quante prove avrebbe passato. Ogni file da solo è
 * verde, ed è per questo che il difetto si vede solo nella suite intera (la notte del 21/11/2026, con `cdpPagina`).
 *
 * Questa prova legge ogni file di `tests/` con il tokenizzatore di PHP e raccoglie i nomi delle funzioni dichiarate fuori da una classe, un'interfaccia, un
 * tratto o un enum (non le funzioni anonime, né i metodi). Una funzione dichiarata dentro un `if (! function_exists('…'))` si salta: è scritta apposta per
 * stare in due posti. Un nome che compare in due file è un errore, con i due file.
 */
function fdtFunzioniDichiarate(string $codice): array
{
    $token = token_get_all($codice);
    $nomi = [];
    $profondita = 0;
    /** Le profondità delle parentesi graffe che aprono una classe, un'interfaccia, un tratto o un enum, o il corpo di un `if (! function_exists(…))`. */
    $chiuse = [];
    $attesa = null;
    $condizione = false;
    $quanti = count($token);
    for ($i = 0; $i < $quanti; $i++) {
        $t = $token[$i];
        // «{$nome}» e «${nome}» dentro una stringa aprono una graffa che si chiude con una «}» semplice.
        if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $t = '{';
        }
        if (is_array($t)) {
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // `Foo::class` non apre niente.
                $prima = $i > 0 ? $token[$i - 1] : null;
                if (! (is_array($prima) && $prima[0] === T_DOUBLE_COLON)) {
                    $attesa = 'blocco';
                }
            } elseif ($t[0] === T_STRING && strtolower($t[1]) === 'function_exists') {
                $condizione = true;
            } elseif ($t[0] === T_FUNCTION && $chiuse === []) {
                // Il nome viene dopo gli spazi (e un eventuale `&`); una funzione anonima non ha nome.
                $j = $i + 1;
                while ($j < $quanti && (is_array($token[$j]) ? in_array($token[$j][0], [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true) : $token[$j] === '&')) {
                    $j++;
                }
                if ($j < $quanti && is_array($token[$j]) && $token[$j][0] === T_STRING) {
                    $nomi[] = ['nome' => $token[$j][1], 'riga' => $token[$j][2]];
                }
            }

            continue;
        }
        if ($t === '{') {
            $profondita++;
            if ($attesa !== null || $condizione) {
                $chiuse[] = $profondita;
                $attesa = null;
                $condizione = false;
            }
        } elseif ($t === '}') {
            if ($chiuse !== [] && end($chiuse) === $profondita) {
                array_pop($chiuse);
            }
            $profondita--;
        } elseif ($t === ';') {
            $condizione = false;
        }
    }

    return $nomi;
}

/** @return array<string, list<string>> nome in minuscolo => i file (con la riga) che lo dichiarano, solo i nomi con più di un file */
function fdtNomiDoppi(string $cartella): array
{
    $dove = [];
    $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cartella, FilesystemIterator::SKIP_DOTS));
    foreach ($iteratore as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $percorso = $file->getPathname();
        foreach (fdtFunzioniDichiarate((string) file_get_contents($percorso)) as $f) {
            $dove[strtolower($f['nome'])][] = substr($percorso, strlen(base_path()) + 1) . ':' . $f['riga'];
        }
    }

    return array_filter($dove, fn (array $file) => count(array_unique(array_map(fn ($x) => explode(':', $x)[0], $file))) > 1);
}

it('nessuna funzione dichiarata fuori dalle classi ha lo stesso nome in due file di tests/', function () {
    $doppi = fdtNomiDoppi(base_path('tests'));
    $righe = array_map(fn ($nome, $file) => $nome . ': ' . implode(', ', $file), array_keys($doppi), $doppi);

    expect($righe)->toBe([], "Due file di prova dichiarano la stessa funzione: la suite intera si fermerebbe con «Cannot redeclare». Rinomina una delle due:\n"
        . implode("\n", $righe));
});

it('il lettore riconosce le funzioni fuori dalle classi e salta metodi, funzioni anonime e quelle dietro function_exists', function () {
    $codice = <<<'PHP'
    <?php
    function primaFuori(int $a): int { return $a; }
    $f = function () { return 1; };
    $g = fn () => 2;
    final class Aiuto { public function metodo(): void { $h = function () {}; } public static function statico(): string { return Aiuto::class; } }
    if (! function_exists('dietroLaGuardia')) { function dietroLaGuardia(): void {} }
    enum Colore: string { case Rosso = 'r'; public function parola(): string { return 'rosso'; } }
    function &perRiferimento(array &$x): array { return $x; }
    function conLaStringa(string $nome): string { return "Piano dei conti {$nome}"; }
    function ultimaFuori(): void {}
    PHP;

    expect(array_column(fdtFunzioniDichiarate($codice), 'nome'))->toBe(['primaFuori', 'perRiferimento', 'conLaStringa', 'ultimaFuori']);
});

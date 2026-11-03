<?php

/**
 * # I messaggi che il server scrive all'amministratore non citano numeri di beta
 *
 * Il gemello lato server di `GuideSenzaNumeriDiBeta.test.ts`. Chi aggiorna alla 1.11.0 dalla 1.10.0 non ha mai visto le
 * beta: «registrato prima della 1.11.0-beta.37» non gli dice niente. Un messaggio che ha bisogno di una data dice che cosa
 * il dato è («registrato da una versione che non teneva il registro») o la versione vera (la 1.10.0, la 1.11).
 *
 * Si leggono i **testi**, non i commenti: nei commenti il numero della beta è la memoria di chi scrive il codice e resta dov'è.
 * Per i file PHP si usa il tokenizzatore di PHP, che i commenti li distingue da solo; per le viste Blade si tolgono prima
 * `{{-- … --}}`, i commenti HTML e quelli PHP. Si guardano `app/`, `lang/` e `resources/views/`.
 *
 * Fuori da questa guardia, di proposito: `database/migrations/`, dove i numeri stanno nei commenti delle colonne (metadati del
 * database che nessun amministratore legge) e dove una migrazione non si riscrive.
 */

/** Un numero di beta, o la forma «X.Y.Z-beta». «beta» da sola, o «N beta fa», non è un numero di beta. */
const MNB_NUMERO_DI_BETA = '/beta[. ]?\d+|\d+\.\d+\.\d+-beta/i';

/**
 * Le stringhe di un sorgente PHP che citano una beta, con la riga.
 *
 * @return list<string>
 */
function mnbStringheConBeta(string $sorgente, string $etichetta = 'sonda'): array
{
    $trovate = [];

    foreach (token_get_all($sorgente) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match(MNB_NUMERO_DI_BETA, $token[1])) {
            $trovate[] = sprintf('%s:%d  %s', $etichetta, $token[2], mb_substr(trim(preg_replace('/\s+/', ' ', $token[1])), 0, 120));
        }
    }

    return $trovate;
}

/**
 * Le righe di una vista Blade che citano una beta fuori dai commenti.
 *
 * @return list<string>
 */
function mnbRigheBladeConBeta(string $sorgente, string $etichetta = 'sonda'): array
{
    $sorgente = preg_replace('/\{\{--[\s\S]*?--\}\}/', '', $sorgente) ?? $sorgente;
    $sorgente = preg_replace('/<!--[\s\S]*?-->/', '', $sorgente) ?? $sorgente;
    $sorgente = preg_replace('#/\*[\s\S]*?\*/#', '', $sorgente) ?? $sorgente;
    $sorgente = preg_replace('/^[ \t]*(\/\/|#).*$/m', '', $sorgente) ?? $sorgente;

    $trovate = [];
    foreach (explode("\n", $sorgente) as $i => $riga) {
        if (preg_match(MNB_NUMERO_DI_BETA, $riga)) {
            $trovate[] = sprintf('%s:%d  %s', $etichetta, $i + 1, mb_substr(trim($riga), 0, 120));
        }
    }

    return $trovate;
}

/** @return list<string> i percorsi dei file con quell'estensione sotto `$cartella` */
function mnbFile(string $cartella, string $estensione): array
{
    $file = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cartella, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), $estensione)) {
            $file[] = $f->getPathname();
        }
    }
    sort($file);

    return $file;
}

it('nessun testo di app/ e di lang/ cita un numero di beta', function () {
    $trovate = [];

    foreach ([app_path(), lang_path()] as $cartella) {
        foreach (mnbFile($cartella, '.php') as $percorso) {
            $trovate = array_merge($trovate, mnbStringheConBeta(file_get_contents($percorso), str_replace(base_path().'/', '', $percorso)));
        }
    }

    expect($trovate)->toBe([], sprintf(
        "%d testi con un numero di beta: chi sale dalla 1.10.0 le beta non le ha mai viste.\n"
        ."Si dice che cosa il dato è («registrato da una versione più vecchia») o la versione vera (la 1.10.0, la 1.11).\n\n%s\n",
        count($trovate),
        implode("\n", $trovate),
    ));
});

it('nessuna vista cita un numero di beta fuori dai commenti', function () {
    $trovate = [];

    foreach (mnbFile(resource_path('views'), '.blade.php') as $percorso) {
        $trovate = array_merge($trovate, mnbRigheBladeConBeta(file_get_contents($percorso), str_replace(base_path().'/', '', $percorso)));
    }

    expect($trovate)->toBe([]);
});

it('la guardia morde: un numero di beta in una stringa viene trovato, uno in un commento no', function () {
    $sonda = <<<'PHP'
    <?php
    // registrato prima della 1.11.0-beta.37
    /* e prima della beta.19 */
    $a = 'Questo passaggio è stato registrato prima della 1.11.0-beta.37';
    $b = "Riallineamento fondi (beta.19) — rettifica {$x}";
    $c = 'registrato da una versione più vecchia, e N beta fa';
    PHP;

    expect(mnbStringheConBeta($sonda))->toHaveCount(2);

    $vista = "{{-- 1.11.0-beta.51 --}}\n<p>ok</p>\n<p>dalla beta.43 in poi</p>\n";
    expect(mnbRigheBladeConBeta($vista))->toHaveCount(1);
});

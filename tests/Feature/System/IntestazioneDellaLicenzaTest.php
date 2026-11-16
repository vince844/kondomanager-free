<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'intestazione della licenza è di tre righe, sempre le stesse: l'identificativo SPDX, l'autore con il collegamento al sorgente, il rimando ai termini aggiunti
 * del `NOTICE` (sezione 7(b) e 7(c) dell'AGPL, che chiede proprio che un file dica dove sono). Ogni file sorgente che comincia l'intestazione con la riga SPDX ha
 * anche le altre due, subito sotto, identiche e con lo stesso segno di commento: un'intestazione a metà o riscritta a mano non dice più ciò che deve dire.
 *
 * Questa guardia guarda i file che l'intestazione ce l'hanno; quella che la chiede a ogni file arriva quando tutti i file l'avranno.
 */

/** Le tre righe, nel loro ordine, senza il segno del commento. */
const IDL_RIGHE = [
    'SPDX-License-Identifier: AGPL-3.0-or-later',
    'Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free',
    'Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.',
];

/** Le cartelle dei sorgenti e le estensioni che si leggono. */
const IDL_CARTELLE = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'tests'];
const IDL_ESTENSIONI = ['php', 'vue', 'ts', 'js', 'css'];

/** Le cartelle che la guardia non legge, con la ragione: `bootstrap/cache/` è generato, `tests/Lab/` è il laboratorio (copia privata non tracciata, solo in TEST). */
const IDL_ESCLUSE = ['bootstrap/cache/', 'tests/Lab/'];

/**
 * I file dei sorgenti sotto una radice (le cartelle e le estensioni della guardia, tolte le esclusioni), con le righe di ciascuno.
 *
 * @return array<string, list<string>> il percorso (relativo alla radice) => le righe del file
 */
function idlFileDeiSorgenti(?string $radiceDelProdotto = null): array
{
    $base = rtrim($radiceDelProdotto ?? base_path(), '/');
    $file = [];
    foreach (IDL_CARTELLE as $cartella) {
        $radice = $base . '/' . $cartella;
        if (! is_dir($radice)) {
            continue;
        }
        $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iteratore as $f) {
            $percorso = str_replace($base . '/', '', $f->getPathname());
            if (! $f->isFile() || ! in_array($f->getExtension(), IDL_ESTENSIONI, true)) {
                continue;
            }
            foreach (IDL_ESCLUSE as $esclusa) {
                if (str_starts_with($percorso, $esclusa)) {
                    continue 2;
                }
            }
            $file[$percorso] = preg_split('/\r\n|\n/', (string) file_get_contents($f->getPathname()));
        }
    }

    return $file;
}

/**
 * I file dei sorgenti con una riga d'intestazione SPDX (la riga comincia, dopo il segno del commento, con «SPDX-License-Identifier:»).
 *
 * @return array<string, list<string>> il percorso => le righe del file
 */
function idlFileConLIntestazione(): array
{
    return array_filter(idlFileDeiSorgenti(), fn (array $righe) => (bool) preg_grep('/^[ \t]*(?:\/\/|\/\*|#|\*|<!--|\{\{--)?[ \t]*SPDX-License-Identifier:/', $righe));
}

/**
 * I file dei sorgenti sotto una radice la cui intestazione manca o non è intera, con che cosa non va.
 *
 * @return array<string, string> il percorso => il difetto
 */
function idlFileSenzaIntestazione(?string $radiceDelProdotto = null): array
{
    $difetti = [];
    foreach (idlFileDeiSorgenti($radiceDelProdotto) as $percorso => $righe) {
        $difetto = idlDifetto($righe);
        if ($difetto !== null) {
            $difetti[$percorso] = $difetto;
        }
    }

    return $difetti;
}

/** Che cosa non va nell'intestazione di un file, o null se è intera. */
function idlDifetto(array $righe): ?string
{
    foreach ($righe as $n => $riga) {
        if (! preg_match('/^([ \t]*(?:\/\/|\/\*|#|\*|<!--|\{\{--)?[ \t]*)SPDX-License-Identifier:/', $riga, $m)) {
            continue;
        }
        $segno = $m[1];
        foreach (IDL_RIGHE as $k => $attesa) {
            $letta = rtrim((string) ($righe[$n + $k] ?? ''));
            if ($letta !== $segno . $attesa) {
                return 'riga ' . ($n + $k + 1) . ': «' . $letta . '» invece di «' . $segno . $attesa . '»';
            }
        }

        return null;
    }

    return 'nessuna riga SPDX';
}

it('ogni file con la riga SPDX ha anche le altre due righe dell\'intestazione, identiche e subito sotto', function () {
    $file = idlFileConLIntestazione();
    // La guardia legge davvero i sorgenti: c'è almeno questo file, e un file Vue con il commento HTML.
    expect($file)->toHaveKey('tests/Feature/System/IntestazioneDellaLicenzaTest.php')
        ->and(collect(array_keys($file))->contains(fn ($p) => str_ends_with($p, '.vue')))->toBeTrue();

    $difetti = collect($file)->map(fn ($righe) => idlDifetto($righe))->filter()->map(fn ($d, $p) => "{$p}, {$d}")->values()->all();

    expect($difetti)->toBe([], "Intestazioni a metà o diverse:\n" . implode("\n", $difetti));
});

it('la guardia riconosce un\'intestazione a metà, una riga cambiata e un segno di commento diverso', function () {
    $intera = ['<?php', '// ' . IDL_RIGHE[0], '// ' . IDL_RIGHE[1], '// ' . IDL_RIGHE[2], ''];
    $vue = ['<!--', IDL_RIGHE[0], IDL_RIGHE[1], IDL_RIGHE[2], '-->'];

    expect(idlDifetto($intera))->toBeNull()
        ->and(idlDifetto($vue))->toBeNull()
        ->and(idlDifetto(['<?php', '// ' . IDL_RIGHE[0], '']))->toContain('riga 3')
        ->and(idlDifetto(['<?php', '// ' . IDL_RIGHE[0], '// Copyright (C) 2026 Qualcun altro', '// ' . IDL_RIGHE[2]]))->toContain('riga 3')
        ->and(idlDifetto(['<?php', '// ' . IDL_RIGHE[0], '# ' . IDL_RIGHE[1], '// ' . IDL_RIGHE[2]]))->toContain('riga 3');
});

it('ogni file sorgente ha l\'intestazione intera, nella forma del suo linguaggio', function () {
    $tutti = idlFileDeiSorgenti();
    // La guardia legge davvero tutto: migliaia di file, con PHP, Blade, Vue, TypeScript, JavaScript e CSS.
    expect(count($tutti))->toBeGreaterThan(3000)
        ->and(collect(array_keys($tutti))->contains(fn ($p) => str_ends_with($p, '.blade.php')))->toBeTrue()
        ->and(collect(array_keys($tutti))->contains(fn ($p) => str_ends_with($p, '.css')))->toBeTrue()
        ->and(collect(array_keys($tutti))->contains(fn ($p) => str_starts_with($p, 'bootstrap/cache/') || str_starts_with($p, 'tests/Lab/')))->toBeFalse();

    $mancano = collect(idlFileSenzaIntestazione())->map(fn ($d, $p) => "{$p}, {$d}")->values()->all();

    expect($mancano)->toBe([], "File senza l'intestazione della licenza (la mette python3 docs/strumenti/intestazione_licenza.py --esegui):\n" . implode("\n", $mancano));
});

it('la guardia su tutti i file morde: in una cartella con un file senza intestazione lo trova, e non trova quello con l\'intestazione', function () {
    $radice = sys_get_temp_dir() . '/idl_' . bin2hex(random_bytes(6));
    mkdir($radice . '/app', 0777, true);
    mkdir($radice . '/bootstrap/cache', 0777, true);
    mkdir($radice . '/tests/Lab', 0777, true);
    $intera = "<?php\n// " . implode("\n// ", IDL_RIGHE) . "\n\nnamespace App;\n";
    file_put_contents($radice . '/app/Intero.php', $intera);
    file_put_contents($radice . '/app/Senza.php', "<?php\n\nnamespace App;\n");
    file_put_contents($radice . '/app/Nota.md', 'non è un sorgente');
    file_put_contents($radice . '/bootstrap/cache/generato.php', "<?php\nreturn [];\n");
    file_put_contents($radice . '/tests/Lab/Laboratorio.php', "<?php\nreturn [];\n");

    try {
        $trovati = idlFileSenzaIntestazione($radice);
    } finally {
        foreach (['app/Intero.php', 'app/Senza.php', 'app/Nota.md', 'bootstrap/cache/generato.php', 'tests/Lab/Laboratorio.php'] as $f) {
            @unlink($radice . '/' . $f);
        }
        foreach (['app', 'bootstrap/cache', 'bootstrap', 'tests/Lab', 'tests', ''] as $d) {
            @rmdir($radice . ($d === '' ? '' : '/' . $d));
        }
    }

    expect(array_keys($trovati))->toBe(['app/Senza.php'])
        ->and($trovati['app/Senza.php'])->toBe('nessuna riga SPDX');
});

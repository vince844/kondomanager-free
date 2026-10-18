<?php

/**
 * Cosa resta scoperto: i file fuori da `app`, `resources/js`, `resources/views`, `routes`, `lang` e dai changelog della versione in corso e delle successive (i documenti
 * in `docs/` non sono testo del prodotto); un punto che costruisce il nome della chiave a pezzi (nessuno oggi); il comportamento di chi legge la chiave
 * (lo prova il test del suo componente: qui si guarda solo che nessuno legga più le chiavi tolte).
 *
 * Il sorgente non nomina più ciò che è stato tolto o cambiato di significato.
 *
 * Due chiavi spariscono perché sommavano le entrate come spese: `totale_capitoli` del piano rate (sostituita da `totale_spese`,
 * `totale_entrate` e `totale_da_ripartire`) e `preventivo` della copertura del cruscotto (sostituita da `spese`, `entrate` e `da_ripartire`). Un
 * punto dimenticato, in una pagina che nessun altro test monta, mostrerebbe «€ 0,00» in una colonna o «NaN %» nel cruscotto senza che un
 * test diventi rosso: per questo il sorgente si guarda da qui. Le pagine `.test.ts` si saltano (i loro dati di prova non leggono niente).
 *
 * Il credito non è «automaticamente attivo» e non «verrà utilizzato automaticamente»: lo usa l'amministratore con «Usa credito» o lo restituisce.
 * Nessuna frase di `app`, `resources/js`, `lang` e dei changelog della versione in corso e delle successive lo promette, nemmeno per ricordare che prima si diceva
 * (il changelog racconta la correzione senza citare la frase vecchia).
 *
 * L'elenco dei tipi di spesa sta in `Conto::TIPI_DI_SPESA`: fuori da `Conto.php` e dal motore (`CalcoloQuoteService`, che tiene la sua clausola)
 * nessun file riscrive la lista, altrimenti la clausola del segno vive in due posti e prima o poi si scolla.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** I file di testo del prodotto sotto le cartelle date, senza i test vitest (`*.test.ts`) e le dipendenze. @return string[] percorsi relativi alla radice */
function b52tFileDelProdotto(array $cartelle, array $estensioni = ['php', 'vue', 'ts', 'json']): array
{
    $trovati = [];
    foreach ($cartelle as $cartella) {
        $base = base_path($cartella);
        if (! is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $nome = $file->getFilename();
            if (! in_array($file->getExtension(), $estensioni, true) || str_ends_with($nome, '.test.ts') || str_contains($file->getPathname(), '/node_modules/')) {
                continue;
            }
            $trovati[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
        }
    }
    sort($trovati);

    return $trovati;
}

/** Le righe dei file date che contengono il modello (espressione regolare): `percorso:riga`. @return string[] */
function b52tRigheCon(array $file, string $modello): array
{
    $righe = [];
    foreach ($file as $percorso) {
        foreach (file(base_path($percorso)) as $n => $riga) {
            if (preg_match($modello, $riga)) {
                $righe[] = $percorso.':'.($n + 1);
            }
        }
    }

    return $righe;
}

it('T1.11 nessun punto dell\'app legge più `totale_capitoli` del piano rate', function () {
    $file = b52tFileDelProdotto(['app', 'resources/js', 'resources/views', 'routes', 'lang']);

    expect(b52tRigheCon($file, '/totale_capitoli/'))->toBe([]);
});

it('T1.11b nessun punto legge o scrive più `preventivo` nella copertura del cruscotto: né la pagina né il controller', function () {
    $pagine = b52tFileDelProdotto(['resources/js']);
    $controller = array_filter(b52tFileDelProdotto(['app/Http/Controllers/Gestionale/Dashboard', 'app/Services/Gestionale']), fn ($f) => str_ends_with($f, 'DashboardController.php') || str_ends_with($f, 'BudgetCoverageService.php'));

    expect(b52tRigheCon($pagine, '/copertura\??\.preventivo/'))->toBe([]);
    expect(b52tRigheCon($controller, '/[\'"]preventivo[\'"]\s*=>/'))->toBe([]);
});

it('T2.13 nessuna frase promette che il credito sia «automaticamente attivo» o «verrà utilizzato automaticamente»', function () {
    $file = b52tFileDelProdotto(['app', 'resources/js', 'lang']);
    $modello = '/automaticamente attiv|verr[àa] utilizzato automaticamente|pronti per la compensazione/iu';

    expect(b52tRigheCon($file, $modello))->toBe([]);
});

it('T2.13b controprova: nessun changelog della versione in corso o delle successive promette il credito automatico', function () {
    $changelog = array_filter(b52tFileDelProdotto(['resources/data/changelogs'], ['json']), function ($f) {
        $versione = basename($f, '.json');

        return version_compare($versione, '1.11.0-beta.52', '>=');
    });
    $trovati = [];
    foreach ($changelog as $f) {
        $testi = [];
        $dati = json_decode(file_get_contents(base_path($f)), true) ?? [];
        array_walk_recursive($dati, function ($v) use (&$testi) {
            if (is_string($v)) {
                $testi[] = $v;
            }
        });
        foreach ($testi as $t) {
            if (preg_match('/automaticamente attiv|verr[àa] utilizzato automaticamente|pronti per la compensazione/iu', $t)) {
                $trovati[] = $f.': '.mb_substr($t, 0, 80);
            }
        }
    }

    expect($trovati)->toBe([]);
});

it('T1.12 controprova: l\'elenco dei tipi di spesa non è riscritto fuori da Conto.php e dal motore', function () {
    $file = array_filter(b52tFileDelProdotto(['app']), fn ($f) => ! in_array($f, ['app/Models/Gestionale/Conto.php', 'app/Services/CalcoloQuoteService.php'], true));

    expect(b52tRigheCon($file, '/\[\s*[\'"]spesa[\'"]\s*,\s*[\'"]uscita[\'"]\s*\]/'))->toBe([]);
});

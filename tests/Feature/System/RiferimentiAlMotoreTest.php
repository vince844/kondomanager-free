<?php

/**
 * Nessun commento cita il motore del riparto con un numero di riga.
 *
 * Il motore (`app/Services/CalcoloQuoteService.php`) cambia a ogni beta: un riferimento «file:riga» punta dopo poche settimane a un'altra riga (una
 * chiusura, un `else`, una riga vuota), e chi lo segue legge un codice che non c'entra. Le righe della clausola del segno citate dai test della
 * 1.11.0-beta.52 si erano già spostate di sedici righe quando la beta è uscita. I commenti citano il metodo (`CalcoloQuoteService::processaConti`),
 * che resta vero finché il metodo c'è.
 *
 * Si cercano le due forme, con e senza `.php`, seguite da due punti e da un numero, nei test, nel frontend e nel codice del prodotto; il motore
 * stesso è escluso, e lo è questo file. L'espressione si costruisce a pezzi, così questo file non la contiene scritta per intero.
 *
 * Cosa resta scoperto: i riferimenti «file:riga» agli altri file (cambiano meno, e il motore è quello che si cita di più); i documenti in `docs/`,
 * che non viaggiano con il prodotto; il laboratorio (`tests/Lab`), che ha le sue regole; un riferimento scritto in un altro modo («riga 1516 del
 * motore»).
 */

/** I file di testo sotto le cartelle date, con le estensioni date, come percorsi relativi alla radice; senza `tests/Lab` e le dipendenze. @return string[] */
function riferimentiAlMotoreFile(array $cartelle, array $estensioni): array
{
    $trovati = [];
    foreach ($cartelle as $cartella) {
        $base = base_path($cartella);
        if (! is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $percorso = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
            if (! in_array($file->getExtension(), $estensioni, true) || str_starts_with($percorso, 'tests/Lab/') || str_contains($percorso, '/node_modules/')) {
                continue;
            }
            $trovati[] = $percorso;
        }
    }
    sort($trovati);

    return $trovati;
}

it('nessun file di test, del frontend o del prodotto cita il motore del riparto con un numero di riga', function () {
    $modello = '/'.'CalcoloQuote'.'Service'.'(\.php)?'.':\d+'.'/';
    $esclusi = ['app/Services/CalcoloQuoteService.php', 'tests/Feature/System/RiferimentiAlMotoreTest.php'];

    $trovati = [];
    foreach (riferimentiAlMotoreFile(['tests', 'resources/js', 'app'], ['php', 'ts', 'vue', 'js']) as $percorso) {
        if (in_array($percorso, $esclusi, true)) {
            continue;
        }
        foreach (file(base_path($percorso)) as $n => $riga) {
            if (preg_match($modello, $riga)) {
                $trovati[] = $percorso.':'.($n + 1);
            }
        }
    }

    expect($trovati)->toBe([]);
});

it('controprova — la ricerca trova le due forme e non il nome del metodo', function () {
    $modello = '/'.'CalcoloQuote'.'Service'.'(\.php)?'.':\d+'.'/';

    expect(preg_match($modello, '(`'.'CalcoloQuote'.'Service.php:1516-1518`)'))->toBe(1)
        ->and(preg_match($modello, '«'.'CalcoloQuote'.'Service:569»'))->toBe(1)
        ->and(preg_match($modello, '`'.'CalcoloQuote'.'Service::processaConti`'))->toBe(0);
});

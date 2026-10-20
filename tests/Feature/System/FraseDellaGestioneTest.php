<?php

/**
 * 1.11.0-beta.54 — nessuna frase del prodotto mette la parola «gestione» davanti al nome di una gestione per conto suo.
 *
 * «Gestione ordinaria» è il nome che il programma dà alla gestione di base di ogni condominio nuovo, e una frase scritta «sulla gestione» più il nome
 * diceva «sulla gestione Gestione ordinaria». La regola sta in un posto solo, `FrasiObbligati::gestione()`: le frasi nominano la gestione con il suo
 * risultato, e la parola non si scrive nel formato.
 *
 * Si cercano nel codice del prodotto (`app/`) le due forme che la ripetevano, la parola seguita da un segnaposto `%s` e la parola seguita da un nome
 * interpolato fra graffe, e si stampano i file e le righe. Le espressioni si costruiscono a pezzi, così questo file non le contiene scritte per intero.
 * Le righe dei log (`Log::`) non sono frasi per l'amministratore: scrivono un identificativo o il tipo della gestione («gestione {$id}»), non il suo
 * nome, e restano fuori.
 *
 * Cosa resta scoperto: un nome unito alla parola con una concatenazione o con un segnaposto numerato; le frasi nominate fra virgolette («della gestione
 * «%s»»), dove il nome si legge come nome e la parola non si ripete; il frontend, che riceve dal server la parola già composta (`gestione_frase` nelle
 * rate di chi ha versato); le note e i testi già salvati nel database prima della beta.54.
 */

/** I file PHP sotto `app/`, come percorsi relativi alla radice. @return string[] */
function fraseDellaGestioneFile(): array
{
    $trovati = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $trovati[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
        }
    }
    sort($trovati);

    return $trovati;
}

/** Le due forme che ripetono la parola: davanti a un segnaposto e davanti a un nome interpolato. @return string[] */
function fraseDellaGestioneModelli(): array
{
    return ['/'.'[Gg]estione'.' %s/', '/'.'[Gg]estione'.' \{\$/'];
}

/** La riga è un log: non è una frase per l'amministratore. */
function fraseDellaGestioneELog(string $riga): bool
{
    return str_contains($riga, 'Log::');
}

it('nessun file di app/ scrive la parola «gestione» davanti a un segnaposto o a un nome interpolato', function () {
    $trovati = [];
    foreach (fraseDellaGestioneFile() as $percorso) {
        foreach (file(base_path($percorso)) as $n => $riga) {
            if (fraseDellaGestioneELog($riga)) {
                continue;
            }
            foreach (fraseDellaGestioneModelli() as $modello) {
                if (preg_match($modello, $riga)) {
                    $trovati[] = $percorso.':'.($n + 1);
                    break;
                }
            }
        }
    }

    expect($trovati)->toBe([]);
});

it('controprova — la ricerca trova le due forme e non la frase che passa dall\'aiutante', function () {
    [$segnaposto, $interpolato] = fraseDellaGestioneModelli();

    expect(preg_match($segnaposto, "'Sulla ".'gestione'." %s: credito'"))->toBe(1)
        ->and(preg_match($segnaposto, "'(".'Gestione'." %s)'"))->toBe(1)
        ->and(preg_match($interpolato, '" — '.'gestione'.' {$g->nome}, scelta"'))->toBe(1)
        ->and(preg_match($segnaposto, "'Sulla %s: credito', FrasiObbligati::gestione(\$nome)"))->toBe(0)
        ->and(preg_match($interpolato, '" — {$gestione}, scelta"'))->toBe(0)
        ->and(preg_match($segnaposto, "'della ".'gestione'." «%s»'"))->toBe(0)
        ->and(fraseDellaGestioneELog('Log::warning("Nessun piano per la '.'gestione'.' {$pianoRate->gestione_id}");'))->toBeTrue()
        ->and(fraseDellaGestioneELog('$frase = sprintf(\'Sulla %s\', $gestione);'))->toBeFalse();
});

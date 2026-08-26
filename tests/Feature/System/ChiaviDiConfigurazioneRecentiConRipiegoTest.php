<?php

/**
 * Le chiavi di configurazione nate di recente si leggono **sempre** con un ripiego.
 *
 * ## Perché questa guardia esiste
 *
 * `config/pagination.php` nasce nella 1.10.0-beta.54. Chi aggiorna da una versione precedente che
 * aveva ricevuto un `php artisan config:cache` si porta dietro `bootstrap/cache/config.php` della
 * vecchia, dove quella chiave non c'è: `config('pagination.consentite')` vale `null`, e in PHP 8
 * ogni funzione che pretende un array — `in_array()`, `array_filter()`, `count()` — è un **fatale**,
 * non un avviso.
 *
 * Il difetto è stato trovato due volte, e la seconda dalla revisione avversariale della beta.1:
 *
 * 1. **Coda 86**, nella migrazione delle impostazioni: `migrate` si fermava e lasciava dieci
 *    migrazioni pendenti, cioè un database a metà.
 * 2. **Gli altri quattro punti**, che la correzione della Coda 86 aveva mancato — fra cui
 *    `PaginaElenco::normalizza()`, che sta sul percorso di **ogni elenco del programma**: sarebbe
 *    stato un 500 su tutto il gestionale, e chi aggiorna dal pannello non ha una console per
 *    svuotare la cache e uscirne.
 *
 * Alla seconda volta si chiude la classe, non il caso.
 *
 * ## Cosa questa guardia NON copre
 *
 * - **Solo `pagination.*`.** È la chiave misurata, l'unica per cui il difetto è stato riprodotto.
 *   Le altre chiavi nate nel ciclo 1.10 non sono presidiate da qui: se una di loro finisse dentro
 *   una funzione che pretende un array, questo test tacerebbe.
 * - **Solo le letture passate a un consumatore di array**, e l'elenco dei consumatori è chiuso. Una
 *   lettura scalare — `(int) config('pagination.default_per_page')` — è sana, perché `(int) null` è
 *   `0`, e infatti non viene segnalata. Ma una lettura passata a una funzione fuori elenco, o
 *   assegnata e consumata due righe più sotto, non viene vista.
 * - **Non copre il passaggio al frontend.** `HandleInertiaRequests` manda `consentite` alla pagina:
 *   con `null` non è un fatale, è una tendina vuota. Il ripiego lì c'è, ma non è questa guardia a
 *   pretenderlo.
 * - **Solo le letture scritte per esteso.** Una lettura costruita a runtime — `config($chiave)` con
 *   la chiave in una variabile — non viene vista. È una guardia sul testo, non sul comportamento.
 * - **Non prova che il ripiego sia quello giusto.** Verifica che ci sia, non che `[]` sia il valore
 *   sensato per quel punto. Quello lo dicono i test di `MigrazioneImpostazioniConConfigInCacheTest`.
 */

it('ogni lettura di paginazione passata a una funzione che pretende un array ha un ripiego', function () {
    // I consumatori che in PHP 8 vanno in fatale su `null`. L'elenco è chiuso di proposito:
    // è la classe misurata, non ogni uso immaginabile.
    $consumatori = 'in_array|array_filter|array_map|array_values|array_merge|count|implode|Rule::in';

    $radici = [base_path('app'), base_path('database'), base_path('routes')];
    $senzaRipiego = [];

    foreach ($radici as $radice) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice)) as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }

            foreach (file($f->getPathname()) as $n => $riga) {
                // Nei commenti la chiave si nomina per spiegare, non per leggerla.
                if (preg_match('#^\s*(\*|//|/\*)#', $riga)) {
                    continue;
                }

                // Una lettura è pericolosa solo se finisce dentro un consumatore di array
                // e non ha il ripiego attaccato: `config('pagination.x') ?? []`.
                // Lo spazio sta DENTRO la lookahead: scritto `\s*(?!\?\?)` la lookahead si
                // azzera e controlla prima dello spazio, quindi non vede mai il `??` che segue.
                // Sbagliato alla prima stesura, e la guardia segnalava le righe già corrette.
                $pericolosa = "#({$consumatori})\s*\([^\n]*config\(\s*'pagination\.[a-z_]+'\s*\)(?!\s*\?\?)#";

                if (preg_match($pericolosa, $riga)) {
                    $senzaRipiego[] = str_replace(base_path().'/', '', $f->getPathname()).':'.($n + 1);
                }
            }
        }
    }

    expect($senzaRipiego)->toBe([], sprintf(
        "Queste letture di una chiave `pagination.*` finiscono in una funzione che pretende un ".
        "array, senza ripiego:\n\n  %s\n\n".
        "Con la configurazione in cache di una versione precedente la chiave non esiste, `config()` ".
        "torna `null`, e in PHP 8 è un fatale.\n".
        "Si aggiunge `?? []` attaccato alla lettura.",
        implode("\n  ", $senzaRipiego)
    ));
});

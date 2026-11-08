<?php

/**
 * I nomi della legge per i documenti della legge: «Registro di contabilità» al posto di «Prima nota» (la «Prima nota diretta» della regolazione e
 * del giroconto è un'altra cosa, la scrittura senza fattura, e resta); «Entrate e uscite per cassa» il prospetto per cassa della pagina dello stato
 * patrimoniale; la distinta delle spese è il preventivo (art. 1135 c.c.), non il rendiconto; nessuna frase attribuisce all'art. 1130-bis c.c. ciò che
 * l'articolo non dice. Il testo dell'articolo: il rendiconto «si compone di un registro di contabilità, di un riepilogo finanziario, nonché di una
 * nota sintetica esplicativa della gestione»; non parla della separazione delle gestioni, dell'esercizio di una scrittura, delle sopravvenienze.
 */

/** I file del prodotto (codice, viste, pagine), senza i test. */
function ndrFile(): array
{
    $radice = dirname(__DIR__, 3);
    $file = [];
    foreach (['app', 'resources/js', 'resources/views', 'lang'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $nome = $f->getPathname();
            if (preg_match('/\.(php|vue|ts)$/', $nome) && ! preg_match('/\.test\.ts$/', $nome)) {
                $file[substr($nome, strlen($radice) + 1)] = file_get_contents($nome);
            }
        }
    }

    return $file;
}

/** Le righe che contengono il testo, «file:riga». */
function ndrCerca(string $regex): array
{
    $trovate = [];
    foreach (ndrFile() as $nome => $testo) {
        foreach (explode("\n", $testo) as $i => $riga) {
            if (preg_match($regex, $riga)) {
                $trovate[] = $nome.':'.($i + 1);
            }
        }
    }

    return $trovate;
}

it('«Prima nota» non è più il nome del registro di contabilità: resta solo la «Prima nota diretta» della scrittura senza fattura', function () {
    expect(ndrCerca('/Prima nota(?! diretta)/'))->toBe([]);
});

it('nessuna frase attribuisce all\'art. 1130-bis la separazione delle gestioni, l\'esercizio delle scritture, le sopravvenienze o le parole del prospetto per cassa', function () {
    // Restano le citazioni che dicono che cosa è il documento (la situazione patrimoniale e il riepilogo finanziario sono nell'articolo); nessuna
    // lo cita per una regola che l'articolo non ha.
    expect(ndrCerca('/1130-bis.*(richiest|separa|esercizio corretto|sopravvenien|disponibilit|fuori preventivo|cassetto)|(richiest|separa|esercizio corretto|sopravvenien|fuori preventivo|cassetto).*1130-bis/i'))->toBe([])
        ->and(count(ndrCerca('/1130-bis/i')))->toBeLessThanOrEqual(4);
});

it('la distinta delle spese dice «preventivo» e non «rendiconto condominiale»', function () {
    $distinta = file_get_contents(dirname(__DIR__, 3).'/resources/views/pdf/gestionale/distinta_spese.blade.php');

    expect($distinta)->not->toContain('rendiconto condominiale')->toContain('è il preventivo di spesa');
});

it('il prospetto per cassa si chiama «Entrate e uscite per cassa», nella sua scheda del rendiconto e nella stampa dello stato patrimoniale', function () {
    $radice = dirname(__DIR__, 3);
    // Il prospetto per cassa ha la sua scheda, «Cassa»; la scheda della situazione patrimoniale ci rimanda e non lo chiama con un altro nome.
    $pagina = file_get_contents($radice.'/resources/js/pages/gestionale/movimenti/rendiconto/Cassa.vue');
    $situazione = file_get_contents($radice.'/resources/js/pages/gestionale/movimenti/statoPatrimoniale/Show.vue');
    $stampa = file_get_contents($radice.'/resources/views/pdf/gestionale/stato_patrimoniale.blade.php');

    expect($situazione)->not->toMatch('/[Rr]iepilogo finanziario dell\'esercizio/')
        ->and($pagina)->toContain('Entrate e uscite per cassa')->not->toMatch('/[Rr]iepilogo finanziario dell\'esercizio/')
        ->and($stampa)->toContain('Entrate e uscite per cassa')->not->toMatch('/[Rr]iepilogo finanziario dell\'esercizio/');
});

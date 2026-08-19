<?php

/**
 * Nessuna schermata di caricamento scrive a mano un limite, né ne controlla uno per conto suo.
 *
 * ## Le due metà dello stesso difetto
 *
 * `LimiteCaricamentoNonSiScriveAManoTest` presidia il **server**: nessuna regola promette più di
 * quanto la macchina accetti. Questo file presidia lo **schermo**, che è dove il difetto si vede:
 * la segnalazione da cui è nata tutta questa storia diceva *«Max 10MB»* su una schermata mentre la
 * regola ne accettava 20 e il server 2.
 *
 * Sono due presidi separati perché falliscono in modi diversi: PHP non si accorge di un numero
 * scritto in un `.vue`, e Vue non si accorge di un `max:` in una request.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la resa a video — che il testo si legga, che stia nel riquadro — né le schermate che
 * non caricano niente.
 *
 * ⚠️ **E non copre la metà positiva**, cioè «ogni schermata di caricamento *riceve* il limite».
 * Il titolo lo prometteva e il corpo non lo faceva: corretto durante la revisione della .60, che è
 * la stessa classe di difetto della coda ㊼ — un nome che copre più di quanto il corpo verifichi.
 * Le schermate con un caricamento sono **dodici**, e non tutte devono dichiarare un limite: le due
 * delle fatture, per esempio, non lo dichiarano affatto e non mentono. Trasformare «riceve» in
 * un'asserzione richiederebbe l'elenco delle eccezioni scritto a mano, cioè la cosa che questi
 * presidi esistono per evitare. Resta scoperto, ed è detto qui invece che promesso nel titolo.
 */

use Illuminate\Support\Facades\File;

/**
 * Un commento non è una promessa all'utente.
 *
 * Serve perché i docblock di queste stesse schermate **citano** i numeri per spiegare il difetto:
 * vietarli renderebbe impossibile raccontarlo.
 */
function rigaDiCommento(string $riga): bool
{
    $pulita = ltrim($riga);

    return str_starts_with($pulita, '//')
        || str_starts_with($pulita, '*')
        || str_starts_with($pulita, '/*')
        || str_starts_with($pulita, '<!--');
}

/** Le schermate che offrono un caricamento di file all'utente. */
function schermateDiCaricamento(): array
{
    $trovate = [];

    $file = collect(File::allFiles(resource_path('js/pages')))
        ->filter(fn ($f) => $f->getExtension() === 'vue');

    foreach ($file as $f) {
        $sorgente = $f->getContents();

        if (! str_contains($sorgente, 'type="file"')) {
            continue;
        }

        $trovate[] = str_replace(resource_path('js/').'', '', $f->getPathname());
    }

    sort($trovate);

    return $trovate;
}

it('nessuna schermata di caricamento scrive a mano un numero di megabyte', function () {
    $bugiarde = [];

    foreach (schermateDiCaricamento() as $percorso) {
        $sorgente = file_get_contents(resource_path('js/'.$percorso));

        foreach (explode("\n", $sorgente) as $i => $riga) {
            if (rigaDiCommento($riga)) {
                continue;
            }

            // ⚠️ Il confronto è **sensibile alle maiuscole** e questo non è pignoleria: `mb-2`,
            // `mb-4`, `mt-1 mb-1` sono classi Tailwind che finiscono nella stessa forma «cifra +
            // mb». La prima stesura di questa guardia era `/i` e segnalava quaranta righe di
            // margini, cioè era inservibile.
            if (preg_match('/\d+\s?MB(?![a-z-])/', $riga)) {
                $bugiarde[] = $percorso.':'.($i + 1).' → '.trim($riga);
            }
        }
    }

    expect($bugiarde)->toBe([],
        'queste schermate promettono un numero che non dipende dal server: '
        .'il controller deve passare `LimiteCaricamento::etichetta()` e la schermata interpolarla');
});

it('nessuna schermata di caricamento controlla la dimensione per conto suo', function () {
    // ⚠️ Un controllo lato client sulla dimensione è **un secondo limite**, scritto in un posto dove
    // nessuno lo cerca: rifiutava file che il server avrebbe accettato e ne lasciava partire altri
    // che il server rifiutava. Il controllo giusto è uno solo, ed è quello del server.
    $doppioni = [];

    foreach (schermateDiCaricamento() as $percorso) {
        $sorgente = file_get_contents(resource_path('js/'.$percorso));

        foreach (explode("\n", $sorgente) as $i => $riga) {
            if (rigaDiCommento($riga)) {
                continue;
            }

            if (preg_match('/\bmaxSize\b|\d+\s*\*\s*1024\s*\*\s*1024/', $riga)) {
                $doppioni[] = $percorso.':'.($i + 1).' → '.trim($riga);
            }
        }
    }

    expect($doppioni)->toBe([], 'il limite di dimensione si controlla in un posto solo, sul server');
});

it('la guardia riconosce davvero le forme che deve prendere', function () {
    // Una guardia che passa perché non trova niente è indistinguibile da una guardia rotta.
    $numero = fn (string $r) => preg_match('/\d+\s?MB(?![a-z-])/', $r) === 1;
    $dimensione = fn (string $r) => preg_match('/\bmaxSize\b|\d+\s*\*\s*1024\s*\*\s*1024/', $r) === 1;

    expect($numero('<p>Solo PDF (Max 20MB)</p>'))->toBeTrue()
        ->and($numero('Formati supportati: PDF, Immagini (max 20MB)'))->toBeTrue()
        ->and($dimensione('const maxSize = 20 * 1024 * 1024;'))->toBeTrue();

    // Quello che NON deve segnalare.
    expect($numero('<div class="mb-2 flex">'))->toBeFalse()
        ->and($numero('<UploadCloud class="w-10 h-10 mb-2 text-gray-400" />'))->toBeFalse()
        ->and($numero('<div class="mt-1 mb-1 border-l-2">'))->toBeFalse()
        ->and($numero('Solo PDF (max {{ props.limiteFile }})'))->toBeFalse()
        ->and($dimensione('const sizes = [\'Bytes\', \'KB\', \'MB\'];'))->toBeFalse();
});

<?php

/**
 * L'elenco dei Comuni italiani e il loro codice catastale.
 *
 * ## Origine: la quinta segnalazione di un amministratore sul forum, 18/08/2026
 *
 * È l'unica della serie che non è un difetto: *«i codici catastali dei Comuni potrebbero essere
 * precaricati»*. Quei quattro campi oggi si battono a mano ogni volta, e il codice catastale è
 * esattamente il dato che nessuno ricorda a memoria.
 *
 * ## La forma decisa, e perché non è una tendina
 *
 * Il campo **resta libero**, con accanto un pulsante di ricerca. L'elenco è un aiuto, non un
 * obbligo. La ragione è scritta nella coda ㊹ e vale la pena ripeterla qui, perché è ciò che questi
 * test proteggono: i Comuni si fondono e cambiano nome, e **un dato sbagliato suggerito dal
 * programma è peggio di un campo vuoto**, perché chi lo inserisce si fida. Se il Comune manca o è
 * cambiato, si scrive a mano come prima.
 *
 * ## La trappola della fonte, misurata il 19/08/2026
 *
 * Allo stesso percorso ISTAT vivono due file che **non sono lo stesso elenco**: il `.csv` è fermo al
 * **26/01/2024** (7.896 comuni, 26 colonne) mentre l'`.xlsx` è aggiornato al **21/02/2026** (7.894
 * comuni, 27 colonne) — e le colonne sono in ordine diverso, quindi un lettore scritto sul CSV legge
 * la colonna sbagliata dell'XLSX. Il CSV è l'indirizzo che si trova per primo ed è quello sbagliato.
 *
 * L'XLSX però costa **142 MB** per essere aperto, cioè muore su un hosting con `memory_limit = 128M`
 * — ed è esattamente il parco installato da cui la segnalazione arriva. Per questo l'elenco viaggia
 * **convertito, dentro il repository**, e il comando che lo carica legge quello: nessuna
 * installazione paga il costo dell'XLSX e nessuna dipende dalla rete.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la resa a video del pulsante di ricerca (si guarda a video, tre viste), né il CAP: la
 * fonte ISTAT **non contiene il CAP**, in nessuna delle due forme, e quel campo resta a mano.
 * Non copre le schermate di fornitori e casse: lì il codice catastale non ha una colonna dove
 * andare, e il pulsante non ci va (decisione del 19/08/2026).
 */

use App\Models\Comune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../../Support/fogliettoIstat.php';

uses(RefreshDatabase::class);

/** Il percorso del file che viaggia col codice. */
function fileComuni(): string
{
    return resource_path('data/comuni/comuni-italiani.json');
}

/** Il file spedito, decodificato. */
function elencoSpedito(): array
{
    return json_decode(file_get_contents(fileComuni()), true, 512, JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// Il dato che viaggia col codice
// ---------------------------------------------------------------------------------------------

it('il file spedito dichiara la data della fonte, che è quella di ISTAT e non quella del download', function () {
    $doc = elencoSpedito();

    // ISTAT la scrive nel **nome del foglio** dell'XLSX («CODICI al 21_02_2026»). È l'unico posto in
    // cui la fonte dichiara sé stessa: la data di download direbbe solo quando abbiamo premuto noi.
    expect($doc)->toHaveKeys(['fonte', 'url', 'foglio', 'aggiornato_al', 'comuni'])
        ->and($doc['aggiornato_al'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($doc['foglio'])->toContain(str_replace('-', '_', implode('_', array_reverse(explode('-', $doc['aggiornato_al'])))));
});

it('ogni comune ha un codice catastale di quattro caratteri, mai vuoto e mai ripetuto', function () {
    $codici = array_column(elencoSpedito()['comuni'], 'codice');

    // È la ragione per cui il codice catastale può fare da chiave dell'aggiornamento: misurato sulla
    // fonte, zero vuoti e zero duplicati su 7.894 righe.
    expect($codici)->not->toBeEmpty()
        ->and(array_filter($codici, fn ($c) => strlen((string) $c) !== 4))->toBe([])
        ->and(count(array_unique($codici)))->toBe(count($codici));
});

it('contiene i casi di prova verificati alla fonte', function () {
    $per = collect(elencoSpedito()['comuni'])->keyBy('nome');

    expect($per['Roma']['codice'])->toBe('H501')
        ->and($per['Linguaglossa']['codice'])->toBe('E602')
        ->and($per['Milano']['codice'])->toBe('F205');
});

// ---------------------------------------------------------------------------------------------
// Il comando che popola la tabella
// ---------------------------------------------------------------------------------------------

it('il comando carica l\'elenco dal file spedito col codice', function () {
    Artisan::call('kondomanager:aggiorna-comuni');

    expect(Comune::count())->toBe(count(elencoSpedito()['comuni']))
        ->and(Comune::where('codice_catasto', 'H501')->value('nome'))->toBe('Roma');
});

it('rilanciarlo non duplica niente', function () {
    Artisan::call('kondomanager:aggiorna-comuni');
    $prima = Comune::count();

    Artisan::call('kondomanager:aggiorna-comuni');

    expect(Comune::count())->toBe($prima);
});

it('corregge una riga già presente, invece di lasciarla com\'è', function () {
    // ⚠️ Questo test esiste per un difetto già pagato in casa: `TipologieImmobiliSeeder` usa
    // `firstOrCreate` con chiave sul solo nome, e una riga già presente **non viene mai corretta**
    // da un aggiornamento successivo (accertato a database il 15/08/2026). Su un elenco che esiste
    // proprio per essere aggiornato, quel comportamento sarebbe il difetto principale.
    Artisan::call('kondomanager:aggiorna-comuni');

    Comune::where('codice_catasto', 'H501')->update([
        'nome'      => 'Roma Vecchia',
        'provincia' => 'Sbagliata',
    ]);

    Artisan::call('kondomanager:aggiorna-comuni');

    $roma = Comune::where('codice_catasto', 'H501')->first();

    expect($roma->nome)->toBe('Roma')
        ->and($roma->provincia)->toBe('Roma');
});

it('scrive su ogni riga la data della fonte da cui viene, così la schermata può dichiararla', function () {
    Artisan::call('kondomanager:aggiorna-comuni');

    expect(Comune::where('codice_catasto', 'H501')->value('fonte_al')?->toDateString())
        ->toBe(elencoSpedito()['aggiornato_al']);
});

it('con --da legge un elenco convertito diverso da quello spedito', function () {
    // ⚠️ Questo test si chiamava «legge un file ISTAT locale» e gli passava un JSON nostro: il nome
    // prometteva più di quanto il corpo verificasse, che è il segnale d'allarme della Fase 1-bis. Il
    // caso vero — l'XLSX come lo pubblica ISTAT — ha il suo test più sotto, ed è arrivato con la
    // beta.60; questo resta a coprire l'altro formato.
    $percorso = sys_get_temp_dir().'/comuni-prova-'.getmypid().'.json';
    file_put_contents($percorso, json_encode([
        'fonte'         => 'prova',
        'url'           => 'prova',
        'foglio'        => 'CODICI al 01_01_2030',
        'aggiornato_al' => '2030-01-01',
        'comuni'        => [
            ['codice' => 'Z999', 'nome' => 'Comune Di Prova', 'altra' => null, 'sigla' => 'ZZ', 'provincia' => 'Prova', 'regione' => 'Prova'],
        ],
    ], JSON_UNESCAPED_UNICODE));

    Artisan::call('kondomanager:aggiorna-comuni', ['--da' => $percorso]);
    unlink($percorso);

    expect(Comune::where('codice_catasto', 'Z999')->value('nome'))->toBe('Comune Di Prova');
});

it('rifiuta un file che non è un elenco di comuni, invece di svuotare la tabella', function () {
    Artisan::call('kondomanager:aggiorna-comuni');
    $prima = Comune::count();

    $percorso = sys_get_temp_dir().'/comuni-rotto-'.getmypid().'.json';
    file_put_contents($percorso, '{"questo":"non è un elenco"}');

    $esito = Artisan::call('kondomanager:aggiorna-comuni', ['--da' => $percorso]);
    unlink($percorso);

    expect($esito)->not->toBe(0)
        ->and(Comune::count())->toBe($prima);
});

/**
 * ## La via d'uscita, che fino alla beta.59 non si poteva percorrere
 *
 * Il changelog della .59 prometteva a un amministratore di aggiornarsi da sé con `--da`, ma `--da`
 * accettava **solo il nostro JSON già convertito** — e la ricetta per produrlo dall'XLSX di ISTAT
 * non esisteva in nessun file del repository: era stata eseguita una volta a mano.
 *
 * Da qui in avanti `--da` accetta il file **come lo pubblica ISTAT**, e `--scrivi-file` rigenera
 * quello che spediamo. È la differenza fra una promessa e un comando.
 */
describe('la via d\'uscita accetta il file come lo pubblica ISTAT', function () {
    it('con --da su un XLSX converte e carica, senza passare da noi', function () {
        $percorso = fogliettoIstat('CODICI al 01_01_2030', [
            ['Comune Di Prova', null, 'Prova', 'Prova', 'ZZ', 'Z999'],
        ]);

        $esito = Artisan::call('kondomanager:aggiorna-comuni', ['--da' => $percorso]);
        unlink($percorso);

        expect($esito)->toBe(0)
            ->and(Comune::where('codice_catasto', 'Z999')->value('nome'))->toBe('Comune Di Prova')
            ->and(Comune::where('codice_catasto', 'Z999')->value('fonte_al')->toDateString())->toBe('2030-01-01');
    });

    it('con --scrivi-file rigenera l\'elenco che spediamo, senza toccare il database', function () {
        // ⚠️ Scrive in un file temporaneo, **non** in quello spedito col codice. La prima stesura
        // riscriveva quello vero e lo ripristinava in un `finally`: la revisione della .60 ha
        // mostrato che un `finally` non scatta su un errore fatale, e che bastava un test
        // interrotto per lasciare nel repository un elenco con **un comune al posto di 7.894**.
        $destinazione = sys_get_temp_dir().'/comuni-scritti-'.getmypid().'.json';

        $percorso = fogliettoIstat('CODICI al 01_01_2030', [
            ['Comune Di Prova', null, 'Prova', 'Prova', 'ZZ', 'Z999'],
        ]);

        try {
            $esito = Artisan::call('kondomanager:aggiorna-comuni', [
                '--da'          => $percorso,
                '--scrivi-file' => true,
                '--in'          => $destinazione,
            ]);

            expect($esito)->toBe(0);

            $scritto = json_decode(file_get_contents($destinazione), true);

            expect($scritto['aggiornato_al'])->toBe('2030-01-01')
                ->and($scritto['comuni'])->toHaveCount(1)
                // ⚠️ Una riga per comune, come il file vero: si aggiorna una volta l'anno e il diff
                // deve restare leggibile. Un JSON su una riga sola sarebbe illeggibile in revisione.
                ->and(substr_count(file_get_contents($destinazione), "\n"))->toBeGreaterThan(5)
                // Con `--scrivi-file` si riscrive il file e **non** si tocca il database: sono due
                // operazioni diverse e confonderle vorrebbe dire cambiare i dati di chi lo lancia.
                ->and(Comune::where('codice_catasto', 'Z999')->exists())->toBeFalse()
                // E l'elenco spedito col codice non è stato sfiorato.
                ->and(json_decode(file_get_contents(fileComuni()), true)['comuni'])->toHaveCount(7894);
        } finally {
            @unlink($destinazione);
            unlink($percorso);
        }
    });

    it('il file rigenerato è rileggibile dal comando stesso: il giro si chiude', function () {
        $destinazione = sys_get_temp_dir().'/comuni-giro-'.getmypid().'.json';
        $percorso = fogliettoIstat('CODICI al 01_01_2030', [
            ['Comune Di Prova', null, 'Prova', 'Prova', 'ZZ', 'Z999'],
        ]);

        try {
            Artisan::call('kondomanager:aggiorna-comuni', [
                '--da' => $percorso, '--scrivi-file' => true, '--in' => $destinazione,
            ]);

            // Rilanciato con `--da` su quello appena scritto, deve rileggerlo e caricarlo: è il giro
            // completo, XLSX → nostro formato → database.
            expect(Artisan::call('kondomanager:aggiorna-comuni', ['--da' => $destinazione]))->toBe(0)
                ->and(Comune::where('codice_catasto', 'Z999')->value('nome'))->toBe('Comune Di Prova');
        } finally {
            @unlink($destinazione);
            unlink($percorso);
        }
    });

    it('se la scrittura non riesce, l\'elenco spedito resta quello di prima', function () {
        // ⚠️ Il caso che la revisione ha trovato: aprire il file vero in `w` lo tronca **prima** di
        // avere il contenuto nuovo. Adesso si scrive accanto e si sposta, quindi una destinazione
        // impossibile non tocca niente.
        $prima = file_get_contents(fileComuni());
        $percorso = fogliettoIstat('CODICI al 01_01_2030', [
            ['Comune Di Prova', null, 'Prova', 'Prova', 'ZZ', 'Z999'],
        ]);

        try {
            $esito = Artisan::call('kondomanager:aggiorna-comuni', [
                '--da' => $percorso, '--scrivi-file' => true,
                '--in' => '/cartella/che/non/esiste/comuni.json',
            ]);

            expect($esito)->not->toBe(0)
                ->and(file_get_contents(fileComuni()))->toBe($prima);
        } finally {
            unlink($percorso);
        }
    });

    it('un file che non è né un nostro JSON né un XLSX di ISTAT si rifiuta', function () {
        $percorso = sys_get_temp_dir().'/niente-'.getmypid().'.xlsx';
        file_put_contents($percorso, 'questo non è uno zip');

        $esito = Artisan::call('kondomanager:aggiorna-comuni', ['--da' => $percorso]);
        unlink($percorso);

        expect($esito)->not->toBe(0);
    });
});

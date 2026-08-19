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

it('con --da legge un file ISTAT locale, che è la via d\'uscita per chi non vuole aspettare una release', function () {
    // La fonte cambia una volta l'anno e l'elenco viaggia col codice: chi ha bisogno di un
    // aggiornamento fuori ciclo scarica il file da ISTAT e lo passa qui, senza che il prodotto
    // dipenda dalla rete a regime.
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

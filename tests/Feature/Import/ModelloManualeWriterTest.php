<?php

use App\Services\Import\HeaderDetector;
use App\Services\Import\ModelloManualeWriter;
use App\Services\Import\SpreadsheetReader;

/**
 * Il modello vuoto che l'amministratore scarica.
 *
 * ⚠️ **Questi test guardano il file dal lato di chi dovrà rileggerlo, non da quello di chi lo
 * scrive.** Un generatore che produce un `.xlsx` valido ma che i nostri strumenti non sanno
 * interpretare è la forma peggiore di difetto in questo percorso: si scopre quando
 * l'amministratore ha già compilato tutto e ricarica il file.
 *
 * La trappola concreta è la posizione delle righe di spiegazione. Sono utili — la domanda «devo
 * mettere lo stesso nome delle unità?» arriva sempre — ma spingono l'intestazione più in basso, e
 * due finestre diverse la vincolano: `HeaderDetector` guarda le prime dodici righe, mentre
 * `ReportRecognizer` cerca il **titolo** entro le prime tre. Scrivere una riga di troppo in testa
 * fa perdere al file la sua ancora, e nessuno se ne accorgerebbe leggendo il codice.
 */
function modelloGenerato(): array
{
    // ⚠️ `tempnam()` **crea** il file: concatenargli «.xlsx» ne lascia uno da 0 byte che nessuno
    // cancella. Su una suite che gira decine di volte al giorno sono centinaia di file.
    $base = tempnam(sys_get_temp_dir(), 'modello_');
    $percorso = $base.'.xlsx';
    @unlink($base);

    (new ModelloManualeWriter)->scriviSu($percorso);

    $fogli = (new SpreadsheetReader)->leggi($percorso);

    @unlink($percorso);

    return $fogli;
}

it('produce i cinque fogli, nell\'ordine in cui si compilano', function () {
    $nomi = array_map(fn ($f) => $f->nome, modelloGenerato());

    expect($nomi)->toBe(['0 copertina', '1 unita', '2 persone', '3 tabelle', '4 saldi']);
});

it('non contiene il foglio dei capitoli di spesa', function () {
    // Deciso il 29/08/2026: nel modello entra ciò che il prodotto non può ricostruire da solo, e
    // il preventivo è precisamente ciò che l'amministratore sta per decidere ex novo.
    $nomi = array_map(fn ($f) => $f->nome, modelloGenerato());

    expect(implode(' ', $nomi))->not->toContain('capitoli');
});

it('tiene il titolo in prima riga, dove il riconoscitore lo cerca', function () {
    // ⚠️ `ReportRecognizer` cerca il titolo della stampa entro le prime **tre** righe. Il titolo è
    // l'unica cosa che distingue questo file da un export di un altro gestionale: perso quello, il
    // modello compilato arriverebbe come «non riconosciuto».
    $fogli = modelloGenerato();

    expect(trim((string) ($fogli[0]->riga(0)[0] ?? '')))->toBe(ModelloManualeWriter::TITOLO);
});

it('lascia trovare l\'intestazione di ogni foglio nonostante le righe di spiegazione', function (string $foglio, array $etichette, int $quorum, string $prima) {
    $fogli = collect(modelloGenerato())->keyBy(fn ($f) => $f->nome);

    $trovata = (new HeaderDetector)->trova($fogli[$foglio], $etichette, $quorum);

    expect($trovata)->not->toBeNull()
        ->and(trim((string) ($fogli[$foglio]->riga($trovata['riga'])[0] ?? '')))->toBe($prima);
})->with([
    'copertina' => ['0 copertina', ['campo', 'valore'], 2, 'campo'],
    'unità' => ['1 unita', ['unita', 'palazzina', 'interno', 'piano', 'tipo'], 3, 'unita'],
    'persone' => ['2 persone', ['unita', 'nome', 'ruolo', 'indirizzo'], 3, 'unita'],
    // ⚠️ Quorum 1: su questo foglio l'unica etichetta fissa è «unita», perché le altre colonne
    // sono nomi di tabelle che l'amministratore inventa. È anche il foglio in cui il rilevatore
    // potrebbe scambiare la riga «# tabella» per l'intestazione, e non lo fa.
    'tabelle' => ['3 tabelle', ['unita'], 1, 'unita'],
    'saldi' => ['4 saldi', ['unita', 'persona', 'importo', 'causale'], 3, 'unita'],
]);

it('ripete su tutti i fogli che la usano la regola della chiave', function () {
    // È la domanda che l'amministratore fa per prima — «devo mettere lo stesso nome delle unità?» —
    // e la risposta non può stare in un solo foglio: chi apre il terzo non ha letto il primo.
    $fogli = collect(modelloGenerato())->keyBy(fn ($f) => $f->nome);

    foreach (['2 persone', '3 tabelle', '4 saldi'] as $nome) {
        $testa = implode(' ', array_merge($fogli[$nome]->riga(1), $fogli[$nome]->riga(2)));

        expect($testa)->toContain('stessa identica scritta');
    }
});

it('porta la chiave dell\'unità come testo, così «016» non diventa 16', function () {
    $fogli = collect(modelloGenerato())->keyBy(fn ($f) => $f->nome);

    // Le due righe di esempio devono arrivare come stringhe: se il foglio le trasformasse in
    // numeri, la stessa chiave scritta a mano negli altri fogli non combacerebbe più.
    expect($fogli['1 unita']->riga(5)[0] ?? null)->toBeString()
        ->and($fogli['1 unita']->riga(5)[0])->toBe('B1/1');
});

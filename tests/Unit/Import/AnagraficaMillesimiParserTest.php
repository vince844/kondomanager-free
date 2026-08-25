<?php

use App\Services\Import\Foglio;
use App\Services\Import\Parser\AnagraficaMillesimiParser;

/**
 * Il report compatto → unità e tabelle millesimali (livelli 5 e 7).
 *
 * ## Cosa questi test NON coprono
 *
 * - Il riconoscimento del file (§ ReportRecognizer): qui l'intestazione arriva già trovata.
 * - Le colonne miste (millesimi in un file, unità in un altro): copre ImportTabelleMillesimaliTest.
 */
it('legge un millesimo testuale sopra i mille scritto con la formattazione italiana', function () {
    // Trovato dalla revisione avversariale, stesso difetto di RipartoConsuntivoParser::cents():
    // la validazione convertiva la virgola in punto PRIMA di togliere il punto delle
    // migliaia, quindi «1.000,0000» diventava «1.000.0000» — due punti — e is_numeric() lo
    // scartava. Un'unità con l'intera proprietà (1.000 millesimi, scritta come testo) faceva
    // sparire l'intera tabella con l'errore «non è un numero».
    $foglio = new Foglio('Foglio1', [
        ['Palazzina', 'Gruppo', 'Progressivo', 'Proprietario', 'PROPRIETA GENERALE'],
        ['1', '0', '1', 'UNICO PROPRIETARIO', '1.000,0000'],
    ]);

    $esito = (new AnagraficaMillesimiParser)->estrai($foglio, 0);

    expect($esito['tabelle'])->toHaveKey('PROPRIETA GENERALE')
        ->and($esito['tabelle']['PROPRIETA GENERALE']->quote)->toBe(['1-0-1' => 1000.0])
        ->and($esito['esito']->errori())->toBe(0);
});

it('rifiuta ancora un millesimo genuinamente illeggibile', function () {
    // La correzione dell'ordine virgola/punto non deve indebolire il controllo: una cella
    // con del testo al posto di un numero resta un errore.
    $foglio = new Foglio('Foglio1', [
        ['Palazzina', 'Gruppo', 'Progressivo', 'Proprietario', 'PROPRIETA GENERALE'],
        ['1', '0', '1', 'CELLA ROTTA', 'vedi nota'],
    ]);

    $esito = (new AnagraficaMillesimiParser)->estrai($foglio, 0);

    expect($esito['tabelle'])->toBe([])
        ->and($esito['esito']->errori())->toBe(1);
});

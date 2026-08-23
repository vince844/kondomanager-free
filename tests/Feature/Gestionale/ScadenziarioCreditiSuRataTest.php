<?php

use Illuminate\Support\Facades\View;

/**
 * Il partial `_tabella_scadenziario` nascondeva un credito su singola rata (`RataQuote::importo`
 * negativo — stessa forma di credito corretta a schermo dalla Coda 69 di questa beta) dietro un
 * «—», ma il totale di colonna in fondo alla tabella lo sommava comunque: il PDF stampato mostrava
 * un totale che non coincideva con la somma delle celle visibili, pur essendo il totale corretto.
 *
 * Non copre: la modalità "per immobile" (`buildMatriceImmobile`) — stesso partial, stesso rischio,
 * non riverificato qui perché la correzione è nel template condiviso, non nella query a monte.
 */
it('mostra un credito su singola rata invece di nasconderlo, e il totale di colonna coincide', function () {
    $colonneRate = [
        0 => ['nome' => '0ª Rata', 'scadenza' => '05/11/2024'],
        1 => ['nome' => '1ª Rata', 'scadenza' => '05/11/2024'],
    ];

    $matrice = [
        [   // condòmino a debito
            'etichetta' => 'DEBITORE',
            'importi_per_rata' => [0 => 300000, 1 => 50000],
            'totale' => 350000,
        ],
        [   // condòmino a credito: rata zero negativa (saldo iniziale)
            'etichetta' => 'CREDITORE',
            'importi_per_rata' => [0 => -20372, 1 => 5191],
            'totale' => -15181,
        ],
    ];

    $html = View::make('pdf.gestionale._tabella_scadenziario', [
        'matrice' => $matrice,
        'colonneRate' => $colonneRate,
        'labelColonna' => 'Condòmino',
        'hasSottoEtichetta' => false,
    ])->render();

    // 1. La cella della rata zero del creditore ora mostra il credito, non un trattino
    expect($html)->toContain('203,72');

    // 2. Il totale di riga lo mostra comunque
    expect($html)->toContain('-151,81');

    // 3. Il totale di colonna della rata zero coincide con la somma delle celle ora visibili:
    //    300000 - 20372 = 279628  =>  € 2.796,28
    expect($html)->toContain('2.796,28');
});

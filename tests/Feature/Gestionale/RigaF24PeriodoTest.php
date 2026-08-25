<?php

/**
 * Il periodo di riferimento di una riga F24, lato server.
 *
 * È il gemello PHP di `resources/js/lib/gestionale/f24/prospetto.test.ts`. Lo stesso valore
 * è calcolato due volte in due linguaggi — qui e nell'interfaccia — ed è lo schema che nella
 * beta.35 è costato un centesimo di divergenza sul netto da pagare: nessuna delle due copie
 * era sbagliata da sola, lo erano insieme.
 *
 * Se un domani cambia questo lato, è questo test ad accendersi e a ricordare che esiste
 * anche l'altro.
 */

use App\Models\Gestionale\RigaF24;

test('«0003» e «2026» diventano 03/2026', function () {
    $riga = new RigaF24(['rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026']);

    expect($riga->periodoLeggibile())->toEqual('03/2026');
});

test('dicembre resta a due cifre', function () {
    $riga = new RigaF24(['rateazione_mese_rif' => '0012', 'anno_riferimento' => '2026']);

    expect($riga->periodoLeggibile())->toEqual('12/2026');
});

/**
 * I primi due caratteri NON sono il mese: nel tracciato quella posizione ospita anche altre
 * forme, ed è la ragione per cui il campo è una stringa di quattro caratteri e non un
 * intero. Leggere la prima coppia sembrerebbe funzionare su «0003» e sbaglierebbe su tutto
 * il resto.
 */
test('legge la seconda coppia di caratteri, non la prima', function () {
    $riga = new RigaF24(['rateazione_mese_rif' => '0101', 'anno_riferimento' => '2026']);

    expect($riga->periodoLeggibile())->toEqual('01/2026');
});

test('i due lati dicono la stessa cosa', function (string $mese, string $anno, string $atteso) {
    $riga = new RigaF24(['rateazione_mese_rif' => $mese, 'anno_riferimento' => $anno]);

    expect($riga->periodoLeggibile())->toEqual($atteso);
})->with([
    ['0001', '2026', '01/2026'],
    ['0006', '2026', '06/2026'],
    ['0012', '2027', '12/2027'],
]);

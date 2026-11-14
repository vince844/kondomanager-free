<?php

/**
 * Il cruscotto: la frase della voce lasciata al conguaglio dice dove il conguaglio si legge, e accanto c'è la porta alla scheda «Conguaglio» del
 * rendiconto dell'esercizio. Il file della pagina letto come testo, come i test delle frasi.
 */

it('accanto alla frase «si legge nella scheda Conguaglio» c\'è la porta alla scheda, per la voce lasciata al conguaglio', function () {
    $cruscotto = file_get_contents(base_path('resources/js/pages/gestionale/dashboard/Dashboard.vue'));
    $frase = strpos($cruscotto, 'si legge nella scheda «Conguaglio» del rendiconto');
    $porta = strpos($cruscotto, "<Link v-if=\"orfano.strategia === 'conguaglio'\" data-test=\"apri-conguaglio\"");

    expect($frase)->not->toBeFalse()->and($porta)->not->toBeFalse()->and($porta)->toBeGreaterThan($frase)
        ->and(substr($cruscotto, $porta, 700))->toContain("generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/conguaglio', { condominio: condominio.id, esercizio: esercizio.id })")
        ->toContain('Apri il conguaglio');
});

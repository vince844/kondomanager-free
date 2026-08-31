<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used by the paginator library to build
    | the simple pagination links. You are free to change them to anything
    | you want to customize your views to better match your application.
    |
    */

    'previous' => '&laquo; Precedente',
    'next'     => 'Successiva &raquo;',


    // ⚠️ **Le due righe qui sopra sono per la paginazione resa dal server** (Blade), e contengono
    // entità HTML: riusarle nel browser stamperebbe «&laquo;» a lettere. Quelle qui sotto sono le
    // etichette dei pulsanti dell'interfaccia, aggiunte nella 1.11.0-beta.10 — fino a quel momento
    // erano scritte in inglese dentro i componenti, e si vedevano su tutti e quattro gli elenchi
    // dell'area del condòmino: bacheca, segnalazioni, documenti, agenda.
    'controls' => [
        'first'    => 'Prima',
        'previous' => 'Precedente',
        'next'     => 'Successiva',
        'last'     => 'Ultima',

        'first_page'    => 'Prima pagina',
        'previous_page' => 'Pagina precedente',
        'next_page'     => 'Pagina successiva',
        'last_page'     => 'Ultima pagina',

        'page'       => 'Pagina :page',
        'more_pages' => 'Altre pagine',
    ],

];

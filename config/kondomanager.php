<?php

/**
 * Impostazioni dell'installazione che arrivano dall'ambiente: chi ospita KondoManager le decide,
 * chi lo usa le subisce. Nascono per l'immagine Docker (dove il codice non si ritocca), ma
 * valgono ovunque ci sia un `.env`.
 *
 * Chi legge queste chiavi lo fa sempre con un ripiego: un `config:cache` fatto prima che questo
 * file esistesse non le contiene (vedi `ChiaviDiConfigurazioneRecentiConRipiegoTest`).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Tetto al numero di condomini
    |--------------------------------------------------------------------------
    |
    | Quanti condomini può avere questa installazione. Assente, vuoto o 0 = nessun limite (ed è
    | il caso di ogni installazione autonoma). Lo applica `CondominioService` alla creazione di un
    | condominio, dimostrativo compreso; non lo applica l'importatore, che porta dentro un archivio
    | esistente e non lo crea. Chi ospita il servizio lo imposta in base al piano.
    */
    'limite_condomini' => (int) env('LIMITE_CONDOMINI', 0),

];

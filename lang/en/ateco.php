<?php

// L'aiuto alla compilazione del codice ATECO (1.11.0-beta.8). Il campo resta libero: questi testi
// descrivono un aiuto, non un obbligo. La riga della fonte dichiara la **revisione** e non una data,
// perché l'ATECO cambia per revisioni e nel file ISTAT una data non esiste — verificato cella per
// cella. Vedi `LettoreStrutturaAteco`.

// ⚠️ I due codici citati in `not_found` sono **di ATECO 2025 e vanno riletti a ogni revisione**.
// Verificati sulla tabella caricata il 30/08/2026: 68.32.01 «Gestione di beni immobili per conto
// terzi» e 97.00.10 «Attività di condomini come datori di lavoro per personale domestico» esistono
// entrambi, e i due precedenti (68.32.00 e 97.00.02) **non ci sono più**. Il giorno che
// `kondomanager:verifica-fonte-ateco` segnala una revisione nuova, questa riga è fra le cose da
// ricontrollare: un aiuto che cita un codice ritirato è peggio di un aiuto che non cita niente.

return [
    'button_title' => 'Search the code in the ATECO classification',
    'button_label' => 'Search the ATECO code',
    'dialog_title' => 'Search the ATECO code',
    'dialog_description' => 'Type the code, or the activity in words. The field stays editable by hand: if the code is not there, close and type it yourself.',
    'placeholder' => 'e.g. 43.22.01, or «plumbing systems»',
    'searching' => 'Searching…',
    'min_chars' => 'Type at least two characters.',
    'not_found' => 'No code found. Try a single word, or search by code: the official ATECO 2025 titles are in Italian and changed with the new classification. The two most used here: 68.32.01 for the condominium administrator (now called «gestione di beni immobili per conto terzi») and 97.00.10 for the building as an employer.',
    'error' => 'I cannot query the classification. Try again, or type the code by hand in the field.',
    'truncated' => 'Showing the first :mostrati of :totale. Type a few more letters to narrow it down.',
    'source_version' => 'Classification :versione, published by ISTAT.',
    'empty_list' => 'The ATECO classification has not been loaded on this installation yet.',
];

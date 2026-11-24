<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

return [

    /*
    |--------------------------------------------------------------------------
    | Errors Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during HTTP errors for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    '403' => [
        'invalid_signature' => 'The link you are trying to use is expired or invalid.',
        'account_suspended' => 'Your account is temporarily suspended. Please contact the system administrator to reactivate it.',
    ],
    '413_title' => 'The file is too large',

    '403_title'         => 'You cannot open this page',
    '403_heading'       => 'You cannot open this page',
    '403_message'       => 'Your account does not have permission for this page. If you need it, ask the office.',
    '429_title'         => 'Too many attempts',
    '429_heading'       => 'Too many attempts',
    '429_message'       => 'Too many attempts in a short time: for security this action is paused for a while.',
    '429_retry_minutes' => 'You can try again in one minute.|You can try again in :count minutes.',
    '429_retry_later'   => 'Please try again later.',
    '404_title'         => 'Page not found',
    '404_heading'       => 'Page not found',
    '404_message'       => 'The page you are looking for is not here: the address may be wrong, or the page has been removed.',
    '500_title'         => 'Internal error',
    '500_heading'       => 'Internal error',
    '500_message'       => 'An unexpected error occurred. Try again in a few minutes.',
    'back_to_dashboard' => 'Back to Dashboard',
    'back_to_login'     => 'Back to sign in',
    'errore_numero' => 'Error :numero',
    '500_message_dopo_salvataggio' => 'An unexpected error occurred. Check whether the operation was saved before doing it again.',
    'codice_di_questo_errore' => 'The code of this error',
    'comunica_allo_studio' => 'If it happens again, give this code to your property manager:',
    'copia' => 'Copy',
    'copiato' => 'Copied',
    'avvisa_lo_studio' => 'Let the office know',
    'avvisa_l_amministratore' => 'Let the administrator know',
    'avviso_sotto_studio' => 'With one click the office receives the code, the time and the page you were on.',
    'avviso_sotto_amministratore' => 'They receive the code, the time and the page you were on.',
    'avviso_domanda' => 'What were you doing?',
    'facoltativo' => '(optional)',
    'avviso_esempio' => 'For example: I was downloading the November instalment receipt',
    'non_scrivere_password' => 'Do not write passwords.',
    'avviso_fatto_titolo' => 'The office has been notified',
    'avviso_fatto_titolo_amministratore' => 'The administrator has been notified',
    'avviso_fatto' => 'They received the code :codice, the time and the page. You do not need to do anything else.',
    'avviso_fatto_con_nota' => 'They received the code :codice, the time and the page, with your note. You do not need to do anything else.',
    'avviso_gia' => 'The office has already been notified of this error.',
    'avviso_troppi' => 'You have already sent five notices in the last hour: the office has received them. Try again later.',
    'avviso_non_riuscito' => 'The notice was not sent. Please give the code to the office yourself.',
    'avviso_non_trovato' => 'This error is not in the log: please give the code to the office yourself.',
    'avviso_non_nella_demo' => 'Notices cannot be sent in the public demo: the error is already logged with its code.',
    'avviso_vedi_gia' => 'You already see the details of this error: you will find it in the «Errori» tab.',
    'avviso_non_attivo' => 'The office does not receive notices from the error page: please give the code to the office yourself.',
    'avviso_spento_frase' => 'If you need help, give this code to the office: with the code they can find right away what happened.',
    'database_fermo_titolo' => 'The program cannot reach its data.',
    'database_fermo' => 'This error is written only in the server log, where whoever runs the installation finds it with the code. For now the notice to the office cannot be sent from here.',
    'troppe_volte' => 'This error has happened many times today: it is written only in the server log, where whoever runs the installation finds it with the code.',
    'demo_titolo' => 'This is the public demo.',
    'demo' => 'The technical details are shown to nobody; the error is logged with its code, and whoever looks after the demo finds it.',
    'dettaglio_titolo' => 'Technical details (only you can see them)',
    'dettaglio_pagina' => 'Page',
    'dettaglio_nel_programma' => 'In the program',
    'dettaglio_versione' => 'Version',
    'dettaglio_testo_intero' => 'Full text',
    'dettaglio_testo_intero_valore' => 'in the server log, storage/logs/laravel.log: search for :codice',
    'riga' => ':file, line :riga',
    'copia_il_rapporto' => 'Copy the report',
    'rapporto_copiato' => 'Report copied',
    'apri_nella_scheda' => 'Open in the «Errori» tab',
    'mostra_la_traccia' => 'Show the trace (:righe lines)',
    'riprova' => 'Try again',
    'riprova_fra' => 'Try again in :tempo',
    'riprova_adesso' => 'Try again now',
    'torna_alla_pagina_iniziale' => 'Back to the home page',
    'torna_indietro' => 'Go back',
    'pagina_iniziale' => 'Home page',
    'ricarica_la_pagina' => 'Reload the page',
    '419_title' => 'The page has expired',
    '419_heading' => 'The page has expired',
    '419_message' => 'For security a page left open for a long time can no longer save. Reload the page and repeat the last operation: what you had written and not saved must be written again.',
    '503_title' => 'Maintenance in progress',
    '503_heading' => 'Maintenance in progress',
    '503_message' => 'The program is stopped for an update and will be available again in a few minutes. There is nothing you need to do.',
    '503_ricarica' => 'This page reloads by itself every :secondi seconds.',
    '503_ultimo_controllo' => 'Last check at :ora.',
    '4xx_title' => 'The request cannot be completed',
    '4xx_heading' => 'The request cannot be completed',
    '4xx_message' => 'The program cannot do what you asked from this page. Go back and try again.',
    '5xx_title' => 'The program is not responding',
    '5xx_heading' => 'The program is not responding',
    '5xx_message' => 'The server cannot respond right now. Try again in a few minutes.',
];

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
    | The following language lines are used during http errors for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */
    '403' => [
        'invalid_signature' => 'Il link che stai utilizzando potrebbe essere scaduto o non più valido.',
        'account_suspended' => "Il tuo account è stato temporaneamente sospeso. Per favore contatta l'amministratore di condominio per riattivarlo.",
    ],
    '413_title' => 'Il file è troppo grande',
    '403_title'         => 'Non puoi aprire questa pagina',
    '403_heading'       => 'Non puoi aprire questa pagina',
    '403_message'       => 'Il tuo account non ha il permesso per questa pagina. Se ti serve, chiedilo allo studio.',
    '429_title'         => 'Troppi tentativi',
    '429_heading'       => 'Troppi tentativi',
    '429_message'       => "Troppi tentativi in poco tempo: per sicurezza questa operazione è sospesa per un po'.",
    '429_retry_minutes' => 'Puoi riprovare fra un minuto.|Puoi riprovare fra :count minuti.',
    '429_retry_later'   => 'Riprova più tardi.',
    '404_title'         => 'Pagina non trovata',
    '404_heading'       => 'Pagina non trovata',
    '404_message'       => 'La pagina che cerchi non c\'è: l\'indirizzo può essere sbagliato, o la pagina è stata tolta.',
    '500_title'         => 'Errore interno',
    '500_heading'       => 'Errore interno',
    '500_message'       => 'Si è verificato un errore imprevisto. Riprova fra qualche minuto.',
    'back_to_dashboard' => 'Torna alla dashboard',
    'back_to_login'     => 'Torna all\'accesso',
    'errore_numero' => 'Errore :numero',
    '500_message_dopo_salvataggio' => 'Si è verificato un errore imprevisto. Controlla se l\'operazione è stata salvata prima di rifarla.',
    'codice_di_questo_errore' => 'Il codice di questo errore',
    'comunica_allo_studio' => 'Se si ripete, comunica allo studio questo codice:',
    'copia' => 'Copia',
    'copiato' => 'Copiato',
    'avvisa_lo_studio' => 'Avvisa lo studio',
    'avvisa_l_amministratore' => 'Avvisa l\'amministratore',
    'avviso_sotto_studio' => 'Con un clic lo studio riceve il codice, l\'ora e la pagina in cui eri.',
    'avviso_sotto_amministratore' => 'Riceve il codice, l\'ora e la pagina in cui eri.',
    'avviso_domanda' => 'Che cosa stavi facendo?',
    'facoltativo' => '(facoltativo)',
    'avviso_esempio' => 'Per esempio: scaricavo la ricevuta della rata di novembre',
    'non_scrivere_password' => 'Non scrivere password.',
    'avviso_fatto_titolo' => 'Lo studio è avvisato',
    'avviso_fatto_titolo_amministratore' => 'L\'amministratore è avvisato',
    'avviso_fatto' => 'Ha ricevuto il codice :codice, l\'ora e la pagina. Non devi fare altro.',
    'avviso_fatto_con_nota' => 'Ha ricevuto il codice :codice, l\'ora e la pagina, con la tua nota. Non devi fare altro.',
    'avviso_gia' => 'Lo studio è già avvisato di questo errore.',
    'avviso_troppi' => 'Hai già mandato cinque avvisi nell\'ultima ora: lo studio li ha ricevuti. Riprova più tardi.',
    'avviso_non_riuscito' => 'L\'avviso non è partito. Comunica tu il codice allo studio.',
    'avviso_non_trovato' => 'Questo errore non è nel registro: comunica tu il codice allo studio.',
    'avviso_non_nella_demo' => 'Nella demo pubblica gli avvisi non si mandano: l\'errore è già registrato con il suo codice.',
    'avviso_vedi_gia' => 'Tu vedi già il dettaglio di questo errore: lo trovi nella scheda «Errori».',
    'avviso_non_attivo' => 'Lo studio non riceve avvisi dalla pagina d\'errore: comunica tu il codice allo studio.',
    'avviso_spento_frase' => 'Se ti serve aiuto, comunica questo codice allo studio: con il codice ritrova subito che cosa è successo.',
    'database_fermo_titolo' => 'Il programma non raggiunge i suoi dati.',
    'database_fermo' => 'Questo errore è scritto solo nel registro del server, dove chi gestisce l\'installazione lo trova con il codice. Per ora l\'avviso allo studio non si può mandare da qui.',
    'troppe_volte' => 'Questo errore si è ripetuto molte volte oggi: è scritto solo nel registro del server, dove chi gestisce l\'installazione lo trova con il codice.',
    'demo_titolo' => 'Questa è la demo pubblica.',
    'demo' => 'Il dettaglio tecnico non si mostra a nessuno; l\'errore è registrato con il suo codice, e chi cura la demo lo ritrova.',
    'dettaglio_titolo' => 'Dettaglio tecnico (lo vedi solo tu)',
    'dettaglio_pagina' => 'Pagina',
    'dettaglio_nel_programma' => 'Nel programma',
    'dettaglio_versione' => 'Versione',
    'dettaglio_testo_intero' => 'Il testo intero',
    'dettaglio_testo_intero_valore' => 'nel registro del server, storage/logs/laravel.log: cerca :codice',
    'riga' => ':file, riga :riga',
    'copia_il_rapporto' => 'Copia il rapporto',
    'rapporto_copiato' => 'Rapporto copiato',
    'apri_nella_scheda' => 'Apri nella scheda «Errori»',
    'mostra_la_traccia' => 'Mostra la traccia (:righe righe)',
    'riprova' => 'Riprova',
    'riprova_fra' => 'Riprova fra :tempo',
    'riprova_adesso' => 'Riprova adesso',
    'torna_alla_pagina_iniziale' => 'Torna alla pagina iniziale',
    'torna_indietro' => 'Torna indietro',
    'pagina_iniziale' => 'Pagina iniziale',
    'ricarica_la_pagina' => 'Ricarica la pagina',
    '419_title' => 'La pagina è scaduta',
    '419_heading' => 'La pagina è scaduta',
    '419_message' => 'Per sicurezza una pagina aperta da molto tempo non può più salvare. Ricarica la pagina e rifai l\'ultima operazione: ciò che avevi scritto e non avevi salvato va riscritto.',
    '503_title' => 'Manutenzione in corso',
    '503_heading' => 'Manutenzione in corso',
    '503_message' => 'Il programma è fermo per un aggiornamento e torna disponibile fra pochi minuti. Non serve fare niente.',
    '503_ricarica' => 'Questa pagina si ricarica da sola ogni :secondi secondi.',
    '503_ultimo_controllo' => 'Ultimo controllo alle :ora.',
    '4xx_title' => 'La richiesta non si può completare',
    '4xx_heading' => 'La richiesta non si può completare',
    '4xx_message' => 'Il programma non può fare quello che hai chiesto da questa pagina. Torna indietro e riprova.',
    '5xx_title' => 'Il programma non risponde',
    '5xx_heading' => 'Il programma non risponde',
    '5xx_message' => 'Il server non riesce a rispondere in questo momento. Riprova fra qualche minuto.',
    // Il messaggio in pagina per un guasto raccolto da un `catch` generico (`MessaggioPerLaPagina`).
    'guasto_con_riferimento' => 'L\'operazione non è riuscita per un problema del programma. Se si ripete, comunica all\'assistenza il riferimento :riferimento.',
];

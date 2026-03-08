<?php

return [
    'header' => [
        'control_panel' => 'Pannello di controllo',
    ],

    'actions' => [
        'action_inbox' => 'Action inbox',
        'view_all' => 'Visualizza tutti',
        'view_all_feminine' => 'Visualizza tutte',
    ],

    'kpis' => [
        'registered_buildings' => 'Condomini registrati',
        'all_buildings' => 'Tutti i fabbricati',
        'open_tickets' => 'Segnalazioni aperte',
        'action_required' => 'Azione richiesta',
        'no_tickets' => 'Nessuna segnalazione',
        'upcoming_deadlines' => 'Scadenze imminenti',
        'next_7_days' => 'Prossimi 7 giorni',
        'storage' => 'Archiviazione',
        'usage' => 'Utilizzo',
        'files_archived' => ':count file archiviati',
        'document_archive' => 'Archivio documenti',
    ],

    'widgets' => [
        'latest_documents_title' => 'Ultimi documenti',
        'latest_documents_description' => 'Elenco degli ultimi documenti in archivio caricati',
        'upcoming_events_title' => 'Prossime scadenze in agenda',
        'upcoming_events_description' => 'Elenco delle scadenze nei prossimi giorni',
        'no_events_created' => 'Nessuna scadenza in agenda ancora creata!',
        'starts_on' => 'inizia il',
        'show_more' => 'Mostra tutto',
        'show_less' => 'Mostra meno',
    ],

    'permissions' => [
        'view_archive_documents' => 'Non hai permessi sufficienti per visualizzare documenti in archivio!',
        'view_events' => 'Non hai permessi sufficienti per visualizzare le scadenze in agenda!',
    ],

    'event_style' => [
        'expired_and_to_issue' => 'Scaduto e da emettere',
        'to_issue' => 'Da emettere',
        'urgent_check' => 'Verifica urgente',
        'payment_check' => 'Verifica incassi',
        'rejected' => 'Rifiutato',
        'paid' => 'Pagato',
        'covered' => 'Coperta',
        'partially_covered' => 'Parz. coperta',
        'credit' => 'A credito',
        'partially_paid' => 'Pagato parz.',
        'in_review' => 'In verifica',
        'expired' => 'Scaduto',
        'expires_in_days' => 'Scade tra :count gg',
        'in_days' => 'Tra :count giorni',
    ],

    'event_categories' => [
        'maintenance' => 'Manutenzione',
        'assembly' => 'Assemblea',
        'cleaning' => 'Pulizia',
        'generic' => 'Generiche',
        'intervention_requests' => 'Richieste di intervento',
        'administrative_deadlines' => 'Scadenze amministrative',
        'installment_deadlines' => 'Scadenze rate',
    ],

    'buildings_dropdown' => [
        'select_aria' => 'Seleziona condominio',
        'select_placeholder' => 'Seleziona condominio...',
        'search_placeholder' => 'Cerca condominio...',
        'empty_state' => 'Nessun condominio trovato.',
        'reset_selection' => 'Reset selezione',
        'management' => 'Gestione',
        'go_to_management_title' => 'Vai al pannello di gestione',
    ],

    'inbox' => [
        'page_title' => 'Action inbox',
        'back_to_dashboard' => 'Torna alla dashboard',
        'subtitle' => 'Il tuo centro di comando. Gestisci scadenze e incassi da un unico punto.',
        'expiring_activities' => 'Attività in scadenza',
        'not_available' => '—',
        'yesterday' => 'Ieri',
        'days_late' => ':count giorni di ritardo',
        'results_shown' => 'Mostrati :count risultati',
        'filters' => [
            'urgent' => 'Scaduti / Urgenti',
            'payments' => 'Verifiche incassi',
            'maintenance' => 'Ticket e manutenzione',
            'all' => 'Vedi tutto',
            'reset' => 'Reset filtri',
        ],
        'table' => [
            'deadline' => 'Scadenza',
            'building' => 'Condominio',
            'activity' => 'Attività',
            'actions' => 'Azioni',
        ],
        'actions' => [
            'reject_report' => 'Rifiuta segnalazione',
            'register' => 'Registra',
            'manage' => 'Gestisci',
            'details' => 'Dettagli',
        ],
        'empty' => [
            'title' => 'Tutto pulito!',
            'description' => 'Nessuna attività urgente richiede attenzione.',
        ],
        'reject_modal' => [
            'title' => 'Rifiuta segnalazione',
            'description_prefix' => 'Stai per rifiutare il pagamento segnalato da',
            'tenant_fallback' => 'Condòmino',
            'description_warning' => 'Attenzione: questa azione sarà irreversibile.',
            'reason_label' => 'Motivazione (visibile all\'utente)',
            'reason_placeholder' => 'Es.: Bonifico non trovato nell\'estratto conto...',
            'confirm' => 'Conferma rifiuto',
        ],
    ],
];

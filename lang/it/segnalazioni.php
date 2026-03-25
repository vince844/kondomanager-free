<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_ticket'               => "La nuova segnalazione guasto è stata creata con successo.",
    'success_create_ticket_in_moderation' => "La nuova segnalazione guasto è stata creata con successo, ma richiede l'approvazione di un amministratore.",
    'error_create_ticket'                 => "Si è verificato un errore durante la creazione della segnalazione guasto.",
    'success_update_ticket'               => "La segnalazione guasto è stata aggiornata con successo.",
    'error_update_ticket'                 => "Si è verificato un errore durante l'aggiornamento della segnalazione guasto.",
    'success_delete_ticket'               => "La segnalazione guasto è stata eliminata con successo.",
    'error_delete_ticket'                 => "Si è verificato un errore durante l'eliminazione della segnalazione guasto.",
    'success_approve_ticket'              => "La segnalazione guasto è stata approvata con successo.",
    'error_approve_ticket'                => "Si è verificato un errore durante l'approvazione della segnalazione guasto.",
    'success_unapprove_ticket'            => "La segnalazione guasto è stata messa in moderazione con successo.",
    'error_unapprove_ticket'              => "Si è verificato un errore durante il tentativo di mettere la segnalazione guasto in moderazione.",
    'error_notify_approved_ticket'        => "La segnalazione guasto è stata approvata, ma si è verificato un errore nell'invio della notifica.",
    'success_lock_ticket'                 => "La segnalazione guasto è stata bloccata con successo.",
    'error_lock_ticket'                   => "Si è verificato un errore durante il tentativo di bloccare della segnalazione guasto.",
    'success_unlock_ticket'               => "La segnalazione guasto è stata sbloccata con successo.",
    'error_unlock_ticket'                 => "Si è verificato un errore durante il tentativo di sbloccare della segnalazione guasto.",

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_tickets_head'          => "Elenco segnalazioni guasto",
        'list_tickets_title'         => "Elenco segnalazioni guasto",
        'list_tickets_description'   => "Di seguito la tabella con l'elenco di tutte le segnalazioni guasto registrate",
        'new_ticket_head'            => "Crea segnalazione guasto",
        'new_ticket_title'           => "Crea segnalazione guasto",
        'new_ticket_description'     => "Compila il seguente modulo per la creazione di una nuova segnalazione guasto",
        'view_ticket_head'           => "Visualizza segnalazione guasto",
        'view_ticket_title'          => "Dettaglio segnalazione",
        'view_ticket_description'    => "Di seguito i dettagli e lo stato della segnalazione guasto",
        'edit_ticket_head'           => "Modifica segnalazione guasto",
        'edit_ticket_title'          => "Modifica segnalazione guasto",
        'edit_ticket_description'    => "Compila il seguente modulo per modificare segnalazione guasto",
        'widget_tickets_title'       => "Ultime segnalazioni guasto",
        'widget_tickets_description' => "Elenco delle ultime segnalazioni guasto inviate",
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'  => "Dettagli segnalazione",
        'content_desc'   => "Inserisci le informazioni relative al problema da segnalare.",
        'location_title' => "Destinatari",
        'location_desc'  => "Indica il condominio e le anagrafiche legate a questa segnalazione.",
        'settings_title' => "Gestione operativa",
        'settings_desc'  => "Imposta lo stato di lavorazione, la priorità e la visibilità della segnalazione.",
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => "Dettagli operativi",
        'priority_level'    => "Livello Priorità",
        'visibility_status' => "Stato Visibilità",
        'current_status'    => "Stato Lavorazione",
        'published'         => "Pubblicata",
        'draft'             => "Bozza / Nascosta",
        'interactions'      => "Interazioni",
        'comments_enabled'  => "Commenti abilitati",
        'comments_disabled' => "Commenti disabilitati",
        'admin_sender'      => "Amministratore",
        'locked'            => 'Bloccata',
        'unlocked'          => 'Aperta',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_ticket_title'       => "Sei sicuro di volere eliminare questa segnalazione?",
        'delete_ticket_description' => "Questa azione non è reversibile. Eliminerà la segnalazione e tutti i dati ad essa associati.",
        'no_tickets'                => "Nessuna segnalazione guasto",
        'no_tickets_created'        => "Nessuna segnalazione guasto ancora creata",
        'no_view_permission'        => "Non hai permessi sufficienti per visualizzare le segnalazioni!",
        'no_tickets_found'          => "Nessuna segnalazione guasto trovata.",
        'change_search_criteria'    => "Modifica i criteri di ricerca e riprova.",
        'cancel_search'             => "Annulla ricerca",
        'loading_error'             => "Si è verificato un errore durante il caricamento delle segnalazioni guasto. Riprova più tardi.",
        'loading'                   => "Caricamento in corso...",
        'try_again'                 => "Riprova",
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'low_priority'    => "Priorità bassa",
        'medium_priority' => "Priorità media",
        'high_priority'   => "Priorità alta",
        'urgent_priority' => "Priorità urgente",
        'open_tickets'    => "Segnalazioni aperte",
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'priority'           => 'Priorità',
        'status'             => 'Stato',
        'filter_by_title'    => 'Filtra per titolo...',
        'title'              => 'Titolo',
        'buildings'          => 'Condomini',
        'residents'          => 'Anagrafiche',
        'visibility'         => 'Visibilidade',
        'approved_tooltip'   => 'Approvata - clicca per rimuovere approvazione',
        'unapproved_tooltip' => 'Non approvata - clicca per approvare',
        'clear_all_filters'  => 'Resetta tutti i filtri',
        'sort_asc'           => 'Ascendente',
        'sort_desc'          => 'Discendente',
        'loading'            => 'Caricamento...',
        'no_results'         => 'Nessun risultato trovato.',
        'selected'           => 'Selezionati',
        'actions'            => 'Azioni',
        'none'               => 'Nessuna',
    ],

    /* ------------------------------------------------------------------
     | Status
     | ------------------------------------------------------------------ */
    'status' => [
        'open'        => 'Aperta',
        'in_progress' => 'In lavorazione',
        'closed'      => 'Chiusa',
    ],

    /* ------------------------------------------------------------------
     | Priority
     | ------------------------------------------------------------------ */
    'priority' => [
        'low'    => 'Bassa',
        'medium' => 'Media',
        'high'   => 'Alta',
        'urgent' => 'Urgente',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'     => 'Pubblica',
        'private'    => 'Privata',
        'created_on' => 'Creata il',
        'sent_on_by' => 'Inviata :date da :name',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_ticket'       => 'Crea segnalazione',
        'edit_ticket'      => 'Modifica',
        'delete_ticket'    => 'Elimina',
        'save_ticket'      => 'Salva',
        'list_tickets'     => 'Elenco',
        'lock_ticket'      => 'Blocca',
        'unlock_ticket'    => 'Sblocca',
        'view_all_tickets' => 'Visualizza tutte',
        'show_more'        => 'Mostra tutto',
        'show_less'        => 'Mostra meno',
        'cancel'           => 'Annulla',
        'back'             => 'Indietro',
        'back_to_list'     => "Torna all'elenco",
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'object'             => 'Oggetto segnalazione',
        'description'        => 'Descrizione segnalazione',
        'visibility'         => 'Visibilità segnalazione',
        'priority'           => 'Priorità segnalazione',
        'status'             => 'Stato segnalazione',
        'publication_status' => 'Stato della pubblicazione',
        'published'          => 'Pubblicata',
        'draft'              => 'Bozza',
        'building'           => 'Condominio',
        'resident'           => 'Anagrafica',
        'comments'           => 'Permetti di commentare',
        'featured'           => 'Segnalazione in evidenza',
        'private'            => 'Crea segnalazione come privata',
        'no_priority'        => 'Nessuna',
        'no_status'          => 'Nessuno',

    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'object'      => 'Inserisci l\'oggetto della segnalazione',
        'description' => 'Inserisci la descrizione della segnalazione',
        'visibility'  => 'Seleziona visibilità',
        'priority'    => 'Seleziona priorità',
        'status'      => 'Seleziona stato',
        'building'    => 'Seleziona condominio',
        'resident'    => 'Seleziona anagrafica',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility' => 'Se impostata su privata, solo gli amministratori potranno visualizzare la segnalazione.',
        'priority'   => 'Imposta il livello di priorità per questa segnalazione in modo da aiutare gli amministratori a gestirla di conseguenza.',
        'status'     => 'Imposta lo stato attuale della segnalazione per tenere traccia dei progressi.',
        'comments'   => 'Consenti agli utenti di aggiungere commenti a questa segnalazione.',
        'featured'   => 'La segnalazione verrà messa in evidenza e comparirà sempre in cima all\'elenco delle segnalazioni',
        'private'    => 'Le segnalazioni private possono essere visualizzate solo dagli amministratori e da te.',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Segnalazioni',
        'new'  => 'Nuova segnalazione',
        'edit' => 'Modifica segnalazione',
        'view' => 'Dettaglio segnalazione',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'reports_title'    => 'Registro centralizzato',
        'reports_desc'     => 'Visualizza tutte le segnalazioni guasto in un unico luogo, con stato e priorità sempre aggiornati.',
        'workflow_title'   => 'Flusso operativo',
        'workflow_desc'    => 'Segui l\'intero ciclo del guasto, dall\'apertura alla risoluzione e chiusura.',
        'control_title'    => 'Controllo e priorità',
        'control_desc'     => 'Assegna la priorità alle emergenze critiche e gestisci gli interventi tecnici con criteri chiari e tracciabili.',
        'management_title' => 'Gestione Guasti',
        'management_desc'  => 'Raccogli e centralizza le segnalazioni per una transizione fluida verso i futuri interventi manutentivi.',
        'priority_title'   => 'Filtro Urgenze',
        'priority_desc'    => 'Identifica a colpo d\'occhio le emergenze critiche (tubi rotti, pericoli) e separale dalla manutenzione ordinaria.',
        'resolution_title' => 'Stato Interventi',
        'resolution_desc'  => 'Traccia l\'avanzamento della risoluzione per mantenere i condòmini sempre aggiornati sui progressi.',
        'issue_title'      => 'Dettagli del Guasto',
        'issue_desc'       => 'Fornisci una descrizione chiara e completa del problema riscontrato per agevolare la futura classificazione dell\'intervento.',
        'location_title'   => 'Contesto e Coinvolti',
        'location_desc'    => 'Indica con precisione il condominio interessato e associa le anagrafiche dei residenti per facilitare le comunicazioni e i sopralluoghi.',
        'settings_title'   => 'Parametri Operativi',
        'settings_desc'    => 'Definisci il livello di urgenza, aggiorna lo stato di lavorazione della pratica e regola i permessi di visibilità sulla bacheca.',
    ]
];
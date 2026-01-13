<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_communication'                => "La nuova comunicazione è stata creata con successo.",
    'success_create_communication_in_moderation'  => "La nuova comunicazione è stata creata con successo, ma deve essere approvata dall'amministratore",
    'error_create_communication'                  => "Si è verificato un errore durante la creazione della comunicazione.",
    'success_update_communication'                => "La comunicazione è stata aggiornata con successo",
    'error_update_communication'                  => "Si è verificato un errore durante l'aggiornamento della comunicazione.",
    'success_delete_communication'                => "La comunicazione è stata eliminata con successo.",
    'error_delete_communication'                  => "Si è verificato un errore durante l'eliminazione della comunicazione.",
    'success_approve_communication'               => "La comunicazione è stata approvata con successo.",
    'success_disapprove_communication'            => "La comunicazione è stata disapprovata con successo.",
    'error_approve_communication'                 => "Si è verificato un errore durante l'approvazione della comunicazione.",
    'error_notify_new_communication'              => "La comunicazione è stata creata, ma si è verificato un errore nell'invio della notifica.",
    'error_notify_approved_communication'         => "La comunicazione è stata approvata, ma si è verificato un errore nell'invio della notifica.",
    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_communications_head'          => "Elenco comunicazioni bacheca",
        'list_communications_title'         => "Elenco comunicazioni bacheca",
        'list_communications_description'   => "Di seguito la tabella con l'elenco di tutte le comunicazioni salvate nella bacheca del condominio",
        'new_communication_head'            => "Crea nuova comunicazione",
        'new_communication_title'           => "Crea nuova comunicazione",
        'new_communication_description'     => "Compila il seguente modulo per la creazione di una nuova comunicazione per la bacheca del condominio",
        'edit_communication_head'           => "Modifica comunicazione",
        'edit_communication_title'          => "Modifica comunicazione",
        'edit_communication_description'    => "Compila il seguente modulo per modificare la comunicazione per la bacheca del condominio",
        'view_communication_head'           => "Visualizza comunicazione",
        'widget_communications_title'       => "Comunicazioni recenti registrate",
        'widget_communications_description' => "Elenco delle ultime comunicazioni pubblicate in bacheca",                   
    ],
    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_communication_title'       => "Sei sicuro di volere eliminare questa comunicazione?",
        'delete_communication_description' => "Questa azione non è reversibile. Eliminerà la comunicazione e tutti i dati ad essa associati.",
        'no_communications'                => "Nessuna comunicazione",
        'no_communications_created'        => "Nessuna comunicazione ancora creata",
        'no_view_permission'               => "Non hai permessi sufficienti per visualizzare le comunicazioni!",
        'no_communications_found'          => "Nessuna comunicazione trovata.",
        'change_search_criteria'           => "Modifica i criteri di ricerca e riprova.",
        'cancel_search'                    => "Annulla ricerca",
        'loading_error'                    => "Si è verificato un errore durante il caricamento delle comunicazioni. Riprova più tardi.",
        'loading'                          => "Caricamento in corso...",
        'try_again'                        => "Riprova",
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
        'selected'           => 'Selezionati',
        'loading'            => 'Caricamento...',
        'no_results'         => 'Nessun risultato trovato.',
        'clear_all_filters'  => 'Resetta tutti i filtri',
        'approved_tooltip'   => 'Approvata - clicca per rimuovere approvazione',
        'unapproved_tooltip' => 'Non approvata - clicca per approvare',
        'actions'            => 'Azioni',
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
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'subject'      => 'Oggetto comunicazione',
        'description'  => 'Descrizione comunicazione',
        'visibility'   => 'Visibilità comunicazione',
        'priority'     => 'Priorità comunicazione',
        'buildings'    => 'Condomini',
        'residents'    => 'Anagrafiche',
        'comments'     => 'Consenti commenti',
        'featured'     => 'Comunicazione in evidenza',
        'private'      => 'Crea comunicazione come privata',
    ],
    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'subject'      => 'Inserisci oggetto comunicazione',
        'description'  => 'Inserisci descrizione comunicazione',
        'visibility'   => 'Seleziona visibilità comunicazione',
        'priority'     => 'Seleziona priorità comunicazione',
        'buildings'    => 'Seleziona condomini',
        'residents'    => 'Seleziona anagrafiche',
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
        'new_communication'       => 'Crea',
        'edit_communication'      => 'Modifica',
        'delete_communication'    => 'Elimina',
        'save_communication'      => 'Salva',
        'list_communications'     => 'Elenco',
        'show_more'               => 'Mostra tutto',
        'show_less'               => 'Mostra meno',
        'view_all_communications' => 'Visualizza tutte',
    ],
    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility'  => 'Se impostata su privata, solo gli amministratori potranno visualizzare la comunicazione.',
        'priority'    => 'Seleziona il livello di priorità con cui questa comunicazione deve essere trattata. Le priorità possono influenzare la visibilità o l\'urgenza nella bacheca.',
        'comments'    => 'Quando viene selezionata questa opzione verranno abilitati i commenti per questa comunicazione',
        'featured'    => 'Le comunicazioni in evidenza sono evidenziate nella bacheca per attirare maggiore attenzione.',
        'private'     => 'Le comunicazioni private possono essere visualizzate solo dagli amministratori e da te.',
    ]

];
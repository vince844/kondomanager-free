<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_document'        => 'Il nuovo documento è stato creato con successo.',
    'error_create_document'          => 'Si è verificato un errore durante la creazione del documento.',
    'no_file_uploaded'               => 'Nessun file caricato. Per favore riprova.',
    'file_not_found'                 => 'Nessun file trovato nel server.',
    'success_delete_document'        => 'Il documento è stato eliminato con successo.',
    'success_update_document'        => 'Il documento è stato aggiornato con successo.',
    'error_update_document'          => 'Si è verificato un errore durante l\'aggiornamento del documento.',
    'error_delete_document'          => 'Si è verificato un errore durante l\'eliminazione del documento.',
    'error_downloading_document'     => 'Si è verificato un errore durante il download del documento.',
    'success_approve_document'       => 'Il documento è stato approvato con successo.',
    'error_approve_document'         => 'Si è verificato un errore durante l\'approvazione del documento.',
    'error_notify_new_document'      => 'Il documento è stato creato, ma si è verificato un errore nell\'invio della notifica.',
    'error_notify_approved_document' => 'Il documento è stato approvato, ma si è verificato un errore nell\'invio della notifica.',
    'category_has_documents'         => 'Questa categoria contiene dei documenti. Spostali o eliminali prima di eliminare la categoria.',
    'success_delete_category'        => 'La categoria documenti è stata eliminata con successo.',
    'error_delete_category'          => 'Si è verificato un errore durante l\'eliminazione della categoria documento.',
    'success_create_category'        => 'La categoria documenti è stata creata con successo.',
    'error_create_category'          => 'Si è verificato un errore durante la creazione della categoria documento.',
    'success_update_category'        => 'La categoria documenti è stata aggiornata con successo.',
    'error_update_category'          => 'Si è verificato un errore durante l\'aggiornamento della categoria documenti.',

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_documents_head'           => 'Elenco archivio documenti',
        'list_documents_title'          => 'Elenco archivio documenti',
        'list_documents_description'    => 'Di seguito la tabella con l\'elenco di tutti i documenti salvati nell\'archivio del condominio',
        'new_document_head'             => 'Crea nuovo documento',
        'new_document_title'            => 'Crea documento in archivio',
        'new_document_description'      => 'Compila il seguente modulo per la creazione di un nuovo documento per l\'archivio del condominio',
        'edit_document_head'            => 'Modifica documento',
        'edit_document_title'           => 'Modifica documento archivio',
        'edit_document_description'     => 'Compila il seguente modulo per modificare documento per l\'archivio del condominio',
        'list_categories_head'          => 'Categorie archivio',
        'list_categories_title'         => 'Elenco categorie archivio documenti',
        'list_categories_description'   => 'Di seguito la tabella con l\'elenco di tutte le categorie documenti dell\'archivio del condominio',
        'categories' => [
            'new_category_title'            => 'Crea nuova categoria',
            'new_category_description'      => 'Aggiungi una nuova categoria per i documenti',
            'edit_category_title'           => 'Modifica categoria: :category',
            'edit_category_description'     => 'Di seguito puoi modificare i dettagli della categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'    => 'File e descrizione',
        'content_desc'     => 'Allega il documento e inserisci i metadati principali.',
        'settings_title'   => 'Classificazione',
        'settings_desc'    => 'Organizza il documento in archivio selezionando una categoria e definiscine la visibilità.',
        'recipients_title' => 'Destinatari del documento',
        'recipients_desc'  => 'Collega il file a specifici condomini e residenti (Anagrafiche).',
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => 'Dettagli classificazione',
        'current_status'    => 'Stato File',
        'visibility_status' => 'Stato Visibilità',
        'published'         => 'Pubblico',
        'draft'             => 'Privato (Solo Admin)',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'                  => 'Nome documento',
        'category'              => 'Categoria',
        'buildings'             => 'Condomini',
        'residents'             => 'Anagrafiche',
        'status'                => 'Stato',
        'filter_by'             => 'Filtra per nome...',
        'approved_tooltip'      => 'Approvato - clicca per rimuovere approvazione',
        'unapproved_tooltip'    => 'Non approvato - clicca per approvare',
        'no_results'            => 'Nessun risultato trovato.',
        'actions'               => 'Azioni',
        'selected'              => 'selezionati',
        'loading'               => 'Caricamento...',
        'clear_all_filters'     => 'Resetta tutti i filtri',
        'sort_asc'              => 'Ascendente',
        'sort_desc'             => 'Discendente',
        'categories' => [
            'name'        => 'Nome categoria',
            'description' => 'Descrizione categoria',
            'filter_by'   => 'Filtra per nome...',
            'no_results'  => 'Nessun risultato trovato.',
            'actions'     => 'Azioni',
        ],
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'name'                          => 'Nome documento',
        'description'                   => 'Descrizione documento',
        'category'                      => 'Categoria',
        'buildings'                     => 'Condomini',
        'residents'                     => 'Anagrafiche',
        'visibility'                    => 'Visibilità documento',
        'select_document'               => 'Seleziona documento',
        'replace_document'              => 'Sostituisci file',
        'remove_document'               => 'Rimuovi file',
        'replace_existing_document'     => 'Questo file sostituirà quello esistente.',
        'document'                      => 'Documento',
        'document_info'                 => 'Informazioni',
        'created'                       => 'Creato il:',
        'status'                        => 'Stato file:',
        'missing'                       => 'Mancante',
        'existing'                      => 'Presente',
        'categories' => [
            'category_name'        => 'Nome',
            'category_description' => 'Descrizione',
        ],
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Inserisci nome documento',
        'description' => 'Inserisci descrizione documento',
        'category'    => 'Seleziona categoria',
        'visibility'  => 'Seleziona visibilità documento',
        'buildings'   => 'Seleziona condomini',
        'residents'   => 'Seleziona anagrafiche',
        'categories'  => [
            'category_name'        => 'Nome della categoria',
            'category_description' => 'Descrizione della categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_documents_created'          => 'Nessun documento in archivio ancora creato.',
        'delete_document_title'         => 'Sei sicuro di voler eliminare questo documento?',
        'delete_document_description'   => 'Questa azione non è reversibile. Eliminerà il documento e tutti i dati associati.',
        'select_document_title'         => 'Trascina qui il tuo documento',
        'select_document_description'   => 'Oppure clicca per selezionarlo dal tuo dispositivo.',
        'document_supported_types'      => 'È ammesso solo il formato PDF.',
        'categories' => [
            'delete_category_title'       => 'Sei sicuro di voler eliminare questa categoria?',
            'delete_category_description' => 'Questa azione non è reversibile. Eliminerà la categoria e tutti i documenti ad essa associati.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Toast
     | ------------------------------------------------------------------ */

    'toast' => [
        'success_title'   => 'Successo',
        'success_message' => 'Categoria creata con successo.',
        'error_title'     => 'Errore',
        'error_message'   => 'Impossibile creare la categoria. Riprova più tardi.',
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    /*
     * ⚠️ **Ogni etichetta dice «archivio», e non è pignoleria.**
     *
     * Questi quattro numeri contano **solo** i documenti d'archivio: `getAdminDocumentiStats()`
     * filtra su `documentable_type` nullo, quindi esclude gli allegati delle fatture, i documenti
     * delle unità immobiliari e quelli dei fornitori. L'esclusione è voluta — l'archivio è un
     * posto, e l'allegato di una fattura vive sulla fattura — ma fino alla beta.62 le etichette
     * promettevano un totale: «Documenti totali», «Spazio totale utilizzato».
     *
     * Misurato il 21/08/2026 sul database di sviluppo: **sei** documenti su disco, i riquadri ne
     * contavano **due**; spazio reale ~11 MB, i riquadri dicevano **414 KB**. Segnalato da un
     * amministratore sul forum e confermato da Vincenzo.
     *
     * ⏳ Lo **spazio** avrà un trattamento a parte nella 1.10.0-beta.63: «quanto disco sto
     * occupando» è una domanda legittima a cui un numero limitato all'archivio risponde male, e
     * lì il riquadro tornerà a essere un totale — con la ripartizione per luogo. Vedi la voce
     * «Coda 55» in `docs/roadmap.md`.
     */
    'stats' => [
        'total_storage_bytes'  => 'Spazio dell\'archivio',
        'total_documents'      => 'Documenti in archivio',
        'uploaded_this_month'  => 'In archivio questo mese',
        'average_size_bytes'   => 'Dimensione media in archivio',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'                => 'Pubblico',
        'private'               => 'Privato',
        'created_on'            => 'Creato il',
        'sent_on_by'            => 'Inviato :date da :name',
        'sent_on_by_category'   => 'Inviato :date da :name in :category',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility' => 'Se impostata su privata, solo gli amministratori potranno visualizzare il documento.',
        'category'   => 'Seleziona una categoria per organizzare meglio i documenti, oppure creane una nuova.',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_document'       => 'Crea documento',
        'list_categories'    => 'Categorie',
        'edit_document'      => 'Modifica',
        'delete_document'    => 'Elimina',
        'save_document'      => 'Salva',
        'list_documents'     => 'Elenco',
        'cancel'             => 'Annulla',
        'back'               => 'Indietro',
        'back_to_list'       => 'Torna all\'elenco',
        'show_more'          => 'Mostra tutto',
        'show_less'          => 'Mostra meno',
        'categories' => [
            'new_category'      => 'Crea categoria',
            'list_documents'    => 'Documenti',
            'save_category'     => 'Salva',
            'edit_category'     => 'Modifica',
            'delete_category'   => 'Elimina',
            'back_to_documents' => 'Torna ai documenti',
        ],
    ],

    /* ------------------------------------------------------------------
     | Default Categories
     | ------------------------------------------------------------------ */
    'categories' => [
        'bilanci'   => 'Bilanci',
        'verbali'   => 'Verbali',
        'avvisi'    => 'Avvisi',
        'contratti' => 'Contratti',
    ],

    /* ------------------------------------------------------------------
     | User Dashboard (Frontend)
     | ------------------------------------------------------------------ */
    'user' => [
        'latest_documents_title'       => 'Ultimi documenti caricati',
        'latest_documents_description' => 'Elenco degli ultimi documenti in archivio.',
        'pdf_only'                     => 'Sono ammessi solo file PDF.',
        'selected_file'                => 'File selezionato',
        'private_document_label'       => 'Crea documento privato',
        'private_document_title'       => 'Crea documento privato',
        'private_document_description' => 'Quando questa opzione è selezionata, il documento sarà privato e visibile solo agli amministratori.',
    ],

    /* ------------------------------------------------------------------
     | User Document List
     | ------------------------------------------------------------------ */
    'user_list' => [
        'category_title'             => 'Documenti: :category',
        'category_description'       => 'Gestione dei documenti digitali relativi a questa categoria del condominio.',
        'search_placeholder'         => 'Cerca per titolo...',
        'loading'                    => 'Aggiornamento...',
        'load_error'                 => 'Errore di caricamento.',
        'try_again'                  => 'Riprova',
        'no_results_title'           => 'Nessun risultato trovato',
        'no_results_description'     => 'Prova a modificare i termini di ricerca.',
        'empty_category_title'       => 'Categoria vuota',
        'empty_category_description' => 'Nessun documento è stato ancora caricato in questa categoria.',
        'clear_search'               => 'Cancella ricerca',
        'upload_document'            => 'Carica documento',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Documenti',
        'new'  => 'Nuovo documento',
        'edit' => 'Modifica documento',
        'view' => 'Dettaglio documento',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'storage_title'             => 'Archivio Centralizzato',
        'storage_desc'              => 'Conserva fatture, contratti e verbali in un unico spazio cloud sempre accessibile.',
        'organization_title'        => 'Organizzazione Veloce',
        'organization_desc'         => 'Usa cartelle e tag per ritrovare immediatamente i documenti importanti durante le assemblee.',
        'privacy_title'             => 'Privacy e Permessi',
        'privacy_desc'              => 'Gestisci chi può visualizzare i documenti impostando livelli di visibilità pubblici o privati.',
        'upload_title'              => 'Caricamento',
        'upload_desc'               => 'Allega il file e definisci nome e descrizione per identificarlo rapidamente nell\'archivio.',
        'category_title'            => 'Classificazione',
        'category_desc'             => 'Assegna una categoria organizzativa e stabilisci il livello di visibilità del documento.',
        'audience_title'            => 'Destinatari',
        'audience_desc'             => 'Associa il file a specifici condomini e residenti per renderlo visibile solo agli interessati.',
        'categories_org_title'      => 'Organizzazione',
        'categories_org_desc'       => 'Usa le categorie per creare faldoni digitali (es. "Fatture", "Verbali", "Contratti").',
        'categories_assoc_title'    => 'Associazione',
        'categories_assoc_desc'     => 'Ogni documento che carichi in archivio potrà essere assegnato a una di queste categorie.',
        'categories_search_title'   => 'Ricerca Veloce',
        'categories_search_desc'    => 'Filtrare l\'archivio per categoria ti permette di trovare istantaneamente i file durante le assemblee.',
    ]
];
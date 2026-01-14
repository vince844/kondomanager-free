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
    'success_update_document'        => 'Il documento è stato aggiornato con successo',
    'error_update_document'          => 'Si è verificato un errore durante l\'aggiornamento del documento.',
    'error_delete_document'          => 'Si è verificato un errore durante l\'eliminazione del documento.',
    'error_downloading_document'     => 'Si è verificato un errore durante il download del documento.',
    'success_approve_document'       => 'Il documento è stato approvato con successo.',
    'error_approve_document'         => 'Si è verificato un errore durante l\'approvazione del documento',
    'error_notify_new_document'      => 'Il documento è stato creato, ma si è verificato un errore nell\'invio della notifica.',
    'error_notify_approved_document' => 'Il documento è stato approvato, ma si è verificato un errore nell\'invio della notifica.',
    'category_has_documents'         => 'Questa categoria contiene dei documenti. Spostali o eliminali prima di eliminare la categoria.',
    'success_delete_category'        => 'La categoria documenti è stata eliminata con successo.',
    'error_delete_category'          => 'Si è verificato un errore durante l\'eliminazione della categoria documento.',
    'success_create_category'        => 'La categoria documenti è stata creata con successo.',
    'error_create_category'          => 'Si è verificato un errore durante la creazione della categoria documento.',
    'success_update_category'        => 'La categoria documenti è stata aggiornata con successo',
    'error_update_category'          => 'Si è verificato un errore durante l\'aggiornamento della categoria documenti.',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
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
        'clear_all_filters' => 'Resetta tutti i filtri',
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
        'document_supported_types'      => 'Sono ammessi solo i formati PDF, JPEG, PNG.',
        'max_document_size'             => 'Il file non può superare i 20MB',
        'categories' => [
            'delete_category_title'       => 'Sei sicuro di voler eliminare questa categoria?',
            'delete_category_description' => 'Questa azione non è reversibile. Eliminerà la categoria e tutti i documenti ad essa associati.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'total_storage_bytes'  => 'Spazio totale utilizzato',
        'total_documents'      => 'Documenti totali',
        'uploaded_this_month'  => 'Caricati questo mese',
        'average_size_bytes'   => 'Dimensione media',
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
        'new_document'       => 'Crea',
        'list_categories'    => 'Categorie',
        'edit_document'      => 'Modifica',
        'delete_document'    => 'Elimina',
        'save_document'      => 'Salva',
        'list_documents'     => 'Elenco',
        'cancel'             => 'Annulla',
        'show_more'          => 'Mostra tutto',
        'show_less'          => 'Mostra meno',
        'categories' => [
            'new_category'    => 'Crea',
            'list_documents'  => 'Documenti',
            'save_category'   => 'Salva',
            'edit_category'   => 'Modifica',
            'delete_category' => 'Elimina',
        ],
    ],
];
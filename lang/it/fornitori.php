<?php

return [

    'success_create_fornitore'           => "Il nuovo fornitore è stato creato con successo.",
    'error_create_fornitore'             => "Si è verificato un errore durante la creazione del nuovo fornitore.",
    'success_update_fornitore'           => "Il fornitore è stato aggiornato con successo.",
    'error_update_fornitore'             => "Si è verificato un errore durante l'aggiornamento del fornitore.",
    'success_delete_fornitore'           => "Il fornitore è stato eliminato con successo.",     
    'error_delete_fornitore'             => "Si è verificato un errore durante l'eliminazione del fornitore.",
    'success_attach_anagrafica'          => "L'anagrafica è stata associata con successo al fornitore.",
    'error_attach_anagrafica'            => "Si è verificato un errore durante l'associazione dell'anagrafica al fornitore.",
    'success_detach_anagrafica'          => "L'anagrafica è stata dissociata con successo dal fornitore.",
    'error_detach_anagrafica'            => "Si è verificato un errore durante la dissociazione dell'anagrafica dal fornitore.",
    'error_delete_has_invoices'          => "Impossibile eliminare: il fornitore ha fatture registrate a sistema. Per non compromettere la contabilità, ti consigliamo di modificare lo \"Stato\" in \"Cessato\".",

    /* ------------------------------------------------------------------
     | Intestazioni, Titoli e Descrizioni
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Elenco fornitori",
        'list_fornitori_title'          => "Elenco fornitori",
        'list_fornitori_description'    => "Gestisci l'anagrafica dei fornitori e dei professionisti collegati ai tuoi condomini.",
        'referents_list_title'          => "Referenti fornitore",
        'documents_list_title'          => "Documenti fornitore",
        'contacts_new_title'            => "Associa referente",
        'documents_new_title'           => "Nuovo documento fornitore",
        'documents_edit_title'          => "Modifica documento fornitore",
        'new_fornitore_head'            => "Crea fornitore",
        'new_fornitore_title'           => "Crea nuovo fornitore",
        'new_fornitore_description'     => "Inserisci i dati per registrare una nuova ditta o un professionista.",
        'edit_fornitore_head'           => "Modifica fornitore",
        'edit_fornitore_title'          => "Modifica fornitore",
        'edit_fornitore_description'    => "Aggiorna i dati anagrafici e fiscali del fornitore per mantenere la contabilità allineata.",
    ],

    /* ------------------------------------------------------------------
     | Tabella
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Ragione Sociale',
        'address'        => 'Indirizzo',
        'contacts'       => 'Contatti',
        'type'           => 'Tipologia',
        'actions'        => 'Azioni',
        'title'          => 'Titolo',
        'status'         => 'Stato',
        'role'           => 'Ruolo',
        'login_access'   => 'Accesso portale',
        'click_to_view'  => 'Clicca per visualizzare',
        'filter_by_name' => 'Cerca fornitore...',
        'no_results'     => 'Nessun risultato trovato',
        'residents'      => 'Referenti', 
        'residents_desc' => 'Elenco dei contatti e referenti associati a questo fornitore.',
    ],

    /* ------------------------------------------------------------------
     | Etichette e Placeholder
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code'             => 'Codice Fiscale',
        'vat_number'           => 'Partita IVA',
        'document'             => 'Documento',
        'document_name'        => 'Nome documento',
        'document_description' => 'Descrizione',
        'select_document'      => 'Seleziona documento',
        'record'               => 'Anagrafica',
        'role'                 => 'Ruolo',
        'created'              => 'Creato',
        'file_status'          => 'Stato file',
        'present'              => 'Presente',
        'missing'              => 'Mancante',
    ],

    'placeholder' => [
        'no_address'            => 'Indirizzo non disponibile',
        'document_name'         => 'Inserisci il nome del documento',
        'document_description'  => 'Inserisci la descrizione del documento',
        'publish_status'        => 'Seleziona visibilità',
        'record'                => 'Seleziona anagrafica',
        'role'                  => 'Seleziona ruolo',
    ],

    /* ------------------------------------------------------------------
     | Azioni
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'Crea fornitore',
        'edit_fornitore'  => 'Modifica',
        'delete_fornitore'=> 'Elimina',
        'detach_referent' => 'Dissocia',
        'associate'       => 'Associa',
        'save'            => 'Salva',
        'save_changes'    => 'Salva modifiche',
        'edit'            => 'Modifica',
        'delete'          => 'Elimina',
        'remove'          => 'Rimuovi',
        'cancel'          => 'Annulla',
        'replace_file'    => 'Sostituisci file',
        'suppliers'       => 'Fornitori',
        'save_fornitore'  => 'Salva Fornitore',
        'list'            => 'Elenco', 
    ],

    'navigation' => [
        'details'   => 'Dettagli',
        'referents' => 'Referenti',
        'documents' => 'Documenti',
        'suppliers' => 'Fornitori',
    ],

    'dialogs' => [
        'delete_supplier_title'       => 'Sei sicuro di voler eliminare questo fornitore?',
        'delete_supplier_description' => 'Questa azione non è reversibile. Eliminerà il fornitore e tutti i dati ad esso associati.',
        'detach_referent_title'       => 'Sei sicuro di voler dissociare questa anagrafica dal fornitore?',
        'detach_referent_description' => 'Questa azione non è reversibile. L\'anagrafica verrà dissociata e non potrà più visualizzare i dati del fornitore.',
        'delete_document_title'       => 'Sei sicuro di voler eliminare questo documento?',
        'delete_document_description' => 'Questa azione non è reversibile.',
        'drop_document_title'         => 'Trascina qui il file',
        'drop_document_description'   => 'oppure clicca per selezionare un documento',
        'replace_existing'            => 'Questo file sostituirà il documento esistente',
        'only_pdf'                    => 'Sono ammessi solo file PDF.',
        'max_20mb'                    => 'Dimensione massima file: 20MB.',
    ],

    'common' => [
        'back'           => 'Indietro',
        'loading'        => 'Caricamento...',
        'reset_filters'  => 'Resetta filtri',
        'selected_count' => ':count selezionati',
    ],

    'roles' => [
        'owner'      => 'Titolare',
        'admin'      => 'Amministrativo',
        'sales'      => 'Commerciale',
        'technician' => 'Tecnico',
        'contact'    => 'Referente',
        'other'      => 'Altro',
    ],

    'sections' => [
        'publish_status'      => 'Stato pubblicazione',
        'publish_status_desc' => 'Scegli se il documento è visibile.',
        'contact_assoc_title' => 'Associazione referente',
        'contact_assoc_desc'  => 'Associa un\'anagrafica esistente a questo fornitore.',
        'info'                => 'Informazioni',
    ],

    'states' => [
        'active'             => 'Attivo',
        'inactive'           => 'Non attivo',
        'suspended'          => 'Sospeso',
        'ended'              => 'Cessato',
        'approved_tooltip'   => 'Visibile agli utenti',
        'unapproved_tooltip' => 'Visibile solo agli amministratori',
    ],

    'messages' => [
        'delete_document_error' => 'Errore durante l\'eliminazione del documento.',
    ],

    'view' => [
        'title'                  => 'Dettagli fornitore',
        'breadcrumb_detail'      => 'Dettaglio fornitore',
        'supplier_fallback'      => 'Fornitore',
        'edit_data'              => 'Modifica dati',
        'phone_landline'         => 'Fisso',
        'phone_mobile'           => 'Cellulare',
        'no_contacts'            => 'Nessun recapito registrato.',
        'iso_active'             => 'Certificazione ISO attiva',
        'iso_inactive'           => 'Nessuna certificazione ISO',
        'not_registered'         => 'Non registrato',
        'default_payment_method' => 'Bonifico',
        'days_abbr'              => 'gg',
        'withholding'            => 'Ritenuta d\'acconto',
        'not_subject_withholding'=> 'Non soggetto a ritenuta d\'acconto.',
        'no_notes'               => 'Nessuna nota aggiuntiva.',
        'guides' => [
            'contacts_title' => 'Recapiti e contatti',
            'contacts_desc'  => 'Visualizza indirizzo, telefoni, email e sito web del fornitore.',
            'treasury_title' => 'Tesoreria e pagamenti',
            'treasury_desc'  => 'Controlla IBAN, modalità di pagamento e ritenute.',
            'company_title'  => 'Dati societari',
            'company_desc'   => 'Verifica iscrizioni e dati di conformità normativa.',
        ],
        'sections' => [
            'contacts' => 'Recapiti e contatti',
            'company'  => 'Dati societari e iscrizioni',
            'treasury' => 'Tesoreria e pagamenti',
            'notes'    => 'Note interne',
        ],
        'labels' => [
            'ateco_code'            => 'Codice ATECO',
            'cciaa_registration'    => 'Iscrizione CCIAA',
            'registration_date'     => 'Data iscrizione',
            'professional_register' => 'Albo / Ordine',
            'share_capital'         => 'Capitale sociale',
            'primary_iban'          => 'IBAN principale',
            'method'                => 'Metodo',
            'deadline'              => 'Scadenza',
            'rate'                  => 'Aliquota',
            'taxable'               => 'Impon.',
            'tax_code_short'        => 'Tributo',
        ],
    ],

    /* ------------------------------------------------------------------
     | Guide (PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'             => 'Anagrafica Fornitori',
        'portfolio_desc'              => 'Accedi rapidamente ai dati fiscali e di contatto di tutte le ditte e professionisti registrati.',
        'compliance_title'            => 'Dati Fiscali',
        'compliance_desc'             => 'Verifica Partite IVA e Codici Fiscali per una corretta fatturazione elettronica.',
        'management_title'            => 'Gestione Rapida',
        'management_desc'             => 'Aggiungi nuovi fornitori o aggiorna i recapiti per migliorare la comunicazione operativa.',
        'new_fornitore_guide_title'   => 'Inserimento Dati', 
        'new_fornitore_guide_desc'    => 'Assicurati di inserire correttamente la P.IVA per abilitare l\'invio delle ritenute d\'acconto.', 
        'edit_status_title'           => 'Ciclo di Vita',
        'edit_status_desc'            => 'Usa lo stato "Cessato" o "Sospeso" invece di eliminare la ditta, per mantenere intatti i bilanci passati.',
        'edit_treasury_title'         => 'Coordinate Bancarie',
        'edit_treasury_desc'          => 'Tieni aggiornato l\'IBAN principale per garantire la precisione dei flussi di cassa e dei bonifici.',
        'edit_compliance_title'       => 'Ritenute e F24',
        'edit_compliance_desc'        => 'Aggiorna le aliquote fiscali per permettere al sistema di calcolare in automatico i netti da pagare.',
    ],
    
];

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
        'category'                => 'Categoria',
        'name'           => 'Ragione Sociale',
        'address'        => 'Indirizzo',
        'contacts'       => 'Contatti',
        'type'           => 'Tipologia',
        'actions'        => 'Azioni',
        'click_to_view'  => 'Clicca per visualizzare',
        'filter_by_name' => 'Cerca fornitore...',
        'residents'      => 'Referenti', 
        'representatives_title' => 'Rappresentanti del fornitore',
        'representatives_desc'  => 'Le persone che rispondono per questa ditta. Apri una scheda per i suoi recapiti.',
        'residents_desc' => 'Elenco dei contatti e referenti associati a questo fornitore.',
    ],

    /* ------------------------------------------------------------------
     | Etichette e Placeholder
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code' => 'Codice Fiscale',
        'vat_number' => 'Partita IVA',
    ],

    'placeholder' => [
        'no_address' => 'Indirizzo non disponibile',
    ],

    /* ------------------------------------------------------------------
     | Azioni
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'Crea fornitore',
        'edit_fornitore'  => 'Modifica',
        'delete_fornitore'=> 'Elimina',
        'save_fornitore'  => 'Salva Fornitore',
        'list'            => 'Elenco', 
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
    

    /* ------------------------------------------------------------------
     | Categorie di fornitore (1.11.0-beta.9)
     |
     | Fino a questa beta erano nove righe scritte da un seeder e non
     | modificabili da nessuna schermata.
     | ------------------------------------------------------------------ */
    'categorie' => [
        'success_create'      => "La categoria è stata creata con successo.",
        'error_create'        => "Si è verificato un errore durante la creazione della categoria.",
        'success_update'      => "La categoria è stata aggiornata con successo.",
        'error_update'        => "Si è verificato un errore durante l'aggiornamento della categoria.",
        'success_delete'      => "La categoria è stata eliminata con successo.",
        'error_delete'        => "Si è verificato un errore durante l'eliminazione della categoria.",

        // Il rifiuto dice **quanti** fornitori la usano: senza il numero, l'amministratore non sa se
        // deve spostarne uno o quaranta prima di riprovare.
        'in_uso'              => "{1} La categoria «:nome» non è stata eliminata: c'è un fornitore che la usa. Cambia la sua categoria e riprova.|[2,*] La categoria «:nome» non è stata eliminata: ci sono :quanti fornitori che la usano. Cambia la loro categoria e riprova.",

        'head'                => "Categorie fornitore",
        'title'               => "Categorie dei fornitori",
        'description'         => "Crea, rinomina ed elimina le categorie con cui classifichi ditte e professionisti.",
        'back'                => "Torna ai fornitori",

        'new'                 => "Nuova categoria",
        'new_title'           => "Crea nuova categoria",
        'new_description'     => "Aggiungi una categoria per classificare ditte e professionisti.",
        'edit_title'          => "Modifica categoria: :categoria",
        'edit_description'    => "Qui puoi cambiare il nome e la descrizione della categoria.",

        'name'                => "Nome",
        'name_placeholder'    => "Per esempio: vetraio",
        'description_label'   => "Descrizione",
        'description_hint'    => "Facoltativa: serve solo a ricordare cosa ci va dentro.",
        'description_placeholder' => "Che tipo di fornitori raccoglie questa categoria",
        'used_by'             => "Fornitori",
        'suppliers_title'     => "Fornitori di questa categoria",
        'suppliers_desc'      => "Chi è classificato come «:categoria». Apri una scheda per cambiargli categoria.",

        'actions'             => "Azioni",
        'edit'                => "Modifica",
        'delete'              => "Elimina",
        'blocked_title'       => "Questa categoria non si può eliminare",
        'blocked_intro'       => "{1} C'è un fornitore classificato come «:categoria». Finché è così, eliminarla lo lascerebbe senza categoria.|[2,*] Ci sono :count fornitori classificati come «:categoria». Finché è così, eliminarla li lascerebbe senza categoria.",
        'blocked_how'         => "Apri una scheda per cambiargli categoria: quando non la usa più nessuno, l'eliminazione funziona.",
        'blocked_close'       => "Ho capito",
        'delete_title'        => "Eliminare la categoria?",
        'delete_description'  => "I fornitori che la usano perderebbero la categoria, quindi l'eliminazione riesce solo se non la sta usando nessuno.",
        'save'                => "Salva",
        'cancel'              => "Annulla",

        'filtro_non_valido'   => "Il filtro per categoria non è più valido: quella categoria non esiste più. Ecco l'elenco completo.",
        'clear_filters'       => "Azzera i filtri",
        'filter'              => "Filtra per nome...",
        'no_results'          => "Nessuna categoria trovata.",
        'empty'               => "Non c'è ancora nessuna categoria.",

        // Il pulsante «+» accanto alla tendina, nel modulo del fornitore.
        'quick_add'           => "Crea una nuova categoria",
        'quick_add_title'     => "Nuova categoria di fornitore",
        'quick_add_description' => "La crei qui e resta selezionata: non perdi quello che hai già scritto nella scheda.",
        'quick_created'       => "Categoria creata e selezionata.",
        'manage'              => "Gestisci le categorie",

        'guides' => [
            'own_title'       => "Categorie tue",
            'own_desc'        => "Le categorie iniziali sono un punto di partenza: aggiungi i mestieri che servono ai tuoi condomini e togli quelli che non usi.",
            'use_title'       => "A cosa servono",
            'use_desc'        => "Classificano ditte e professionisti nell'elenco fornitori e permettono di ritrovarli per tipo di lavoro.",
            'safe_title'      => "Eliminazione protetta",
            'safe_desc'       => "Una categoria usata da almeno un fornitore non si elimina: prima va cambiata la categoria a quei fornitori.",
        ],
    ],

];
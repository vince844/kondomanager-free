<?php

return [

    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_building' => "Il nuovo condominio è stato creato con successo.",
    'error_create_building'   => "Si è verificato un errore durante la creazione del condominio.",
    'success_edit_building'   => "Il condominio è stato modificato con successo.",
    'error_edit_building'     => "Si è verificato un errore durante la modifica del condominio.",
    'success_delete_building' => "Il condominio è stato eliminato con successo.",
    'error_delete_building'   => "Si è verificato un errore durante l'eliminazione del condominio.",

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_buildings_head'           => "Elenco condomini",
        'list_buildings_title'          => "Elenco condomini",
        'list_buildings_description'    => "Di seguito la tabella con l'elenco di tutti i profili dei condomini registrati.",
        'new_building_head'             => "Crea condominio",
        'new_building_title'            => "Crea condominio",
        'new_building_description'      => "Compila il seguente modulo per la creazione di un nuovo condominio.",
        'edit_building_head'            => "Modifica condominio",
        'edit_building_title'           => "Modifica condominio",
        'edit_building_description'     => "Compila il seguente modulo per aggiornare o modificare i dati del condominio.",
    ],

    /* ------------------------------------------------------------------
     | Sezioni del Modulo (Card)
     | ------------------------------------------------------------------ */
    'cards' => [
        'info_title'            => "Informazioni principali",
        'info_desc'             => "Dati identificativi essenziali del condominio.",
        'location_title'        => "Ubicazione",
        'location_desc'         => "Indirizzo di riferimento dello stabile.",
        'registry_title'        => "Dati strutturali e catastali",
        'registry_desc'         => "Informazioni sul fabbricato ed estremi di registrazione al catasto.",
        'notes_helper'          => "Le note inserite saranno visibili solo allo staff dello studio amministrativo.",
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Denominazione',
        'address'        => 'Indirizzo',
        'filter_by_name' => 'Filtra per nome...',
        'actions'        => 'Azioni',
        'residents'      => 'Anagrafiche',
        'residents_desc' => 'Consulta rapidamente l\'elenco completo delle persone associate a questo condominio.',
        'total'          => '{1} 1 in totale|[2,*] :count in totale',
        'click_to_manage'=> 'Clicca per gestire',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'               => 'Denominazione',
        'address'            => 'Indirizzo e civico',
        'city'               => 'Comune',
        'province'           => 'Prov.',
        'zip_code'           => 'CAP',
        'tax_code'           => 'Codice Fiscale',
        'email'              => 'Indirizzo email',
        'notes'              => 'Note aggiuntive interne',
        'build_year'         => 'Anno di costruzione',
        'acquisition_year'   => 'Anno di acquisizione',
        'floors'             => 'Numero di piani',
        'municipality'       => 'Comune catasto',
        'municipality_code'  => 'Codice catasto',
        'section'            => 'Sezione',
        'sheet'              => 'Foglio',
        'parcel'             => 'Particella',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'               => 'Es. Condominio Le Vele',
        'address'            => 'Via, Piazza, Corso...',
        'city'               => 'Es. Roma, Milano',
        'province'           => 'RM',
        'zip_code'           => '00000',
        'tax_code'           => 'Codice fiscale',
        'email'              => 'email@condominio.it',
        'notes'              => 'Inserisci una nota visibile solo agli amministratori...',
        'build_year'         => 'Es. 1980',
        'acquisition_year'   => 'Es. 2024',
        'floors'             => 'Es. 5',
        'municipality'       => 'Comune catasto',
        'municipality_code'  => 'Codice catasto',
        'section'            => 'Sezione',
        'sheet'              => 'Foglio',
        'parcel'             => 'Particella',
        'no_address'         => 'Indirizzo non presente',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => "Nessun condominio ancora creato",
        'close_list'           => "Chiudi Elenco",
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'   => 'Crea condominio',
        'edit_building'  => 'Modifica',
        'delete_building'=> 'Elimina',
        'save_building'  => 'Salva',
        'update_building'=> 'Aggiorna', 
        'list_buildings' => 'Elenco',
        'cancel'         => 'Annulla',
    ],

    /* ------------------------------------------------------------------
     | Page Guides (Cards - PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'        => 'Portafoglio Fabbricati',
        'portfolio_desc'         => 'Panoramica di tutti i condomini gestiti. Da qui hai il controllo totale sui tuoi mandati.',
        'quick_access_title'     => 'Accesso Rapido',
        'quick_access_desc'      => 'Clicca su un condominio per entrare nel suo gestionale dedicato (fatture, rate, bilanci).',
        'new_acquisitions_title' => 'Nuove Acquisizioni',
        'new_acquisitions_desc'  => 'Aggiungi nuovi stabili al gestionale e inizia a configurarne anagrafica e conti correnti.',
        
        // Guide per la pagina CREATE (Nuovo Condominio)
        'create_info_title'      => 'Dati Generali',
        'create_info_desc'       => 'Inserisci il nome, i contatti e le informazioni principali del nuovo stabile.',
        'create_registry_title'  => 'Dati Catastali',
        'create_registry_desc'   => 'Compila i dati catastali, indispensabili per gli adempimenti fiscali e le pratiche edilizie.',
        'create_notes_title'     => 'Note Interne',
        'create_notes_desc'      => 'Aggiungi appunti, codici di accesso o istruzioni visibili solo allo studio.',

        // Guide per la pagina EDIT (Modifica Condominio)
        'edit_info_title'        => 'Dati Generali',
        'edit_info_desc'         => 'Modifica il nome, i contatti e le informazioni principali del condominio.',
        'edit_registry_title'    => 'Dati Catastali',
        'edit_registry_desc'     => 'Aggiorna i dati catastali per mantenere la contabilità allineata alle normative.',
        'edit_notes_title'       => 'Note Interne',
        'edit_notes_desc'        => 'Aggiorna gli appunti, i codici di accesso o le istruzioni riservate allo studio.',
    ],
];
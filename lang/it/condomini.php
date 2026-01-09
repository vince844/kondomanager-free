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
        'list_buildings_description'    => "Di seguito la tabella con l'elenco di tutti i profili dei condomini registrati",
        'new_building_head'             => "Crea condominio",
        'new_building_title'            => "Crea condominio",
        'new_building_description'      => "Compila il seguente modulo per la creazione di un nuovo condominio",
        'edit_building_head'            => "Modifica condominio",
        'edit_building_title'           => "Modifica condominio",
        'edit_building_description'     => "Compila il seguente modulo per aggiornare o modificare i dati del condominio",
        'building_info_heading'         => "Dati anagrafici",
        'building_info_description'     => "Di seguito è possibile specificare i dati anagrafici del condominio",
        'building_registry_heading'     => "Dati catastali",
        'building_registry_description' => "Di seguito è possibile specificare i dati catastali del condominio",
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Denominazione',
        'address'        => 'Indirizzo',
        'filter_by_name' => 'Filtra per nome...',
        'actions'        => 'Azioni',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'               => 'Denominazione',
        'address'            => 'Indirizzo',
        'tax_code'           => 'Codice fiscale',
        'email'              => 'Indirizzo email',
        'notes'              => 'Note',
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
        'name'               => 'Inserisci la denominazione del condominio',
        'address'            => "Inserisci l'indirizzo del condominio",
        'tax_code'           => 'Codice fiscale del condominio',
        'email'              => 'Indirizzo email del condominio',
        'notes'              => 'Inserisci una nota aggiuntiva qui',
        'municipality'       => 'Comune catasto',
        'municipality_code'  => 'Codice catasto',
        'section'            => 'Sezione catasto',
        'sheet'              => 'Foglio catasto',
        'parcel'             => 'Particella catasto',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => "Nessun condominio ancora creato",
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'   => 'Crea',
        'edit_building'  => 'Modifica',
        'delete_building'=> 'Elimina',
        'save_building'  => 'Salva',
        'list_buildings' => 'Elenco',
    ],
];
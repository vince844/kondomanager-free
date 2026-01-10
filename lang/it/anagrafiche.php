<?php

return [
    'success_create_anagrafica' => 'La nuova anagrafica è stata creata con successo.',
    'error_create_anagrafica'   => 'Si è verificato un errore durante la creazione della nuova anagrafica.',
    'success_update_anagrafica' => 'L\'anagrafica è stata aggiornata con successo.',
    'error_update_anagrafica'   => 'Si è verificato un errore durante l\'aggiornamento dell\'anagrafica.',
    'success_delete_anagrafica' => 'L\'anagrafica è stata eliminata con successo.',
    'error_delete_anagrafica'   => 'Si è verificato un errore durante l\'eliminazione dell\'anagrafica.',
    'anagrafica_has_building'   => 'L\'anagrafica non può essere eliminata perché è associata a dei condomini',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_residents_head'         => 'Elenco anagrafiche',
        'list_residents_title'        => 'Elenco anagrafiche in rubrica',
        'list_residents_description'  => 'Di seguito la tabella con l\'elenco di tutte le anagrafiche registrate in rubrica',
        'new_resident_head'           => 'Crea anagrafica',
        'new_resident_title'          => 'Crea nuova anagrafica',
        'new_resident_description'    => 'Compila il seguente modulo per la creazione di una nuova anagrafica',
        'edit_resident_head'          => 'Modifica anagrafica',
        'edit_resident_title'         => 'Modifica anagrafica',
        'edit_resident_description'   => 'Compila il seguente modulo per modificare l\'anagrafica registrata in rubrica',
        'resident_info_heading'       => 'Dati anagrafici',
        'resident_info_description'   => 'Di seguito è possibile specificare i dati anagrafici generici dell\'anagrafica',
        'resident_fiscal_heading'     => 'Dati fiscali',
        'resident_fiscal_description' => 'Di seguito è possibile specificare i dati fiscali dell\'anagrafica',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'      => 'Nome e cognome',
        'address'   => 'Indirizzo',
        'buildings' => 'Condomini',
        'actions'   => 'Azioni',
        'filter'    => 'Filtra per nome...',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_resident'    => 'Crea',
        'edit_resident'   => 'Modifica',
        'delete_resident' => 'Elimina',
        'save_resident'   => 'Salva',
        'list_residents'  => 'Elenco',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'              => 'Nome e cognome',
        'address'           => 'Indirizzo di residenza',
        'telephone'         => 'Telefono',
        'mobile'            => 'Cellulare',
        'primary_email'     => 'Indirizzo email primario',
        'secondary_email'   => 'Indirizzo email secondario',
        'pec'               => 'Indirizzo email PEC',
        'document_type'     => 'Tipologia documento',
        'document_number'   => 'Numero documento',
        'document_expiry'   => 'Scadenza documento',
        'fiscal_code'       => 'Codice fiscale',
        'birth_place'       => 'Luogo di nascita',
        'birthday'          => 'Data di nascita',
        'buildings'         => 'Condomini',
        'notes'             => 'Note aggiuntive',
        'passport'          => 'Passaporto',
        'id_card'           => 'Carta d\'identità',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'              => 'Inserisci nome e cognome',
        'address'           => 'Inserisci l\'indirizzo di residenza',
        'telephone'         => 'Inserisci numero di telefono',
        'mobile'            => 'Inserisci numero di cellulare',
        'primary_email'     => 'Inserisci indirizzo email primario',
        'secondary_email'   => 'Inserisci indirizzo email secondario',
        'pec'               => 'Inserisci indirizzo email pec',
        'document_type'     => 'Seleziona tipologia documento',
        'document_number'   => 'Inserisci numero documento',
        'document_expiry'   => 'Data scadenza documento',
        'fiscal_code'       => 'Inserisci codice fiscale',
        'birth_place'       => 'Inserisci luogo di nascita',
        'birthday'          => 'Inserisci data di nascita',
        'buildings'         => 'Seleziona condomini',
        'notes'             => 'Inserisci una nota aggiuntiva',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'buildings_header'      => 'Seleziona e associa condomini',
        'buildings_description' => 'Puoi selezionare uno o più di un condominio da associare all\'anagrafica, questo permetterà all\'anagrafica di visualizzare i dati dei condomini associati ad essa',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_resident_title'       => 'Sei sicuro di volere eliminare questa anagrafica?',
        'delete_resident_description' => 'Questa azione non è reversibile. Eliminerà l\'anagrafica e tutti i dati ad essa associati.',
        'no_residents_created'        => 'Nessuna anagrafica ancora creata',
    ],
];
<?php

return [

    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_building' => "The new building was created successfully.",
    'error_create_building'   => "An error occurred while creating the building.",
    'success_edit_building'   => "The building was updated successfully.",
    'error_edit_building'     => "An error occurred while updating the building.",
    'success_delete_building' => "The building was deleted successfully.",
    'error_delete_building'   => "An error occurred while deleting the building.",

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_buildings_head'           => "Buildings List",
        'list_buildings_title'          => "Buildings",
        'list_buildings_description'    => "Below is the table listing all registered building profiles.",
        'new_building_head'             => "Create Building",
        'new_building_title'            => "New Building",
        'new_building_description'      => "Fill out the form below to register a new building.",
        'edit_building_head'            => "Edit Building",
        'edit_building_title'           => "Edit Building",
        'edit_building_description'     => "Update the building's demographic and cadastral data.",
    ],

    /* ------------------------------------------------------------------
     | Sezioni del Modulo (Card)
     | ------------------------------------------------------------------ */
    'cards' => [
        'info_title'            => "Main Information",
        'info_desc'             => "Essential identification data of the building.",
        'location_title'        => "Location",
        'location_desc'         => "Reference address of the building.",
        'registry_title'        => "Structural and Cadastral Data",
        'registry_desc'         => "Information about the building and land registry details.",
        'notes_helper'          => "The notes entered here will only be visible to the management office staff.",
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Name',
        'address'        => 'Address',
        'filter_by_name' => 'Filter by name...',
        'actions'        => 'Actions',
        'residents'      => 'Residents',
        'residents_desc' => 'Quickly view the complete list of people associated with this building.',
        'total'          => 'Totals',
        'click_to_manage'=> 'Click to manage',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'               => 'Name',
        'address'            => 'Address and street number',
        'city'               => 'City',
        'province'           => 'Prov.',
        'zip_code'           => 'ZIP Code',
        'tax_code'           => 'Tax Code',
        'email'              => 'Email address',
        'notes'              => 'Internal additional notes',
        'build_year'         => 'Year built',
        'acquisition_year'   => 'Acquisition year',
        'floors'             => 'Number of floors',
        'municipality'       => 'Cadastral Municipality',
        'municipality_code'  => 'Cadastral Code',
        'section'            => 'Section',
        'sheet'              => 'Sheet',
        'parcel'             => 'Parcel',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'               => 'E.g. Sunrise Building',
        'address'            => 'Street, Avenue, Square...',
        'city'               => 'E.g. London, New York',
        'province'           => 'NY',
        'zip_code'           => '00000',
        'tax_code'           => 'Tax Code',
        'email'              => 'email@building.com',
        'notes'              => 'Enter a note visible only to administrators...',
        'build_year'         => 'E.g. 1980',
        'acquisition_year'   => 'E.g. 2024',
        'floors'             => 'E.g. 5',
        'municipality'       => 'Cadastral municipality',
        'municipality_code'  => 'Cadastral code',
        'section'            => 'Section',
        'sheet'              => 'Sheet',
        'parcel'             => 'Parcel',
        'no_address'         => 'Address not available',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => "No buildings created yet",
        'close_list'           => "Close List",
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'   => 'Create',
        'edit_building'  => 'Edit',
        'delete_building'=> 'Delete',
        'save_building'  => 'Save',
        'update_building'=> 'Update', 
        'list_buildings' => 'List',
        'cancel'         => 'Cancel',
    ],

    /* ------------------------------------------------------------------
     | Page Guides (Cards - PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'        => 'Building Portfolio',
        'portfolio_desc'         => 'Overview of all managed buildings. From here you have total control over your mandates.',
        'quick_access_title'     => 'Quick Access',
        'quick_access_desc'      => 'Click on a building to enter its dedicated management area (invoices, installments, budgets).',
        'new_acquisitions_title' => 'New Acquisitions',
        'new_acquisitions_desc'  => 'Add new buildings to the system and start configuring contacts and bank accounts.',
        
        // Guide per la pagina CREATE (Nuovo Condominio)
        'create_info_title'      => 'General Data',
        'create_info_desc'       => 'Enter the name, contacts, and main information of the new building.',
        'create_registry_title'  => 'Cadastral Data',
        'create_registry_desc'   => 'Fill in the cadastral data, essential for tax compliance and building practices.',
        'create_notes_title'     => 'Internal Notes',
        'create_notes_desc'      => 'Add notes, access codes, or instructions visible only to the office.',

        // Guide per la pagina EDIT (Modifica Condominio)
        'edit_info_title'        => 'General Data',
        'edit_info_desc'         => 'Edit the name, contacts, and main information of the building.',
        'edit_registry_title'    => 'Cadastral Data',
        'edit_registry_desc'     => 'Update the cadastral data to keep the accounting aligned with regulations.',
        'edit_notes_title'       => 'Internal Notes',
        'edit_notes_desc'        => 'Update notes, access codes, or instructions reserved for the office.',
    ],
];
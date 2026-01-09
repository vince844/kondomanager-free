<?php

return [

    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_building' => "The new building has been created successfully.",
    'error_create_building'   => "An error occurred while creating the new building.",
    'success_edit_building'   => "The building has been edited successfully.",
    'error_edit_building'     => "An error occurred while updating the building.",
    'success_delete_building' => "The building has been deleted successfully.",
    'error_delete_building'   => "An error occurred while deleting the building.",

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_buildings_head'           => "List of buildings",
        'list_buildings_title'          => "List of buildings",
        'list_buildings_description'    => "Below is the table with the list of all building profiles already created",
        'new_building_head'             => "Create new building",
        'new_building_title'            => "Create new building",
        'new_building_description'      => "Fill out the form below to create a new building profile",
        'edit_building_head'            => "Edit building",
        'edit_building_title'           => "Edit building",
        'edit_building_description'     => "Fill out the form below to edit the building profile",
        'building_info_heading'         => "Building information",
        'building_info_description'     => "Here you can enter the basic information of the building.",
        'building_registry_heading'     => "Building registry data",
        'building_registry_description' => "Here you can enter the registry data of the building",
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Name',
        'address'        => 'Address',
        'filter_by_name' => 'Filter by name...',
        'actions'        => 'Actions',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'               => 'Name',
        'address'            => 'Address',
        'tax_code'           => 'Tax code',
        'email'              => 'Email address',
        'notes'              => 'Notes',
        'municipality'       => 'Municipality',
        'municipality_code'  => 'Municipality code',
        'section'            => 'Section',
        'sheet'              => 'Sheet',
        'parcel'             => 'Parcel',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'               => 'Enter the building name',
        'address'            => 'Enter the building address',
        'tax_code'           => 'Enter the building tax code',
        'email'              => 'Enter the building email address',
        'notes'              => 'Enter additional note here',
        'municipality'       => 'Municipality',
        'municipality_code'  => 'Municipality code',
        'section'            => 'Section',
        'sheet'              => 'Sheet',
        'parcel'             => 'Parcel',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => "No buildings have been created yet",
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'   => 'Create new',
        'edit_building'  => 'Edit',
        'delete_building'=> 'Delete',
        'save_building'  => 'Save',
        'list_buildings' => 'List',
    ],
];
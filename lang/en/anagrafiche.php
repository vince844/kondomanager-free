<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_anagrafica' => 'The new resident record has been created successfully.',
    'error_create_anagrafica'   => 'An error occurred while creating the new resident record.',
    'success_update_anagrafica' => 'The resident record has been updated successfully.',
    'error_update_anagrafica'   => 'An error occurred while updating the resident record.',
    'success_delete_anagrafica' => 'The resident record has been deleted successfully.',
    'error_delete_anagrafica'   => 'An error occurred while deleting the resident record.',
    'anagrafica_has_building'   => 'The resident record cannot be deleted because it is associated with one or more buildings',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_residents_head'         => 'Residents list',
        'list_residents_title'        => 'Residents in address book',
        'list_residents_description'  => 'Below is the table listing all resident records registered in the address book',
        'new_resident_head'           => 'Create resident record',
        'new_resident_title'          => 'Create new resident record',
        'new_resident_description'    => 'Fill out the following form to create a new resident record',
        'edit_resident_head'          => 'Edit resident record',
        'edit_resident_title'         => 'Edit resident record',
        'edit_resident_description'   => 'Fill out the following form to edit the resident record registered in the address book',
        'resident_info_heading'       => 'Personal information',
        'resident_info_description'   => 'Below you can enter the general personal details of the resident',
        'resident_fiscal_heading'     => 'Tax information',
        'resident_fiscal_description' => 'Below you can enter the tax details of the resident',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'      => 'Full name',
        'address'   => 'Address',
        'buildings' => 'Buildings',
        'actions'   => 'Actions',
        'filter'    => 'Filter by name...',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_resident'    => 'Create',
        'edit_resident'   => 'Edit',
        'delete_resident' => 'Delete',
        'save_resident'   => 'Save',
        'list_residents'  => 'List',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'              => 'Full name',
        'address'           => 'Residential address',
        'telephone'         => 'Landline phone',
        'mobile'            => 'Mobile phone',
        'primary_email'     => 'Primary email address',
        'secondary_email'   => 'Secondary email address',
        'pec'               => 'Certified email (PEC) address',
        'document_type'     => 'Document type',
        'document_number'   => 'Document number',
        'document_expiry'   => 'Document expiry date',
        'fiscal_code'       => 'Tax code',
        'birth_place'       => 'Place of birth',
        'birthday'          => 'Date of birth',
        'buildings'         => 'Buildings',
        'notes'             => 'Additional notes',
        'passport'          => 'Passport',
        'id_card'           => 'ID card',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'              => 'Enter full name',
        'address'           => 'Enter residential address',
        'telephone'         => 'Enter landline phone number',
        'mobile'            => 'Enter mobile phone number',
        'primary_email'     => 'Enter primary email address',
        'secondary_email'   => 'Enter secondary email address',
        'pec'               => 'Enter certified email (PEC) address',
        'document_type'     => 'Select document type',
        'document_number'   => 'Enter document number',
        'document_expiry'   => 'Document expiry date',
        'fiscal_code'       => 'Enter tax code',
        'birth_place'       => 'Enter place of birth',
        'birthday'          => 'Enter date of birth',
        'buildings'         => 'Select buildings',
        'notes'             => 'Enter an additional note',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'buildings_header'      => 'Select and associate buildings',
        'buildings_description' => 'You can select one or more buildings to associate with this resident record. This will allow the resident to view data related to the associated buildings.',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_resident_title'       => 'Are you sure you want to delete this resident record?',
        'delete_resident_description' => 'This action is irreversible. It will delete the resident record and all associated data.',
        'no_residents_created'        => 'No resident records created yet',
    ],
];
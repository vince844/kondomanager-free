<?php

return [

    'success_create_fornitore'           => "The new supplier has been created successfully.",
    'error_create_fornitore'             => "An error occurred while creating the new supplier.",
    'success_update_fornitore'           => "The supplier has been updated successfully.",
    'error_update_fornitore'             => "An error occurred while updating the supplier.",
    'success_delete_fornitore'           => "The supplier has been deleted successfully.",     
    'error_delete_fornitore'             => "An error occurred while deleting the supplier.",
    'success_attach_anagrafica'          => "The record has been successfully associated with the supplier.",
    'error_attach_anagrafica'            => "An error occurred while associating the record with the supplier.",
    'success_detach_anagrafica'          => "The record has been successfully detached from the supplier.",
    'error_detach_anagrafica'            => "An error occurred while detaching the record from the supplier.",

    /* ------------------------------------------------------------------
     | Headings, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Suppliers list",
        'list_fornitori_title'          => "Suppliers list",
        'list_fornitori_description'    => "Manage the records of suppliers and professionals linked to your buildings.",
        'new_fornitore_head'            => "Create supplier",
        'new_fornitore_title'           => "Create supplier",
        'new_fornitore_description'     => "Enter data to register a new company or professional.",
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Company Name',
        'address'        => 'Address',
        'contacts'       => 'Contacts',
        'type'           => 'Type',
        'actions'        => 'Actions',
        'click_to_view'  => 'Click to view',
        'filter_by_name' => 'Search supplier...',
        'residents'      => 'Referents',
        'residents_desc' => 'List of contacts and referents associated with this supplier.',
    ],

    /* ------------------------------------------------------------------
     | Labels and Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code' => 'Tax Code',
        'vat_number' => 'VAT Number',
    ],

    'placeholder' => [
        'no_address' => 'Address not available',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'New Supplier',
        'edit_fornitore'  => 'Edit',
        'delete_fornitore'=> 'Delete',
        'save_fornitore'  => 'Save Supplier',
        'list'            => 'List', 
    ],

    /* ------------------------------------------------------------------
     | Guides (PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'             => 'Suppliers Directory',
        'portfolio_desc'              => 'Quickly access tax and contact data for all registered companies and professionals.',
        'compliance_title'            => 'Tax Compliance',
        'compliance_desc'             => 'Verify VAT numbers and Tax codes for correct electronic invoicing.',
        'management_title'            => 'Quick Management',
        'management_desc'             => 'Add new suppliers or update contact details to improve operational communication.',
        'new_fornitore_guide_title'   => 'Data Entry', 
        'new_fornitore_guide_desc'    => 'Ensure you correctly enter the VAT number to enable the sending of withholding taxes.', 
    ],
    
];
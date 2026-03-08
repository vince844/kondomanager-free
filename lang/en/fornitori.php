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
    'error_delete_has_invoices'          => "Unable to delete: the supplier has invoices registered in the system. To avoid compromising the accounting, we recommend changing the \"Status\" to \"Inactive\".",

    /* ------------------------------------------------------------------
     | Headings, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Suppliers list",
        'list_fornitori_title'          => "Suppliers list",
        'list_fornitori_description'    => "Manage the records of suppliers and professionals linked to your buildings.",
        'referents_list_title'          => "Supplier referents",
        'documents_list_title'          => "Supplier documents",
        'contacts_new_title'            => "Associate referent",
        'documents_new_title'           => "New supplier document",
        'documents_edit_title'          => "Edit supplier document",
        'new_fornitore_head'            => "Create supplier",
        'new_fornitore_title'           => "Create supplier",
        'new_fornitore_description'     => "Enter data to register a new company or professional.",
        'edit_fornitore_head'           => "Edit supplier",
        'edit_fornitore_title'          => "Edit supplier",
        'edit_fornitore_description'    => "Update the supplier's master and tax data to keep accounting aligned.",
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
        'title'          => 'Title',
        'status'         => 'Status',
        'role'           => 'Role',
        'login_access'   => 'Portal access',
        'click_to_view'  => 'Click to view',
        'filter_by_name' => 'Search supplier...',
        'no_results'     => 'No results found',
        'residents'      => 'Referents',
        'residents_desc' => 'List of contacts and referents associated with this supplier.',
    ],

    /* ------------------------------------------------------------------
     | Labels and Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code'             => 'Tax Code',
        'vat_number'           => 'VAT Number',
        'document'             => 'Document',
        'document_name'        => 'Document name',
        'document_description' => 'Description',
        'select_document'      => 'Select document',
        'record'               => 'Record',
        'role'                 => 'Role',
        'created'              => 'Created',
        'file_status'          => 'File status',
        'present'              => 'Present',
        'missing'              => 'Missing',
    ],

    'placeholder' => [
        'no_address'            => 'Address not available',
        'document_name'         => 'Enter document name',
        'document_description'  => 'Enter document description',
        'publish_status'        => 'Select visibility',
        'record'                => 'Select record',
        'role'                  => 'Select role',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'New Supplier',
        'edit_fornitore'  => 'Edit',
        'delete_fornitore'=> 'Delete',
        'detach_referent' => 'Detach',
        'associate'       => 'Associate',
        'save'            => 'Save',
        'save_changes'    => 'Save changes',
        'edit'            => 'Edit',
        'delete'          => 'Delete',
        'remove'          => 'Remove',
        'cancel'          => 'Cancel',
        'replace_file'    => 'Replace file',
        'suppliers'       => 'Suppliers',
        'save_fornitore'  => 'Save Supplier',
        'list'            => 'List', 
    ],

    'navigation' => [
        'details'   => 'Details',
        'referents' => 'Referents',
        'documents' => 'Documents',
        'suppliers' => 'Suppliers',
    ],

    'dialogs' => [
        'delete_supplier_title'       => 'Are you sure you want to delete this supplier?',
        'delete_supplier_description' => 'This action cannot be undone. It will remove the supplier and all associated data.',
        'detach_referent_title'       => 'Are you sure you want to detach this referent from the supplier?',
        'detach_referent_description' => 'This action cannot be undone. The referent will be detached and will no longer access supplier data.',
        'delete_document_title'       => 'Are you sure you want to delete this document?',
        'delete_document_description' => 'This action cannot be undone.',
        'drop_document_title'         => 'Drop a file here',
        'drop_document_description'   => 'or click to select a document',
        'replace_existing'            => 'This file will replace the existing document',
        'only_pdf'                    => 'Only PDF files are allowed.',
        'max_20mb'                    => 'Maximum file size: 20MB.',
    ],

    'common' => [
        'back'           => 'Back',
        'loading'        => 'Loading...',
        'reset_filters'  => 'Reset filters',
        'selected_count' => ':count selected',
    ],

    'roles' => [
        'owner'      => 'Owner',
        'admin'      => 'Administrative',
        'sales'      => 'Sales',
        'technician' => 'Technician',
        'contact'    => 'Referent',
        'other'      => 'Other',
    ],

    'sections' => [
        'publish_status'      => 'Publish status',
        'publish_status_desc' => 'Choose whether the document is visible.',
        'contact_assoc_title' => 'Referent association',
        'contact_assoc_desc'  => 'Associate an existing record with this supplier.',
        'info'                => 'Information',
    ],

    'states' => [
        'active'             => 'Active',
        'inactive'           => 'Inactive',
        'suspended'          => 'Suspended',
        'ended'              => 'Ended',
        'approved_tooltip'   => 'Visible to users',
        'unapproved_tooltip' => 'Visible only to admins',
    ],

    'messages' => [
        'delete_document_error' => 'Error while deleting document.',
    ],

    'view' => [
        'title'                => 'Supplier details',
        'breadcrumb_detail'    => 'Supplier details',
        'supplier_fallback'    => 'Supplier',
        'edit_data'            => 'Edit data',
        'phone_landline'       => 'Landline',
        'phone_mobile'         => 'Mobile',
        'no_contacts'          => 'No contact details registered.',
        'iso_active'           => 'ISO certification active',
        'iso_inactive'         => 'No ISO certification',
        'not_registered'       => 'Not registered',
        'default_payment_method' => 'Bank transfer',
        'days_abbr'            => 'days',
        'withholding'          => 'Withholding tax',
        'not_subject_withholding' => 'Not subject to withholding tax.',
        'no_notes'             => 'No additional notes.',
        'guides' => [
            'contacts_title' => 'Contacts and details',
            'contacts_desc'  => 'View address, phones, email and website.',
            'treasury_title' => 'Treasury and payments',
            'treasury_desc'  => 'Check IBAN, payment method and withholding data.',
            'company_title'  => 'Company data',
            'company_desc'   => 'Review registrations and compliance information.',
        ],
        'sections' => [
            'contacts' => 'Contacts',
            'company'  => 'Company data and registrations',
            'treasury' => 'Treasury and payments',
            'notes'    => 'Internal notes',
        ],
        'labels' => [
            'ateco_code'            => 'ATECO code',
            'cciaa_registration'    => 'Chamber of Commerce registration',
            'registration_date'     => 'Registration date',
            'professional_register' => 'Professional register',
            'share_capital'         => 'Share capital',
            'primary_iban'          => 'Primary IBAN',
            'method'                => 'Method',
            'deadline'              => 'Deadline',
            'rate'                  => 'Rate',
            'taxable'               => 'Taxable',
            'tax_code_short'        => 'Tax code',
        ],
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
        'edit_status_title'           => 'Lifecycle Status',
        'edit_status_desc'            => 'Use the "Inactive" or "Suspended" status instead of deleting the company, to keep past accounting records intact.',
        'edit_treasury_title'         => 'Bank Details',
        'edit_treasury_desc'          => 'Keep the main IBAN updated to ensure the accuracy of cash flows and wire transfers.',
        'edit_compliance_title'       => 'Withholding & Taxes',
        'edit_compliance_desc'        => 'Update tax rates to allow the system to automatically calculate net payments.',
    ],
    
];

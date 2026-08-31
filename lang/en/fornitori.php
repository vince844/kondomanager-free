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
        'category'                => 'Category',
        'name'           => 'Company Name',
        'address'        => 'Address',
        'contacts'       => 'Contacts',
        'type'           => 'Type',
        'actions'        => 'Actions',
        'click_to_view'  => 'Click to view',
        'filter_by_name' => 'Search supplier...',
        'residents'      => 'Referents',
        'representatives_title' => 'Supplier representatives',
        'representatives_desc'  => 'The people who answer for this company. Open a record for their contact details.',
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
        'edit_status_title'           => 'Lifecycle Status',
        'edit_status_desc'            => 'Use the "Inactive" or "Suspended" status instead of deleting the company, to keep past accounting records intact.',
        'edit_treasury_title'         => 'Bank Details',
        'edit_treasury_desc'          => 'Keep the main IBAN updated to ensure the accuracy of cash flows and wire transfers.',
        'edit_compliance_title'       => 'Withholding & Taxes',
        'edit_compliance_desc'        => 'Update tax rates to allow the system to automatically calculate net payments.',
    ],
    

    /* ------------------------------------------------------------------
     | Supplier categories (1.11.0-beta.9)
     | ------------------------------------------------------------------ */
    'categorie' => [
        'success_create'      => "The category was created successfully.",
        'error_create'        => "An error occurred while creating the category.",
        'success_update'      => "The category was updated successfully.",
        'error_update'        => "An error occurred while updating the category.",
        'success_delete'      => "The category was deleted successfully.",
        'error_delete'        => "An error occurred while deleting the category.",
        'in_uso'              => "{1} The category «:nome» was not deleted: one supplier is using it. Change its category and try again.|[2,*] The category «:nome» was not deleted: :quanti suppliers are using it. Change their category and try again.",

        'head'                => "Supplier categories",
        'title'               => "Supplier categories",
        'description'         => "Create, rename and delete the categories you use to classify companies and professionals.",
        'back'                => "Back to suppliers",

        'new'                 => "New category",
        'new_title'           => "Create new category",
        'new_description'     => "Add a category to classify companies and professionals.",
        'edit_title'          => "Edit category: :categoria",
        'edit_description'    => "Here you can change the name and the description of the category.",

        'name'                => "Name",
        'name_placeholder'    => "For example: glazier",
        'description_label'   => "Description",
        'description_hint'    => "Optional: it only helps you remember what belongs here.",
        'description_placeholder' => "What kind of suppliers this category collects",
        'used_by'             => "Suppliers",
        'suppliers_title'     => "Suppliers in this category",
        'suppliers_desc'      => "Who is classified as «:categoria». Open a record to change its category.",

        'actions'             => "Actions",
        'edit'                => "Edit",
        'delete'              => "Delete",
        'blocked_title'       => "This category cannot be deleted",
        'blocked_intro'       => "{1} One supplier is classified as «:categoria». While that is the case, deleting it would leave that supplier without a category.|[2,*] :count suppliers are classified as «:categoria». While that is the case, deleting it would leave them without a category.",
        'blocked_how'         => "Open a record to change its category: once nobody uses this one, deletion works.",
        'blocked_close'       => "Got it",
        'delete_title'        => "Delete the category?",
        'delete_description'  => "Suppliers using it would lose their category, so deletion only succeeds when nobody is using it.",
        'save'                => "Save",
        'cancel'              => "Cancel",

        'filtro_non_valido'   => "The category filter is no longer valid: that category does not exist any more. Here is the full list.",
        'clear_filters'       => "Clear filters",
        'filter'              => "Filter by name...",
        'no_results'          => "No category found.",
        'empty'               => "There are no categories yet.",

        'quick_add'           => "Create a new category",
        'quick_add_title'     => "New supplier category",
        'quick_add_description' => "Create it here and it stays selected: you do not lose what you already typed in the form.",
        'quick_created'       => "Category created and selected.",
        'manage'              => "Manage categories",

        'guides' => [
            'own_title'       => "Your own categories",
            'own_desc'        => "The initial categories are a starting point: add the trades your buildings need and remove the ones you never use.",
            'use_title'       => "What they are for",
            'use_desc'        => "They classify companies and professionals in the supplier list and let you find them by type of work.",
            'safe_title'      => "Protected deletion",
            'safe_desc'       => "A category used by at least one supplier cannot be deleted: those suppliers must be moved first.",
        ],
    ],

];
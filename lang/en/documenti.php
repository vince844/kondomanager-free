<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_document'        => 'The new document was created successfully.',
    'error_create_document'          => 'An error occurred while creating the document.',
    'no_file_uploaded'               => 'No file uploaded. Please try again.',
    'file_not_found'                 => 'No file found on the server.',
    'success_delete_document'        => 'The document was deleted successfully.',
    'success_update_document'        => 'The document was updated successfully.',
    'error_update_document'          => 'An error occurred while updating the document.',
    'error_delete_document'          => 'An error occurred while deleting the document.',
    'error_downloading_document'     => 'An error occurred while downloading the document.',
    'success_approve_document'       => 'The document was approved successfully.',
    'error_approve_document'         => 'An error occurred while approving the document.',
    'error_notify_new_document'      => 'The document was created, but an error occurred sending the notification.',
    'error_notify_approved_document' => 'The document was approved, but an error occurred sending the notification.',
    'error_notify_updated_document' => "The document was updated, but an error occurred while sending the notification.",
    'category_has_documents'         => 'The category was not deleted: there are documents using it. Remove it from those documents and try again.',
    'success_delete_category'        => 'The document category was deleted successfully.',
    'error_delete_category'          => 'An error occurred while deleting the document category.',
    'success_create_category'        => 'The document category was created successfully.',
    'error_create_category'          => 'An error occurred while creating the document category.',
    'success_update_category'        => 'The document category was updated successfully.',
    'error_update_category'          => 'An error occurred while updating the document category.',

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_documents_head'           => 'Document archive list',
        'list_documents_title'          => 'Document archive list',
        'list_documents_description'    => 'Below is the table with the list of all documents saved in the building archive',
        'new_document_head'             => 'Create new document',
        'new_document_title'            => 'Create document in archive',
        'new_document_description'      => 'Fill out the form below to create a new document for the building archive',
        'edit_document_head'            => 'Edit document',
        'edit_document_title'           => 'Edit archive document',
        'edit_document_description'     => 'Fill out the form below to edit the document for the building archive',
        'list_categories_head'          => 'Archive categories',
        'list_categories_title'         => 'Document archive categories list',
        'list_categories_description'   => 'Below is the table with the list of all document categories in the building archive',
        'categories' => [
            'new_category_title'            => 'Create new category',
            'new_category_description'      => 'Add a new category for documents',
            'edit_category_title'           => 'Edit category: :category',
            'edit_category_description'     => 'Below you can edit the category details',
        ],
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'    => 'File and description',
        'content_desc'     => 'Attach the document and enter the main metadata.',
        'settings_title'   => 'Classification',
        'settings_desc'    => 'Organize the document in the archive by selecting a category and defining its visibility.',
        'recipients_title' => 'Document recipients',
        'recipients_desc'  => 'Link the file to specific buildings and residents.',
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => 'Classification details',
        'current_status'    => 'File Status',
        'visibility_status' => 'Visibility Status',
        'published'         => 'Public',
        'draft'             => 'Private (Admin Only)',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'                  => 'Document name',
        'category'              => 'Category',
        'no_category'                   => 'no category',
        'date'                          => 'Date',
        'buildings'             => 'Buildings',
        'buildings_desc'                => 'The buildings this document is linked to. Open a record to see its details.',
        'residents'             => 'Residents',
        'residents_desc'                => 'The people this document is shared with. Open a record to see their contact details.',
        'status'                => 'Status',
        'filter_by'             => 'Filter by name...',
        'approved_tooltip'      => 'Approved - click to remove approval',
        'unapproved_tooltip'    => 'Not approved - click to approve',
        'no_results'            => 'No results found.',
        'actions'               => 'Actions',
        'selected'              => 'selected',
        'loading'               => 'Loading...',
        'clear_all_filters'     => 'Reset all filters',
        'sort_asc'              => 'Ascending',
        'sort_desc'             => 'Descending',
        'categories' => [
            'name'        => 'Category name',
            'documents'     => 'Documents',
            'description' => 'Category description',
            'filter_by'   => 'Filter by name...',
            'no_results'  => 'No results found.',
            'actions'     => 'Actions',
        ],
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'notify_update' => "Email the people who already received this document",
        'name'                          => 'Document name',
        'description'                   => 'Document description',
        'category'                      => 'Categories',
        'buildings'                     => 'Buildings',
        'residents'                     => 'Residents',
        'visibility'                    => 'Document visibility',
        'select_document'               => 'Select document',
        'replace_document'              => 'Replace file',
        'remove_document'               => 'Remove file',
        'replace_existing_document'     => 'This file will replace the existing one.',
        'document'                      => 'Document',
        'document_info'                 => 'Information',
        'created'                       => 'Created on:',
        'status'                        => 'File status:',
        'missing'                       => 'Missing',
        'existing'                      => 'Present',
        'categories' => [
            'category_name'        => 'Name',
            'category_description' => 'Description',
        ],
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Enter document name',
        'description' => 'Enter document description',
        'category'    => 'Select category',
        'visibility'  => 'Select document visibility',
        'buildings'   => 'Select buildings',
        'residents'   => 'Select residents',
        'categories'  => [
            'category_name'        => 'Category name',
            'category_description' => 'Category description',
        ],
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_documents_created'          => 'No document created in the archive yet.',
        'delete_document_title'         => 'Are you sure you want to delete this document?',
        'delete_document_description'   => 'This action cannot be undone. It will delete the document and all associated data.',
        'select_document_title'         => 'Drag your document here',
        'select_document_description'   => 'Or click to select it from your device.',
        'document_supported_types'      => 'Only the PDF format is allowed.',
        'categories' => [
            'delete_category_title'       => 'Are you sure you want to delete this category?',
            'delete_category_description' => 'This action cannot be undone. Documents are not touched: a category that holds any cannot be deleted.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Toast
     | ------------------------------------------------------------------ */
    'toast' => [
        'success_title'   => 'Success',
        'success_message' => 'Category created successfully.',
        'error_title'     => 'Error',
        'error_message'   => 'Unable to create the category. Please try again later.',
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'total_storage_bytes'  => 'Archive space',
        'total_documents'      => 'Documents in the archive',
        'uploaded_this_month'  => 'Archived this month',
        'average_size_bytes'   => 'Average size in the archive',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'                => 'Public',
        'private'               => 'Private',
        'created_on'            => 'Created on',
        'sent_on_by'            => 'Sent :date by :name',
        'sent_on_by_category'   => 'Sent :date by :name in :category',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'notify_update' => "Sends an email to those who were already recipients, telling them the document has changed. Leave it off if you are fixing a typo: anyone added now receives it anyway, because for them it is new.",
        'visibility' => 'If set to private, only administrators will be able to view the document.',
        'category'   => 'A document can belong to several categories, and it is found inside each of them. Select at least one, or create a new one.',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_document'       => 'Create document',
        'list_categories'    => 'Categories',
        'edit_document'      => 'Edit',
        'delete_document'    => 'Delete',
        'save_document'      => 'Save',
        'list_documents'     => 'List',
        'cancel'             => 'Cancel',
        'back'               => 'Back',
        'back_to_list'       => 'Back to list',
        'show_more'          => 'Show more',
        'show_less'          => 'Show less',
        'categories' => [
            'new_category'      => 'Create category',
            'list_documents'    => 'Documents',
            'save_category'     => 'Save',
            'edit_category'     => 'Edit',
            'delete_category'   => 'Delete',
            'back_to_documents' => 'Back to documents',
        ],
    ],

    /* ------------------------------------------------------------------
     | Default Categories
     | ------------------------------------------------------------------ */
    'categories' => [
        'bilanci'   => 'Budgets',
        'verbali'   => 'Minutes',
        'avvisi'    => 'Notices',
        'contratti' => 'Contracts',
    ],

    /* ------------------------------------------------------------------
     | User Dashboard (Frontend)
     | ------------------------------------------------------------------ */
    'user' => [
        'latest_documents_title'       => 'Latest uploaded documents',
        'latest_documents_description' => 'List of the latest documents in the archive.',
        'pdf_only'                     => 'Only PDF files are allowed.',
        'selected_file'                => 'Selected file',
        'private_document_label'       => 'Create private document',
        'private_document_title'       => 'Create private document',
        'private_document_description' => 'When this option is selected, the document will be private and visible only to administrators.',
    ],

    /* ------------------------------------------------------------------
     | User Document List
     | ------------------------------------------------------------------ */
    'user_list' => [
        'category_title'             => 'Documents: :category',
        'category_description'       => 'Management of digital documents related to this building category.',
        'search_placeholder'         => 'Search by title...',
        'loading'                    => 'Updating...',
        'load_error'                 => 'Loading error.',
        'try_again'                  => 'Try again',
        'no_results_title'           => 'No results found',
        'no_results_description'     => 'Try adjusting the search terms.',
        'empty_category_title'       => 'Empty category',
        'empty_category_description' => 'No documents have been uploaded to this category yet.',
        'clear_search'               => 'Clear search',
        'upload_document'            => 'Upload document',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Documents',
        'new'  => 'New document',
        'edit' => 'Edit document',
        'view' => 'Document details',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'storage_title'             => 'Centralized Archive',
        'storage_desc'              => 'Store invoices, contracts, and minutes in a single cloud space that is always accessible.',
        'organization_title'        => 'Quick Organization',
        'organization_desc'         => 'Use folders and tags to immediately find important documents during meetings.',
        'privacy_title'             => 'Privacy and Permissions',
        'privacy_desc'              => 'Manage who can view documents by setting public or private visibility levels.',
        'upload_title'              => 'Upload',
        'upload_desc'               => 'Attach the file and define a name and description to quickly identify it in the archive.',
        'category_title'            => 'Classification',
        'category_desc'             => 'Assign an organizational category and establish the document\'s visibility level.',
        'audience_title'            => 'Recipients',
        'audience_desc'             => 'Link the file to specific buildings and residents to make it visible only to interested parties.',
        'categories_org_title'      => 'Organization',
        'categories_org_desc'       => 'Use categories to create digital folders (e.g., "Invoices", "Minutes", "Contracts").',
        'categories_assoc_title'    => 'Association',
        'categories_assoc_desc'     => 'Every document you upload to the archive can be assigned to one of these categories.',
        'categories_search_title'   => 'Quick Search',
        'categories_search_desc'    => 'Filtering the archive by category allows you to instantly find files during meetings.',
    ],

    'categoria_bloccata' => [
        'titolo' => 'This category cannot be deleted',
        'intro'  => '{1} One document is using «:nome». While that is the case, deleting it would leave that document without a category.|[2,*] :count documents are using «:nome». While that is the case, deleting it would leave them without a category.',
        'come'   => 'Open a document and remove this category from its list: once nobody uses it, deletion works.',
        'chiudi' => 'Got it',
        'usata'  => '{0} None|{1} 1 document|[2,*] :count documents',
    ],

];
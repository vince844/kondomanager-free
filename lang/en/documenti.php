<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_document'        => 'The new document has been created successfully.',
    'error_create_document'          => 'An error occurred while creating the document.',
    'no_file_uploaded'               => 'No file uploaded. Please try again.',
    'file_not_found'                 => 'No file found on the server.',
    'success_delete_document'        => 'The document has been deleted successfully.',
    'success_update_document'        => 'The document has been updated successfully.',
    'error_update_document'          => 'An error occurred while updating the document.',
    'error_delete_document'          => 'An error occurred while deleting the document.',
    'error_downloading_document'     => 'An error occurred while downloading the document.',
    'success_approve_document'       => 'The document has been approved successfully.',
    'error_approve_document'         => 'An error occurred while approving the document.',
    'error_notify_new_document'      => 'The document has been created, but an error occurred while sending the notification.',
    'error_notify_approved_document' => 'The document has been approved, but an error occurred while sending the notification.',
    'category_has_documents'         => 'This category contains documents. Move or delete them before deleting the category.',
    'success_delete_category'        => 'The document category has been deleted successfully.',
    'error_delete_category'          => 'An error occurred while deleting the document category.',
    'success_create_category'        => 'The document category has been created successfully.',
    'error_create_category'          => 'An error occurred while creating the document category.',
    'success_update_category'        => 'The document category has been updated successfully.',
    'error_update_category'          => 'An error occurred while updating the document category.',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_documents_head'        => 'Document archive list',
        'list_documents_title'       => 'Document archive list',
        'list_documents_description' => 'Below is the table listing all documents saved in the building archive',
        'new_document_head'          => 'Create new document',
        'new_document_title'         => 'Create document in archive',
        'new_document_description'   => 'Fill out the following form to create a new document for the building archive',
        'edit_document_head'         => 'Edit document',
        'edit_document_title'        => 'Edit archive document',
        'edit_document_description'  => 'Fill out the following form to edit the document in the building archive',
        'list_categories_head'       => 'Archive categories',
        'list_categories_title'      => 'Document archive categories list',
        'list_categories_description' => 'Below is the table listing all document categories in the building archive',
        'categories' => [
            'new_category_title'       => 'Create new category',
            'new_category_description' => 'Add a new category for documents',
            'edit_category_title'      => 'Edit category: :category',
            'edit_category_description' => 'Below you can edit the category details',
        ],
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'              => 'Document name',
        'category'          => 'Category',
        'buildings'         => 'Buildings',
        'residents'         => 'Resident records',
        'status'            => 'Status',
        'filter_by'         => 'Filter by name...',
        'approved_tooltip'  => 'Approved - click to remove approval',
        'unapproved_tooltip' => 'Not approved - click to approve',
        'no_results'        => 'No results found.',
        'actions'           => 'Actions',
        'selected'          => 'selected',
        'loading'           => 'Loading...',
        'clear_all_filters' => 'Clear all filters',
        'categories' => [
            'name'        => 'Category name',
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
        'name'                    => 'Document name',
        'description'             => 'Document description',
        'category'                => 'Category',
        'buildings'               => 'Buildings',
        'residents'               => 'Resident records',
        'visibility'              => 'Document visibility',
        'select_document'         => 'Select document',
        'replace_document'        => 'Replace file',
        'remove_document'         => 'Remove file',
        'replace_existing_document' => 'This file will replace the existing one.',
        'document'                => 'Document',
        'document_info'           => 'Information',
        'created'                 => 'Created on:',
        'status'                  => 'File status:',
        'missing'                 => 'Missing',
        'existing'                => 'Present',
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
        'residents'   => 'Select resident records',
        'categories' => [
            'category_name'        => 'Category name',
            'category_description' => 'Category description',
        ],
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_documents_created'       => 'No documents in the archive yet.',
        'delete_document_title'      => 'Are you sure you want to delete this document?',
        'delete_document_description' => 'This action is irreversible. It will delete the document and all associated data.',
        'select_document_title'      => 'Drag your document here',
        'select_document_description' => 'Or click to select it from your device.',
        'document_supported_types'   => 'Only PDF, JPEG, PNG formats are allowed.',
        'max_document_size'          => 'The file cannot exceed 20MB',
        'categories' => [
            'delete_category_title'       => 'Are you sure you want to delete this category?',
            'delete_category_description' => 'This action is irreversible. It will delete the category and all associated documents.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'total_storage_bytes' => 'Total space used',
        'total_documents'     => 'Total documents',
        'uploaded_this_month' => 'Uploaded this month',
        'average_size_bytes'  => 'Average document size',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'     => 'Public',
        'private'    => 'Private',
        'created_on' => 'Created on',
        'sent_on_by' => 'Sent :date by :name',
        'sent_on_by_category' => 'Sent :date by :name in :category',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility' => 'If set to private, only administrators will be able to view the document.',
        'category'   => 'Select a category to better organize the documents, or create a new one.',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_document'    => 'Create',
        'list_categories' => 'Categories',
        'edit_document'   => 'Edit',
        'delete_document' => 'Delete',
        'save_document'   => 'Save',
        'list_documents'  => 'List',
        'cancel'          => 'Cancel',
        'show_more'       => 'Show all',
        'show_less'       => 'Show less',
        'categories' => [
            'new_category'    => 'Create',
            'list_documents'  => 'Documents',
            'save_category'   => 'Save',
            'edit_category'   => 'Edit',
            'delete_category' => 'Delete',
        ],
    ],
];
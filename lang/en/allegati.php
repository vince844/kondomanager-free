<?php

return [
    'title' => 'Attachments',
    'subtitle' => 'Attachments from tickets and communications across all buildings',
    'empty' => 'No attachments found.',
    'empty_description' => 'Files uploaded to tickets or communications will appear here.',
    'empty_filtered_title' => 'No attachments match these filters',
    'empty_filtered_description' => 'Try changing the selected criteria or reset the filters to view the full list.',
    'empty_element_description' => 'No files have been uploaded for this item yet.',
    'pending_badge' => 'Pending approval',
    'published_badge' => 'Published',

    'header' => [
        'list_head' => 'Attachments list',
        'list_title' => 'Attachments list',
        'list_description' => 'Below is the table of attachments from tickets and communications across all buildings',
    ],

    'guides' => [
        'overview_title' => 'Unified list',
        'overview_desc' => 'View all files attached to tickets and communications in one place, with a direct link to the item page.',
        'moderation_title' => 'Bulk moderation',
        'moderation_desc' => 'Approve or delete pending attachments individually or in bulk directly from the table.',
        'export_title' => 'ZIP export',
        'export_desc' => 'Download filtered attachments in an archive organized by building, type, and item, complete with an elenco.csv summary.',
    ],

    'upload' => [
        'label' => 'Add attachments',
        'hint' => '{0} Allowed formats: :formati, max :limite per file. No more files can be added.|{1} Allowed formats: :formati, max :limite per file. You can add 1 more file (max :massimo).|[2,*] Allowed formats: :formati, max :limite per file. You can add :rimasti more files (max :massimo).',
        'no_slots' => 'The limit of :massimo attachments has been reached.',
        'button' => 'Upload attachments',
        'uploading' => 'Uploading…',
        'selected_files' => 'Selected files',
        'remove_file' => 'Remove',
    ],

    'actions' => [
        'open' => 'Open',
        'download' => 'Download',
        'rename' => 'Rename',
        'save_name' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'approve' => 'Approve',
        'approve_selected' => 'Approve selected',
        'delete_selected' => 'Delete selected',
        'download_zip' => 'Download ZIP',
        'save_settings' => 'Save settings',
    ],

    'confirm_delete' => [
        'title' => 'Delete attachment',
        'description' => 'Do you want to permanently delete the attachment ":nome"? This action cannot be undone.',
        'bulk_title' => 'Delete selected attachments',
        'bulk_description' => 'Do you want to permanently delete the selected attachments? This action cannot be undone.',
    ],

    'settings' => [
        'title' => 'Attachment settings',
        'dialog_description' => 'Configure visibility and moderation of attachments for this item.',
        'access_label' => 'Attachment access',
        'access_aperti' => 'Open',
        'access_consultazione' => 'Read-only',
        'access_riservati' => 'Administration only',
        'hint_aperti' => 'Anyone who sees the item can view and download files. From the portal, residents in the building can add new ones for tickets, and the author can for announcements.',
        'hint_consultazione' => 'In the portal files can be viewed and downloaded, but no new files can be added. Whoever uploaded a file can still rename or delete it.',
        'hint_riservati' => 'Files are only visible and manageable by administrators.',
        'moderation_label' => 'Under moderation',
        'moderation_hint' => 'Enabled: new files await approval, except those uploaded by users who can approve them. Disabled: files from users with publishing permission are published immediately.',
    ],

    'filters' => [
        'status' => 'Status',
        'status_all' => 'All statuses',
        'status_pending' => 'Pending approval',
        'status_published' => 'Published',
        'type' => 'Type',
        'type_all' => 'All types',
        'type_segnalazioni' => 'Tickets',
        'type_comunicazioni' => 'Communications',
        'condominio' => 'Building',
        'condominio_all' => 'All buildings',
        'author' => 'Author',
        'author_placeholder' => 'Search by author…',
        'author_tooltip' => 'The column displays the linked contact name (or the account name if no contact is linked). The search matches both the contact name and the user account name.',
        'date_from' => 'From',
        'date_to' => 'To',
        'reset' => 'Reset filters',
    ],

    'table' => [
        'name' => 'File',
        'element' => 'Item',
        'condominio' => 'Building',
        'author' => 'Author',
        'date' => 'Uploaded on',
        'status' => 'Status',
        'size' => 'Size',
        'actions' => 'Actions',
    ],
];


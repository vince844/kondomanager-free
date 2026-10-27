<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_ticket'               => "The new fault ticket has been created successfully.",
    'success_create_ticket_in_moderation' => "The new fault ticket has been created successfully, but it requires administrator approval.",
    'error_create_ticket'                 => "An error occurred while creating the fault ticket.",
    'success_update_ticket'               => "The fault ticket has been updated successfully.",
    'error_update_ticket'                 => "An error occurred while updating the fault ticket.",
    'success_delete_ticket'               => "The fault ticket has been deleted successfully.",
    'error_delete_ticket'                 => "An error occurred while deleting the fault ticket.",
    'success_approve_ticket'              => "The fault ticket has been approved successfully.",
    'error_approve_ticket'                => "An error occurred while approving the fault ticket.",
    'success_unapprove_ticket'            => "The fault ticket has been successfully placed in moderation.",
    'error_unapprove_ticket'              => "An error occurred while trying to place the fault ticket in moderation.",
    'error_notify_approved_ticket'        => "The fault ticket was approved, but an error occurred while sending the notification.",
    'error_notify_updated_ticket' => "The ticket was updated, but an error occurred while sending the notification.",
    'success_lock_ticket'                 => "The fault ticket has been locked successfully.",
    'error_lock_ticket'                   => "An error occurred while trying to lock the fault ticket.",
    'success_unlock_ticket'               => "The fault ticket has been unlocked successfully.",
    'error_unlock_ticket'                 => "An error occurred while trying to unlock the fault ticket.",

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_tickets_head'          => "List of fault tickets",
        'list_tickets_title'         => "List of fault tickets",
        'list_tickets_description'   => "Below is the table with the list of all registered fault tickets",
        'new_ticket_head'            => "Create fault ticket",
        'new_ticket_title'           => "Create fault ticket",
        'new_ticket_description'     => "Fill out the form below to create a new fault ticket",
        'view_ticket_head'           => "View fault ticket",
        'view_ticket_title'          => "Ticket details",
        'view_ticket_description'    => "Below are the details and status of the fault ticket",
        'edit_ticket_head'           => "Edit fault ticket",
        'edit_ticket_title'          => "Edit fault ticket",
        'edit_ticket_description'    => "Fill out the form below to edit the fault ticket",
        'widget_tickets_title'       => "Recent fault tickets",
        'widget_tickets_description' => "List of the most recently submitted fault tickets",
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'  => "Ticket details",
        'content_desc'   => "Enter the information regarding the problem to be reported.",
        'location_title' => "Recipients",
        'location_desc'  => "Indicate the building and the residents linked to this ticket.",
        'settings_title' => "Operational management",
        'settings_desc'  => "Set the processing status, priority, and visibility of the ticket.",
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => "Operational details",
        'priority_level'    => "Priority Level",
        'visibility_status' => "Visibility Status",
        'current_status'    => "Processing Status",
        'published'         => "Published",
        'draft'             => "Draft / Hidden",
        'interactions'      => "Interactions",
        'comments_enabled'  => "Comments enabled",
        'comments_disabled' => "Comments disabled",
        'admin_sender'      => "Administrator",
        'locked'            => 'Locked',
        'unlocked'          => 'Open',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_ticket_title'       => "Are you sure you want to delete this ticket?",
        'delete_ticket_description' => "This action cannot be undone. It will delete the ticket and all data associated with it.",
        'no_tickets'                => "No fault tickets",
        'no_tickets_created'        => "No fault tickets have been created yet",
        'no_view_permission'        => "You do not have sufficient permissions to view the tickets!",
        'no_tickets_found'          => "No fault tickets found.",
        'change_search_criteria'    => "Please change the search criteria and try again.",
        'cancel_search'             => "Cancel search",
        'loading_error'             => "An error occurred while loading the fault tickets. Please try again later.",
        'loading'                   => "Loading...",
        'try_again'                 => "Try again",
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'low_priority'    => "Low priority",
        'medium_priority' => "Medium priority",
        'high_priority'   => "High priority",
        'urgent_priority' => "Urgent priority",
        'open_tickets'    => "Open tickets",
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'priority'           => 'Priority',
        'status'             => 'Status',
        'filter_by_title'    => 'Filter by title...',
        'title'              => 'Title',
        'buildings'          => 'Buildings',
        'residents'          => 'Residents',
        'visibility'         => 'Visibility',
        'approved_tooltip'   => 'Approved - click to remove approval',
        'unapproved_tooltip' => 'Not approved - click to approve',
        'clear_all_filters'  => 'Reset all filters',
        'sort_asc'           => 'Ascending',
        'sort_desc'          => 'Descending',
        'loading'            => 'Loading...',
        'no_results'         => 'No results found.',
        'selected'           => 'Selected',
        'actions'            => 'Actions',
    ],

    /* ------------------------------------------------------------------
     | Status
     | ------------------------------------------------------------------ */
    'status' => [
        'open'        => 'Open',
        'in_progress' => 'In progress',
        'closed'      => 'Closed',
    ],

    /* ------------------------------------------------------------------
     | Priority
     | ------------------------------------------------------------------ */
    'priority' => [
        'low'    => 'Low',
        'medium' => 'Medium',
        'high'   => 'High',
        'urgent' => 'Urgent',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'     => 'Public',
        'private'    => 'Private',
        'created_on' => 'Created on',
        'sent_on_by' => 'Sent :date by :name',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_ticket'       => 'Create ticket',
        'edit_ticket'      => 'Edit',
        'delete_ticket'    => 'Delete',
        'save_ticket'      => 'Save',
        'list_tickets'     => 'List',
        'lock_ticket'      => 'Lock',
        'unlock_ticket'    => 'Unlock',
        'view_all_tickets' => 'View all',
        'show_more'        => 'Show more',
        'show_less'        => 'Show less',
        'cancel'           => 'Cancel',
        'back'             => 'Back',
        'back_to_list'     => "Back to list",
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'notify_update' => "Email the people following this ticket",
        'object'             => 'Ticket subject',
        'description'        => 'Ticket description',
        'visibility'         => 'Ticket visibility',
        'priority'           => 'Ticket priority',
        'status'             => 'Ticket status',
        'publication_status' => 'Publication status',
        'published'          => 'Published',
        'draft'              => 'Draft',
        'building'           => 'Building',
        'resident'           => 'Resident',
        'comments'           => 'Allow comments',
        'featured'           => 'Featured ticket',
        'private'            => 'Create ticket as private',
        'no_priority'        => 'None',
        'no_status'          => 'None',
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'object'      => 'Enter the ticket subject',
        'description' => 'Enter the ticket description',
        'visibility'  => 'Select visibility',
        'priority'    => 'Select priority',
        'status'      => 'Select status',
        'building'    => 'Select building',
        'resident'    => 'Select resident',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'notify_update' => "Sends an email to those who were already recipients, telling them the ticket has changed. Leave it off if you are fixing a typo: anyone added now receives it anyway, because for them it is new. While it is a draft or awaiting approval nothing is sent; once it is published and approved, all recipients receive it.",
        'visibility' => 'A draft, or a ticket awaiting approval, is not shown to residents; once published and approved, the residents it is addressed to can see it.',
        'priority'   => 'Set the priority level for this ticket to help administrators manage it accordingly.',
        'status'     => 'Set the current status of the ticket to track progress.',
        'comments'   => 'Allow users to add comments to this ticket.',
        'featured'   => 'The ticket will be featured and will always appear at the top of the ticket list.',
        'private'    => 'Private tickets can only be viewed by administrators and you.',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Tickets',
        'new'  => 'New ticket',
        'edit' => 'Edit ticket',
        'view' => 'Ticket details',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'reports_title'    => 'Centralized log',
        'reports_desc'     => 'View all fault tickets in one place, with status and priority always up to date.',
        'workflow_title'   => 'Operational workflow',
        'workflow_desc'    => 'Follow the entire cycle of the fault, from opening to resolution and closure.',
        'control_title'    => 'Control and priority',
        'control_desc'     => 'Prioritize critical emergencies and manage technical interventions with clear and trackable criteria.',
        'management_title' => 'Fault Management',
        'management_desc'  => 'Collect and centralize tickets for a smooth transition to future maintenance interventions.',
        'priority_title'   => 'Urgency Filter',
        'priority_desc'    => 'Identify critical emergencies at a glance (broken pipes, hazards) and separate them from routine maintenance.',
        'resolution_title' => 'Intervention Status',
        'resolution_desc'  => 'Track the progress of the resolution to keep residents constantly updated on the work.',
        'issue_title'      => 'Fault Details',
        'issue_desc'       => 'Provide a clear and complete description of the problem encountered to facilitate the future classification of the intervention.',
        'location_title'   => 'Context and Involved Parties',
        'location_desc'    => 'Accurately indicate the affected building and associate the residents\' records to facilitate communication and inspections.',
        'settings_title'   => 'Operational Parameters',
        'settings_desc'    => 'Define the level of urgency, update the processing status of the file, and adjust the visibility permissions on the bulletin board.',
    ],

    /* ------------------------------------------------------------------
     | Pubblicazione e privacy (Code 304 e 306)
     | ------------------------------------------------------------------ */
    'publication' => [
        'pending'   => 'Awaiting approval',
        'draft'     => 'Draft',
        'published' => 'Published',
        'needs_approval' => 'It is awaiting approval: choosing «Published» is not enough to show it to residents. They will see it once it is approved, with the shield next to the title in the list of tickets.',
        'waiting_approver' => 'It is awaiting approval: choosing «Published» is not enough to show it to residents. They will see it once it is approved by whoever approves tickets.',
    ],

    'privacy' => [
        'private'                 => 'Private',
        'private_hint'            => 'Visible only to the author, the linked people and the administrator',
        'guide_title'             => 'Who can see it',
        'guide_private'           => 'This report is private: only the person who opened it, the people it is addressed to and the administrator can see it.',
        'guide_not_private'       => 'This report is not private: the residents it is addressed to can see it (the whole building, if it is not addressed to anyone in particular), as can the administrator.',
        'guide_private_pending'   => 'This report is private. For now nobody else can see it, apart from the administrator: the people it is addressed to will see it once it is published and approved.',
        'guide_not_private_pending' => 'This report is not private. For now nobody else can see it, apart from the administrator: the residents it is addressed to (the whole building, if it is not addressed to anyone in particular) will see it once it is published and approved.',
        'make_public'             => 'Make public',
        'make_public_title'       => 'Make the report public?',
        'make_public_description' => 'All the residents of :condominio will see it, with the name of :autore, and those who could not see it will receive the notice of a new report. :autore will be notified. There is no command to make it private again.',
        'make_public_description_pending' => 'All the residents of :condominio will see it, with the name of :autore, once it is published and approved: right now it is not. :autore will be notified. There is no command to make it private again.',
        'make_public_author_loses_access' => 'Afterwards :autore will no longer see it in the portal: they are not listed among the residents of :condominio.',
        'make_public_confirm'     => 'Make public',
        'already_public'          => 'The report is already public.',
        'success_make_public'     => 'The report is now public.',
        'success_make_public_pending' => 'The report is no longer private: all the residents will see it once it is published and approved.',
        'error_make_public'       => 'The report could not be made public.',
        'error_notify_author'     => 'The report is no longer private, but the notice to the author was not sent.',
        'error_notify_residents'  => 'The report is no longer private, but the notice to the residents who could not see it was not sent.',
        'error_notify_both'       => 'The report is no longer private, but neither the notice to the author nor the one to the residents who could not see it was sent.',
        'residents_required'      => 'A private report must stay addressed to at least one resident. To make it visible to the whole building, use «Make public» on the report page.',
        'residents_required_ask_approver' => 'A private report must stay addressed to at least one resident. To make it visible to the whole building, it can be made public by whoever approves reports.',
    ],
];

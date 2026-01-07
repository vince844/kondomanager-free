<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_communication'                => "The new communication has been created successfully.",
    'success_create_communication_in_moderation'  => "The new communication has been created successfully, but it requires to be approved by administator",
    'error_create_communication'                  => "An error occurred while creating the new communication.",
    'success_update_communication'                => "The communication has been updated successfully",
    'error_update_communication'                  => "An error occurred while updating the communication.",
    'success_delete_communication'                => "The communication has been deleted successfully.",
    'error_delete_communication'                  => "An error occurred while deleting the communication.",
    'success_approve_communication'               => "The communication has been approved successfully.",
    'success_disapprove_communication'            => "The communication has been disapproved successfully.",
    'error_approve_communication'                 => "An error occurred while approving the communication.",
    'error_notify_new_communication'              => "The communication was created, but there was an error sending the notification.",
    'error_notify_approved_communication'         => "The communication was approved, but there was an error sending the notification.",
    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_communications_head'          => "List of communications",
        'list_communications_title'         => "List of communications",
        'list_communications_description'   => "Below is the table with the list of all communications saved on the bulletin board",
        'new_communication_head'            => "Create new communication",
        'new_communication_title'           => "Create new communication",
        'new_communication_description'     => "Fill out the form below to submit a new communication to the bulletin board",
        'edit_communication_head'           => "Edit communication",
        'edit_communication_title'          => "Edit communication",
        'edit_communication_description'    => "Modify the details of the communication on the bulletin board",
        'view_communication_head'           => "View communication",
        'widget_communications_title'       => "Recent communications recorded",
        'widget_communications_description' => "Overview of the most recent communications",
    ],
    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_communication_title'       => "Are you sure you want to delete this communication?",
        'delete_communication_description' => "This action cannot be undone. It will permanently delete the communication from the system.",
        'no_communications'                => "No communications",
        'no_communications_created'        => "No communications have been created yet",
        'no_view_permission'               => "You do not have permission to view communications!",
        'no_communications_found'          => "No communications found.",
        'change_search_criteria'           => "Please change the search criteria and try again.",
        'cancel_search'                    => "Cancel search",
        'loading_error'                    => "An error occurred while loading the communications. Please try again later.",
        'loading'                          => "Loading...",
        'try_again'                        => "Try again",
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
        'selected'           => 'Selected',
        'loading'            => 'Loading...',
        'no_results'         => 'No results found.',
        'clear_all_filters'  => 'Reset all filters',
        'approved_tooltip'   => 'Approved - click to remove approval',
        'unapproved_tooltip' => 'Not approved - click to approve',
        'actions'            => 'Actions',
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
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'subject'     => 'Communication subject',
        'description' => 'Communication description',
        'visibility'  => 'Communication visibility',
        'priority'    => 'Communication priority',
        'buildings'   => 'Buildings',
        'residents'   => 'Residents',
        'comments'    => 'Allow comments',
        'featured'    => 'Featured communication',
        'private'     => 'Create as private communication',
    ],
    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'subject'      => 'Enter the communication subject',
        'description'  => 'Enter the communication description',
        'visibility'   => 'Select communication visibility',
        'priority'     => 'Select communication priority',
        'buildings'    => 'Select buildings',
        'residents'    => 'Select residents',
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
        'created_on' => 'Created',
        'sent_on_by' => 'Sent :date by :name',
    ],
    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_communication'       => 'Create new',
        'edit_communication'      => 'Edit',
        'delete_communication'    => 'Delete',
        'save_communication'      => 'Save',
        'list_communications'     => 'List',
        'show_more'               => 'Show more',
        'show_less'               => 'Show less',
        'view_all_communications' => 'View all',
    ],
    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility'  => 'If set to private, only administrators will be able to view the communication.',
        'priority'    => 'The priority level may affect the visibility or urgency on the bulletin board.',
        'comments'    => 'When this option is selected, comments will be enabled for this communication',
        'featured'    => 'Featured communications are highlighted on the bulletin board to draw more attention.',
        'private'     => 'Private communications can only be viewed by administrators and you.',
    ]

];
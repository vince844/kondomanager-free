<?php

return [
    'header' => [
        'control_panel' => 'Control panel',
    ],

    'actions' => [
        'action_inbox' => 'Action inbox',
        'view_all' => 'View all',
        'view_all_feminine' => 'View all',
    ],

    'kpis' => [
        'registered_buildings' => 'Registered buildings',
        'all_buildings' => 'All buildings',
        'open_tickets' => 'Open tickets',
        'action_required' => 'Action required',
        'no_tickets' => 'No tickets',
        'upcoming_deadlines' => 'Upcoming deadlines',
        'next_7_days' => 'Next 7 days',
        'storage' => 'Storage',
        'usage' => 'Usage',
        'files_archived' => ':count archived files',
        'document_archive' => 'Document archive',
    ],

    'widgets' => [
        'latest_documents_title' => 'Latest documents',
        'latest_documents_description' => 'List of recently uploaded archived documents',
        'upcoming_events_title' => 'Upcoming agenda deadlines',
        'upcoming_events_description' => 'List of deadlines in the next days',
        'no_events_created' => 'No agenda deadlines have been created yet!',
        'starts_on' => 'starts on',
    ],

    'permissions' => [
        'view_archive_documents' => 'You do not have enough permissions to view archived documents!',
        'view_events' => 'You do not have enough permissions to view agenda deadlines!',
    ],

    'event_style' => [
        'expired_and_to_issue' => 'Expired and pending issue',
        'to_issue' => 'To issue',
        'urgent_check' => 'Urgent check',
        'payment_check' => 'Payment check',
        'rejected' => 'Rejected',
        'paid' => 'Paid',
        'covered' => 'Covered',
        'partially_covered' => 'Partially covered',
        'credit' => 'Credit',
        'partially_paid' => 'Partially paid',
        'in_review' => 'Under review',
        'expired' => 'Expired',
        'expires_in_days' => 'Expires in :count days',
        'in_days' => 'In :count days',
    ],

    'event_categories' => [
        'maintenance' => 'Maintenance',
        'assembly' => 'Assembly',
        'cleaning' => 'Cleaning',
        'generic' => 'General',
        'intervention_requests' => 'Intervention requests',
        'administrative_deadlines' => 'Administrative deadlines',
        'installment_deadlines' => 'Installment deadlines',
    ],

    'buildings_dropdown' => [
        'select_aria' => 'Select building',
        'select_placeholder' => 'Select building...',
        'search_placeholder' => 'Search building...',
        'empty_state' => 'No buildings found.',
        'reset_selection' => 'Reset selection',
        'management' => 'Management',
        'go_to_management_title' => 'Go to management panel',
    ],

    'inbox' => [
        'page_title' => 'Action inbox',
        'back_to_dashboard' => 'Back to dashboard',
        'subtitle' => 'Your command center. Manage deadlines and payments from one place.',
        'expiring_activities' => 'Expiring activities',
        'not_available' => '—',
        'yesterday' => 'Yesterday',
        'days_late' => ':count days late',
        'results_shown' => 'Showing :count results',
        'filters' => [
            'urgent' => 'Expired / Urgent',
            'payments' => 'Payment checks',
            'maintenance' => 'Tickets and maintenance',
            'all' => 'View all',
            'reset' => 'Reset filters',
        ],
        'table' => [
            'deadline' => 'Deadline',
            'building' => 'Building',
            'activity' => 'Activity',
            'actions' => 'Actions',
        ],
        'actions' => [
            'reject_report' => 'Reject report',
            'register' => 'Register',
            'manage' => 'Manage',
            'details' => 'Details',
        ],
        'empty' => [
            'title' => 'All clear!',
            'description' => 'No urgent activity requires attention.',
        ],
        'reject_modal' => [
            'title' => 'Reject report',
            'description_prefix' => 'You are about to reject the payment reported by',
            'tenant_fallback' => 'Resident',
            'description_warning' => 'Warning: this action is irreversible.',
            'reason_label' => 'Reason (visible to the user)',
            'reason_placeholder' => 'Ex: Transfer not found in account statement...',
            'confirm' => 'Confirm rejection',
        ],
    ],
];

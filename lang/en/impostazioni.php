<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_update_notification_preferences' => 'Your notification preferences have been updated successfully',
    'error_update_notification_preferences'   => 'An error occurred while updating your notification preferences',
    'success_save_general_settings'           => 'General settings are successfully saved.',
    'error_save_general_settings'             => 'An error occurred while saving general settings.',
    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'settings_head'                => 'Impostazioni',
        'settings_title'               => 'Application settings',
        'settings_description'         => 'Below is a list of all the configurable settings for the application',
        'general_settings_title'       => 'General settings',
        'general_settings_description' => 'On this page you can manage the general settings of the application',
    ],
    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'manage'    => 'Manage',
        'settings'  => 'Settings',
    ],
    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'general_settings_title'        => 'General settings',
        'general_settings_description'  => 'General application configuration settings',
        'users_settings_title'          => 'User management',
        'users_settings_description'    => 'Settings for managing users, roles, and permissions',
        'backups_settings_title'        => 'Backup management',
        'backups_settings_description'  => 'Settings related to database and file backups',
        'language_settings_title'       => 'Application language',
        'language_settings_description' => 'Select the primary language for the entire application',
        'default_building_title'        => 'Open building on login',
        'default_building_description'  => 'If enabled, users will be automatically redirected to their default building after login',
        'select_building_title'         => 'Default building',
        'select_building_description'   => 'Choose which building should open automatically after login',
        'user_registration_title'       => 'Enable user registration',
        'user_registration_description' => 'If enabled, visitors can create a new account from the home page',
    ],
    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building' => 'Select building',
        'select_language' => 'Select language',
        'search_settings' => 'Filter settings...',
        'language' => [
            'it' => 'Italian',
            'en' => 'English',
            'pt' => 'Portuguese',
        ],
    ],
    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'save_settings' => 'Save settings',
    ],
    /* ------------------------------------------------------------------
    | Sidebar navigation
    | ------------------------------------------------------------------ */
    'sidebar' => [
        'users'         => 'Users',
        'roles'         => 'Roles',
        'permissions'   => 'Permissions',
        'invites'       => 'Invites',
    ],
];
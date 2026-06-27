<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_update_notification_preferences' => 'Your notification preferences have been updated successfully.',
    'error_update_notification_preferences'   => 'An error occurred while trying to update your notification preferences.',
    'success_save_general_settings'           => 'General settings have been saved successfully.',
    'error_save_general_settings'             => 'An error occurred while saving the general settings.',
    'success_save_cron_settings'              => 'Cloud automation settings have been saved successfully.',
    'error_save_cron_settings'                => 'An error occurred while saving the cloud automation settings.',
    'success_regenerate_cron_token'           => 'Webhook token regenerated successfully.',
    'error_regenerate_cron_token'             => 'An error occurred while regenerating the token.',
    'success_save_mail_settings'              => 'Email configuration saved successfully.',
    'error_save_mail_settings'                => 'Error while saving the email configuration.',
    'success_save_print_settings'             => 'Print settings have been saved successfully.',

    /* ------------------------------------------------------------------
     | Mail Status Badge
     | ------------------------------------------------------------------ */
    'mail_status' => [
        'database' => 'Database Configuration',
        'env'      => '.env Configuration',
        'log'      => 'Safe Mode (Log)',
    ],

    /* ------------------------------------------------------------------
     | Driver descriptions
     | ------------------------------------------------------------------ */
    'driver' => [
        'smtp_description'     => 'Recommended. Use an external SMTP server (Gmail, Brevo, etc.)',
        'sendmail_description' => 'Only for VPS or dedicated servers with Postfix and SPF/DKIM configured.',
    ],

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'settings_head'                => 'Settings',
        'settings_title'               => 'Application settings',
        'settings_description'         => 'Below is a list of all configurable settings for the application.',
        'general_settings_title'       => 'General settings',
        'general_settings_description' => 'On this page you can manage the general settings of the application.',
        'cron_settings_title'          => 'Cloud automation (External Cron)',
        'cron_settings_description'    => 'Use this feature if your hosting does not support cron jobs every minute. Supported services: cron-job.org',
        'mail_settings_title'          => 'Email configuration',
        'mail_settings_description'    => 'Configure the sending method for invoices, reminders and official communications to residents.',
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'manage'                => 'Manage',
        'settings'              => 'Settings',
        'update_now'            => 'Update now',
                'discover_patreon'      => 'Descubre Patreon',
        'back_to_settings'      => 'Settings',
        'mail_host'             => 'SMTP Server (Host)',
        'mail_port'             => 'SMTP Port',
        'mail_username'         => 'Username / Email',
        'mail_password'         => 'SMTP Password',
        'mail_encryption'       => 'Encryption (Security)',
        'mail_from_address'     => 'Sender email address',
        'mail_from_name'        => 'Sender display name',
        'save_settings'         => 'Save configuration',
        'send_test'             => 'Send test email',
        'password_is_set'       => 'Password set and secure',
        'enable_db_settings'    => 'Enable database configuration',
        'enable_db_description' => 'If disabled, the system will use the parameters defined in the .env file.',
        'mail_driver'           => 'Sending method',
        'api_key_is_set'        => 'API key configured and secure',
        'encryption_none'       => 'None',
        'mail_sendmail_path'      => 'Sendmail path',
        'mail_sendmail_path_hint' => 'Leave the default value if unsure. Change only if your server uses a different path.',
        'print_legal_note'        => 'Legal Note / Footer',
        'print_legal_note_help'   => 'This text will appear in the footer of every statement, along with the page number.',
        'print_admin_signature'   => 'Administrator Signature',
        'print_no_signature'      => 'No signature',
        'print_upload_image'      => 'Upload Image',
        'print_remove_signature'  => 'Remove',
        'print_signature_help'    => 'Use a PNG or JPG image with a white or transparent background (max 2MB). The image will be printed at the end of the last page of statements and reports.',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'general_settings_title'                => 'General settings',
        'general_settings_description'          => 'General configuration settings for the application.',
        'users_settings_title'                  => 'User management',
        'users_settings_description'            => 'Settings for managing users, roles and permissions.',
        'backups_settings_title'                => 'Backup management',
        'backups_settings_description'          => 'Settings for managing backups.',
        'updates_title'                         => 'System updates',
        'updates_desc_available'                => 'New version available: :version',
        'updates_desc_latest'                   => 'The system is up to date with the latest version.',
        'language_settings_title'               => 'Application language',
        'language_settings_description'         => 'Select the main language for the application.',
        'default_building_title'                => 'Open building on login',
        'default_building_description'          => 'If enabled, the user will be redirected directly to the selected building.',
        'select_building_title'                 => 'Default building',
        'select_building_description'           => 'Select the building to open automatically after login.',
        'user_registration_title'               => 'Enable user registration',
        'user_registration_description'         => 'If enabled, users can register from the home page.',
        'default_role_title'                    => 'Default role for new users',
        'default_role_description'              => 'Choose which role to automatically assign to users who register from the frontend.',
        'force_comment_moderation_title'        => 'Force comment moderation',
        'force_comment_moderation_description'  => 'If enabled, all comments from regular users will require admin approval before being published and visible.',

        'mail_settings_title'       => 'Email configuration',
        'mail_settings_description' => 'Choose the sending method, configure credentials and test the connection.',
        'print_settings_title'                  => 'PDF Print Settings',
        'print_settings_description'            => 'Configure the appearance and default data that will appear at the bottom of all system generated documents.',
        'mail_guide_title'   => 'Configuration guide',
        'mail_guide_gmail'   => 'Gmail (recommended for everyone): Enable 2-step verification, generate an "App Password" and use smtp.gmail.com, port 587, TLS. Works on any hosting without DNS configuration.',
        'mail_guide_smtp2go' => 'Other SMTP providers (Brevo, SMTP2Go, etc.): require sender domain verification via DNS records (SPF/DKIM). Use this option only if you have your own domain with access to the DNS panel.',
        'mail_guide_domain'  => 'Sendmail: works only on VPS or dedicated servers with Postfix installed and SPF/DKIM configured. Not suitable for shared hosting.',

        'mail_info_title'       => 'How does email sending work?',
        'mail_info_description' => 'Kondomanager supports two sending methods.<br><br><strong>SMTP</strong> — the recommended method for everyone. Uses an external mail server (e.g. Gmail). Emails are delivered reliably without advanced configuration.<br><br><strong>Sendmail</strong> — for advanced users with a VPS or dedicated server where Postfix is installed and SPF/DKIM records are configured in DNS. On shared hosting, emails will be rejected by recipients.<br><br>If you disable the database configuration, the <strong>.env</strong> file settings will be used.',

        'mail_legend_title'    => 'Status legend',
        'mail_legend_database' => 'Uses the credentials configured on this page (takes priority over .env).',
        'mail_legend_env'      => 'Uses the configuration defined in the server .env file.',
        'mail_legend_log'      => 'Email sending disabled — emails are written to the log file only.',

        'sendmail_guide_title'       => 'Sendmail — dedicated servers only',
        'sendmail_guide_description' => 'Use this option ONLY if your server is a VPS or dedicated server with Postfix/Exim installed and SPF and DKIM records configured in your sender domain DNS. On shared hosting (Altervista, etc.) emails will be rejected by recipients because modern providers (Gmail, Outlook) require DNS authentication. For shared hosting use Gmail SMTP.',

        'test_header'          => 'Immediate send test',
        'test_success_title'   => 'Connection successful',
        'test_success_message' => 'The test email was sent successfully to the recipient.',
        'test_error_title'     => 'Connection error',
        'test_error_message'   => 'Unable to send the email. Check the parameters and try again.',
        'test_unsaved_warning' => 'You have unsaved changes. Save the configuration before sending the test.',

        'cron_info_title'               => 'What is Cloud Automation?',
        'cron_info_description'         => 'Kondomanager runs scheduled tasks in the background (e.g. instalment generation, email sending).<br><br>Normally, the server handles everything automatically. Enable this option <strong>ONLY</strong> if you are on <strong>Shared Hosting</strong> that does not allow configuring the system "Crontab" via terminal.',
        'cron_legend_title'             => 'Operating Mode',
        'cron_legend_external'          => 'Webhook (External): The system waits for a signal from cron-job.org.',
        'cron_legend_internal'          => 'System Cron (Internal): The server manages processes autonomously.',
        'cron_settings_title'                     => 'Cloud automation',
        'cron_settings_description'               => 'Configure cron-job.org for shared hosting.',
        'enable_external_scheduler_title'         => 'Enable external scheduler',
        'enable_external_scheduler_description'   => 'Allow third-party services to run automations.',
        'webhook_url_title'                       => 'Webhook URL',
        'webhook_url_description'                 => 'Copy this URL and set up a GET call every 1 minute on your external service.',
        'webhook_url_badge'                       => 'Secret',
        'security_warning_title'                  => 'IP security active',
        'security_warning_description'            => 'The system only accepts calls from the official cron-job.org IP addresses. If you use another service, this configuration will not work.',
        'logs_settings_title'                     => 'Audit & System Logs',
        'patreon_title'                         => 'Apóyanos en Patreon',
        'patreon_desc'                          => 'Únete a la comunidad y apoya el desarrollo Open Source de Kondomanager.',
        'logs_settings_description'             => 'View the history of sent emails, user activity and system logs.',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building'     => 'Select building',
        'select_language'     => 'Select language',
        'search_settings'     => 'Filter settings...',
        'mail_host'           => 'e.g. smtp.gmail.com',
        'mail_password'       => 'Enter SMTP password',
        'mail_password_keep'  => 'Leave blank to keep the current password',
        'mail_password_enter' => 'Enter SMTP password',
        'mail_from_address'   => 'e.g. admin@your-domain.com',
        'test_recipient'      => 'Enter email for test',
        'select_role'         => 'Select a role',
        'print_legal_note'    => 'E.g.: Profession exercised pursuant to Law January 14, 2013, no. 4 - VAT 01234567890 - Liability Policy no. XYZ',

        'language' => [
            'it' => 'Italian',
            'en' => 'English',
            'pt' => 'Portuguese',
            'es' => 'Spanish',
        ],
    ],

    'actions' => [
        'save_settings'    => 'Save settings',
        'copy_url'         => 'Copy URL',
        'regenerate_token' => 'Regenerate token',
    ],
    'confirmations' => [
        'regenerate_token' => 'Are you sure? You will need to update the URL on cron-job.org.',
    ],
    'sidebar' => [
        'users'       => 'Users',
        'roles'       => 'Roles',
        'permissions' => 'Permissions',
        'invites'     => 'Invites',
    ],
    
    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'language_title' => 'Language & Translations',
        'language_desc'  => 'Choose the default language the application will be displayed in for new users and unconfigured devices.',
        'access_title'   => 'Login & Navigation',
        'access_desc'    => 'Streamline your daily workflow: set a specific building to open automatically right after login, skipping the dashboard.',
        'security_title' => 'Registration & Roles',
        'security_desc'  => 'Enable public registration on the website. New registrants will automatically be assigned the default role you select here.',
        'print_footer_title' => 'Document footer',
        'print_footer_desc'  => 'Set the legal text that will appear at the bottom of all generated PDFs.',
        'print_signature_title' => 'Document signature',
        'print_signature_desc'  => 'Upload or draw your signature to append to reports and official documents.',
        'print_layout_title' => 'Format & Layout',
        'print_layout_desc'  => 'All documents are automatically generated in A4 format, with adaptive margins for the header.',
    ],
];
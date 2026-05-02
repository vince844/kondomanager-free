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

    /* ------------------------------------------------------------------
     | Mail Status Badge
     | ------------------------------------------------------------------ */
    'mail_status' => [
        'database' => 'SMTP from Database',
        'env'      => '.env Configuration',
        'log'      => 'Safe Mode (Log)',
    ],

    /* ------------------------------------------------------------------
     | Driver descriptions
     | ------------------------------------------------------------------ */
    'driver' => [
        'smtp_description'     => 'External SMTP server (Gmail, Brevo, etc.)',
        'sendmail_description' => 'Local PHP mail — ideal for shared hosting',
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
        'mail_settings_title'                   => 'Email configuration',
        'mail_settings_description'             => 'Choose the sending method, configure credentials and test the connection.',
        'mail_guide_title'                      => 'SMTP configuration guide',
        'mail_guide_gmail'                      => 'Gmail: Enable 2-step verification and generate an "App Password". Use port 587 with TLS.',
        'mail_guide_smtp2go'                    => 'Free Hosting: If you use Altervista, consider Sendmail or SMTP2Go to bypass port restrictions.',
        'mail_guide_domain'                     => 'Pro tip: Use a validated professional domain to avoid emails landing in spam.',
        'mail_info_title'                       => 'How does email sending work?',
        'mail_info_description'                 => 'Kondomanager supports two sending methods: <strong>SMTP</strong> for external servers (Gmail, Brevo, etc.) and <strong>Sendmail</strong> for shared hosting (e.g. Altervista) where outbound SMTP ports are blocked.<br><br>If you disable the database configuration, the <strong>.env</strong> file settings will be used.',
        'mail_legend_title'                     => 'Status legend',
        'mail_legend_database'                  => 'Uses your custom credentials (Priority).',
        'mail_legend_env'                       => 'Uses the default server configuration.',
        'mail_legend_log'                       => 'Email sending disabled (Log file only).',
        'sendmail_guide_title'                  => 'Sendmail — no credentials required',
        'sendmail_guide_description'            => 'Emails are sent through the local mail server (PHP mail). Ideal for shared hosting such as Altervista where outbound SMTP ports are blocked. Deliverability depends on the server\'s reputation.',
        'test_header'                           => 'Immediate send test',
        'test_success_title'                    => 'Connection successful',
        'test_success_message'                  => 'The test email was sent successfully to the recipient.',
        'test_error_title'                      => 'Connection error',
        'test_error_message'                    => 'Unable to send the email. Check the parameters and try again.',
        'cron_info_title'                       => 'What is Cloud Automation?',
        'cron_info_description'                 => 'Kondomanager runs scheduled tasks in the background (e.g. instalment generation, email sending).<br><br>Normally, the server handles everything automatically. Enable this option <strong>ONLY</strong> if you are on <strong>Shared Hosting</strong> that does not allow configuring the system "Crontab" via terminal.',
        'cron_legend_title'                     => 'Operating Mode',
        'cron_legend_external'                  => 'Webhook (External): The system waits for a signal from cron-job.org.',
        'cron_legend_internal'                  => 'System Cron (Internal): The server manages processes autonomously.',
        'cron_settings_title'                   => 'Cloud automation',
        'cron_settings_description'             => 'Configure cron-job.org for shared hosting.',
        'enable_external_scheduler_title'       => 'Enable external scheduler',
        'enable_external_scheduler_description' => 'Allow third-party services to run automations.',
        'webhook_url_title'                     => 'Webhook URL',
        'webhook_url_description'               => 'Copy this URL and set up a GET call every 1 minute on your external service.',
        'webhook_url_badge'                     => 'Secret',
        'security_warning_title'                => 'IP security active',
        'security_warning_description'          => 'The system only accepts calls from the official cron-job.org IP addresses. If you use another service, this configuration will not work.',
        'logs_settings_title'                   => 'Audit & System Logs',
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

        'language' => [
            'it' => 'Italian',
            'en' => 'English',
            'pt' => 'Portuguese',
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
];
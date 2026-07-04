<?php

return [

    'wizard' => [
        'title' => 'Kondomanager — Installation',
        'brand_subtitle' => 'Open source property management software',
        'wait_default' => 'Please wait...',
        'wait_environment' => 'Setting up the database, please do not close this page...',
    ],

    'actions' => [
        'next' => 'Next',
        'back' => 'Back',
        'finish' => 'Finish',
        'skip' => 'Skip',
        'loading' => 'Please wait...',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
    ],

    'steps' => [
        'welcome' => [
            'label' => 'Welcome to Kondomanager',
            'description' => 'Get started',
            'guide' => 'This guided wizard will walk you step by step through installing and configuring the platform.',
        ],
        'requirements' => [
            'label' => 'Server requirements',
            'description' => 'Make sure all required requirements are met',
            'guide' => 'Check that your server meets all the requirements listed below. If anything is missing, contact your hosting provider to fix it before continuing.',
        ],
        'environment' => [
            'label' => 'Application & database',
            'description' => 'Name, language and database connection',
            'guide' => "Enter the application's name, web address and default language, along with your database access credentials (you'll find these in your hosting control panel, or you may have created them yourself).",
        ],
        'mail' => [
            'label' => 'Mail settings',
            'description' => 'Outgoing mail settings',
            'guide' => 'Configure the SMTP server Kondomanager will use to send emails (notifications, password resets, communications to unit owners). You can skip this step and set it up later from General Settings.',
        ],
        'admin' => [
            'label' => 'Create administrator',
            'description' => 'Create the administrator user',
            'guide' => "Create your main administrator account: you'll use these credentials to log in to Kondomanager once the installation is complete.",
        ],
        'finish' => [
            'label' => 'Finish',
            'description' => 'Complete the setup',
            'guide' => 'One last step before you start using Kondomanager: set up a cron job on your server (running every minute, pointing to the scheduler URL), otherwise background processes — billing generation, reminders, email notifications — will not run.',
        ],
    ],

    'welcome' => [
        'before_start' => "Before you begin, keep your database credentials handy — and your SMTP server details too, if you'd like to set up email right away. Also make sure you meet the following minimum server requirements:",
        'php_requirement' => 'The server must have PHP :version or higher installed.',
        'php_check_link' => 'How to check the installed PHP version',
        'extensions_label' => 'Extensions:',
        'extensions_text' => 'Make sure the following extensions are enabled in your PHP configuration.',
        'extensions_check_link' => 'How to check installed PHP extensions',
        'database_label' => 'Database:',
        'database_text' => 'The application needs a MySQL database to store data. Make sure you have created a database and have the following at hand: host, port, database name, username and password.',
        'database_link' => 'How to create a database on cPanel',
        'mail_label' => 'Email settings:',
        'mail_text' => "The application sends important emails such as user registration, password resets and notifications. Make sure you have a valid email address and password at hand, matching your server's host and port configuration.",
        'mail_link' => 'Test your SMTP configuration',
        'cache_label' => 'Clear the cache:',
        'cache_text' => 'If needed, clear the server cache before proceeding.',
    ],

    'requirements' => [
        'php_version' => 'PHP Version',
        'extensions' => 'Extensions',
        'permissions' => 'Permissions',
        'recheck_button' => 'Recheck',
        'last_checked' => 'Last checked: :time',
    ],

    'sections' => [
        'database' => 'Database',
    ],

    'fields' => [
        'app_name' => ['label' => 'Application name', 'tooltip' => 'The name shown in the browser tab and as the sender in emails sent to unit owners.'],
        'app_url' => ['label' => 'Application URL', 'tooltip' => 'The full web address where Kondomanager will be reachable, e.g. https://yourdomain.com.'],
        'app_locale' => ['label' => 'Language', 'tooltip' => 'The default language the application will be shown in to new users. You can change it anytime from General Settings.'],
        'db_host' => ['label' => 'Host', 'tooltip' => 'The database server address, usually 127.0.0.1 or localhost.'],
        'db_port' => ['label' => 'Port', 'tooltip' => 'The MySQL server port, usually 3306.'],
        'db_database' => ['label' => 'Database', 'tooltip' => 'The name of the database created specifically for Kondomanager.'],
        'db_username' => ['label' => 'Username', 'tooltip' => 'A user with access permissions to the database.'],
        'db_password' => ['label' => 'Password', 'tooltip' => "The password for the database user above."],
        'mail_mailer' => ['label' => 'Mailer', 'tooltip' => 'The mail sending method, usually "smtp".'],
        'mail_host' => ['label' => 'Host', 'tooltip' => 'The SMTP server address, e.g. smtp.gmail.com.'],
        'mail_port' => ['label' => 'Port', 'tooltip' => 'The SMTP port: usually 587 (TLS) or 465 (SSL).'],
        'mail_username' => ['label' => 'Username', 'tooltip' => 'The email address or username to authenticate with the SMTP server.'],
        'mail_password' => ['label' => 'Password', 'tooltip' => 'The password for the email account above.'],
        'mail_encryption' => ['label' => 'Encryption', 'tooltip' => 'The encryption type required by the SMTP server: TLS is the most common (port 587), SSL for direct encrypted connections (port 465). Choose "None" only for local or test servers.', 'none' => 'None'],
        'mail_from_address' => ['label' => 'From address', 'tooltip' => 'The email address unit owners will see as the sender of communications.'],
        'mail_from_name' => ['label' => 'From name', 'tooltip' => 'The name displayed next to the sender address in emails.'],
        'admin_name' => ['label' => 'Full name', 'tooltip' => "The name of Kondomanager's main administrator."],
        'admin_email' => ['label' => 'Email address', 'tooltip' => "You'll use this address to log in as administrator."],
        'admin_password' => ['label' => 'Password', 'tooltip' => 'At least 6 characters. Choose a secure password.'],
        'admin_password_confirmation' => ['label' => 'Confirm password', 'tooltip' => 'Repeat the password to confirm it.'],
    ],

    'finish' => [
        'title' => 'Installation complete!',
        'description' => 'The application has been successfully installed and configured.',
        'save_settings' => 'Save settings',
        'cron_guide' => [
            'title' => 'Cron job setup guide',
            'subtitle' => 'Pick your hosting environment for detailed instructions. You can revisit this guide anytime from Settings > Cron.',
            'tab_webhook' => 'cron-job.org',
            'tab_cpanel' => 'cPanel',
            'tab_plesk' => 'Plesk / VPS',
            'webhook_intro' => "Ideal for shared hosting without terminal access (e.g. Altervista): a free service \"calls\" your installation every minute instead of a real cron job.",
            'webhook_step1' => 'Log in to Kondomanager as administrator, go to Settings > Cron, enable "External scheduler" and copy the generated webhook URL.',
            'webhook_step2' => 'Create a free account on cron-job.org, create a new cron job pasting the copied URL, and set it to run every minute.',
            'cpanel_intro' => 'Native setup recommended for professional hosting with cPanel — more stable and efficient than the webhook.',
            'cpanel_step' => 'In cPanel\'s "Cron Jobs" section, set the frequency to every minute (* * * * *) and paste this command (adjusting the path):',
            'cpanel_command' => '/usr/local/bin/php /home/yoursite/public_html/artisan schedule:run >> /dev/null 2>&1',
            'plesk_intro' => 'On Plesk servers or VPS, stricter timeout limits require two separate processes instead of one.',
            'plesk_step1' => 'In the .env file, set:',
            'plesk_env' => 'SCHEDULE_QUEUE_WORKER=false',
            'plesk_step2' => 'Create two separate scheduled tasks in Plesk, both running every minute (* * * * *):',
            'plesk_command1_label' => 'Cron 1 — Scheduler',
            'plesk_command1' => 'php artisan schedule:run >> /dev/null 2>&1',
            'plesk_command2_label' => 'Cron 2 — Queue worker',
            'plesk_command2' => 'php artisan queue:work --stop-when-empty --max-time=55 --tries=3',
        ],
    ],

    'validation' => [
        'no_whitespace' => 'The :attribute field cannot contain spaces.',
    ],

    'mail' => [
        'test_button' => 'Send test email',
        'test_subject' => 'Test email from Kondomanager',
        'test_body' => 'If you received this email, your SMTP configuration is correct and Kondomanager will be able to send notifications, reminders and communications to unit owners.',
        'test_success' => 'Test email sent successfully to :email. Check your inbox.',
        'test_error' => 'Sending failed: :error',
        'test_recipient_label' => 'Send test email to',
        'test_recipient_tooltip' => 'The address to send the test email to — use one you can check right away, different from the SMTP server if you want to verify real delivery.',
    ],

    'database' => [
        'test_button' => 'Test connection',
        'test_success' => 'Connection successful! The database ":database" is reachable with these credentials.',
        'test_error' => 'Connection failed: :error',
    ],

];

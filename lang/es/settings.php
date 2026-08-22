<?php

return [
    'layout' => [
        'title' => 'Settings',
        'description' => 'Manage your profile and account settings',
        'nav' => [
            'profile' => 'Profile',
            'password' => 'Password',
            'two_factor' => '2FA Security',
            'notifications' => 'Notifications',
            'appearance' => 'Appearance',
        ],
    ],

    'appearance' => [
        'title' => 'Appearance Settings',
        'description' => 'Update the application\'s appearance settings',
        'tabs' => [
            'light' => 'Light',
            'dark' => 'Dark',
            'system' => 'System',
        ],
    ],

    'profile' => [
        'title' => 'Profile Settings',
        'heading' => 'Profile Information',
        'description' => 'Update your name and email address',
        'name' => 'Full Name',
        'name_placeholder' => 'Full Name',
        'email' => 'Email Address',
        'email_placeholder' => 'Email Address',
        'email_unverified' => 'Your email address is unverified.',
        'resend_verification' => 'Click here to receive a new verification email.',
        'verification_sent' => 'A new verification link has been sent to your email address.',
        'save' => 'Save',
        'saved' => 'Saved',
    ],

    'password' => [
        'title' => 'Password Settings',
        'heading' => 'Update Password',
        'description' => 'Use a long and random password to keep your account secure',
        'current_password' => 'Current Password',
        'current_password_placeholder' => 'Current Password',
        'new_password' => 'New Password',
        'new_password_placeholder' => 'New Password',
        'confirm_password' => 'Confirm Password',
        'confirm_password_placeholder' => 'Confirm Password',
        'save' => 'Save Password',
        'saved' => 'Saved',
    ],

    'notifications' => [
        'title' => 'Notification Settings',
        'heading' => 'Notification Settings',
        'description' => 'Select below the email notifications you wish to receive',
        'empty' => 'There are no email notifications available to select.',
        'save' => 'Save Preferences',
        'enable_all' => 'Activar todas',
        'disable_all' => 'Desactivar todas',
        'counter' => ':attive activas de :totali',
    ],

    'two_factor' => [
        'title' => 'Two-Factor Authentication',
        'heading' => 'Two-Factor Authentication',
        'description' => 'Manage two-factor authentication (2FA) settings',
        'disabled' => 'Disabled',
        'enabled' => 'Enabled',
        'enable' => 'Enable',
        'disable' => 'Disable 2FA',
        'intro' => 'When two-factor authentication (2FA) is enabled, you will need to enter a secure code during login. You can get this code from the Google Authenticator app on your phone.',
        'download_app' => 'You can download the app here:',
        'store_android' => 'Google Play Store (Android)',
        'store_ios' => 'Apple App Store (iOS)',
        'follow_steps' => 'To enable 2FA, follow the instructions below.',
        'enabled_description' => 'With two-factor authentication enabled, you will be prompted for a secure, random token during authentication, which you can retrieve from your phone\'s Google Authenticator app.',
        'dialog_title_enable' => 'Enable Two-Step Verification',
        'dialog_title_verify' => 'Verify Authentication Code',
        'dialog_desc_enable' => 'Open your authenticator app and select "Scan a QR code"',
        'dialog_desc_verify' => 'Enter the 6-digit code from your authenticator app',
        'continue' => 'Continue',
        'manual_code' => 'or enter the code manually',
        'back' => 'Back',
        'confirm' => 'Confirm',
        'recovery_codes_title' => '2FA Recovery Codes',
        'recovery_codes_desc' => 'Recovery codes allow you to regain access if you lose your 2FA device. Store them in a secure password manager or print and keep them in a safe place.',
        'show_recovery_codes' => 'Show Recovery Codes',
        'hide_recovery_codes' => 'Hide Recovery Codes',
        'regenerate_codes' => 'Regenerate Codes',
        'remaining_codes' => 'You have :count recovery codes remaining. Each code can only be used once to access your account and will be removed after use. If you need new codes, click "Regenerate Codes" above.',
        'invalid_code' => 'Invalid verification code',
        'confirm_error' => 'An error occurred while confirming two-factor authentication',
    ],

    'delete_user' => [
        'title' => 'Delete Account',
        'description' => 'Delete your account and all associated data',
        'warning_title' => 'Warning',
        'warning_description' => 'Proceed with caution, this action is irreversible.',
        'button' => 'Delete Account',
        'confirm_title' => 'Are you sure you want to delete your account?',
        'confirm_description' => 'Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
        'password' => 'Password',
        'password_placeholder' => 'Password',
        'cancel' => 'Cancel',
    ],

    'notification_types' => [
        'new_communication' => [
            'label' => 'New noticeboard communication',
            'description' => 'Receive a notification when a new communication is created',
        ],
        'approved_communication' => [
            'label' => 'Noticeboard communication approved',
            'description' => 'Receive a notification when your communication is approved',
        ],
        'updated_communication' => [
            'label' => 'Comunicación del tablón actualizada',
            'description' => 'Recibe una notificación cuando se modifica una comunicación que ya has recibido',
        ],
        'new_ticket' => [
            'label' => 'New fault report',
            'description' => 'Receive a notification when a new fault report is created',
        ],
        'approved_ticket' => [
            'label' => 'Fault report approved',
            'description' => 'Receive a notification when your fault report is approved',
        ],
        'updated_ticket' => [
            'label' => 'Incidencia actualizada',
            'description' => 'Recibe una notificación cuando se modifica una incidencia que sigues',
        ],
        'new_archive_document' => [
            'label' => 'New document in archive',
            'description' => 'Receive a notification when a new document is published in the archive',
        ],
        'approved_archive_document' => [
            'label' => 'Archive document approved',
            'description' => 'Receive a notification when your archive document is approved',
        ],
        'updated_archive_document' => [
            'label' => 'Documento del archivo actualizado',
            'description' => 'Recibe una notificación cuando se modifica un documento que ya has recibido',
        ],
        'new_comment' => [
            'label' => 'New comment',
            'description' => 'Receive a notification when a comment is added to a report you are participating in',
        ],
        'comment_approved' => [
            'label' => 'Comment approved',
            'description' => 'Receive a notification when your pending comment is approved',
        ],
        'comment_deleted' => [
            'label' => 'Comment deleted or hidden',
            'description' => 'Receive a notification when your comment is deleted or hidden',
        ],
        'new_user' => [
            'label' => 'New registered user',
            'description' => 'Receive a notification when a new user registers',
        ],
        'comment_under_moderation' => [
            'label' => 'Comment to be moderated',
            'description' => 'Receive a notification when a new comment requires your approval',
        ],
    ],
];
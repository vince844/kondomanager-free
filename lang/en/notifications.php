<?php

return [
    // Notification for new registered users (RegisteredUserNotification)
    'new_user_registered' => [
        'subject'   => 'New user registered',
        'greeting'  => 'Hello :name',
        'line_1'    => 'A new user has registered on the portal. After they confirm their email address, they will be able to access the private area.',
        'line_2'    => 'Make sure to associate the profile with one or more buildings if you want the user to be able to view their data.',
        'action'    => 'Access the portal',
    ],
    
    // Reset password (CustomResetPasswordNotification)
    'reset_password' => [
        'subject'   => 'Password Reset Notification',
        'greeting'  => 'Hello!',
        'line_1'    => 'You are receiving this email because we received a password reset request for your account.',
        'action'    => 'Reset Password',
        'line_2'    => 'This reset link will expire in :count minutes.',
        'line_3'    => 'If you did not request a password reset, please ignore this email.',
    ],
    
    // Email verification (CustomVerifyEmailNotification)
    'verify_email' => [
        'subject'   => 'Verify Email Address',
        'greeting'  => 'Hello',
        'line_1'    => 'Please click the button below to verify your email address.',
        'action'    => 'Verify Email Address',
        'line_2'    => 'If you did not create an account, please ignore this email.',
    ],
    
    // User invitation (InviteUserNotification)
    'invite_user' => [
        'subject'   => 'Welcome to :appName',
        'line_1'    => 'The condominium administrator has invited you to register your online account.',
        'action'    => 'Register now',
        'line_2'    => 'This invitation will expire in three days.',
        'expired'   => 'This invitation has expired. Ask the administrator to send you a new one.',
        'already_used' => 'This invitation has already been used. Log in with your account.',
        'account_exists' => 'An account with this email already exists: log in, or reset your password if you do not remember it.',
    ],
    
    // New user created by admin (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Welcome to :appName',
        'greeting'  => 'Hello :name,',
        'line_1'    => 'The condominium administrator has created your profile. Click the following link to set your password.',
        'action'    => 'Set password',
        'line_2'    => 'This link will expire in three days.',
        'password_already_set' => 'Your password has already been set. Please log in with your credentials.',
        'password_set' => 'Your password is set. You can now log in.',
        'link_expired' => 'The invitation link has expired or is no longer valid. Please contact the administrator to receive a new invitation.',
    ],

    // Communication pending approval (ApproveComunicazioneNotification)
    'approve_communication' => [
        'subject'   => 'New communication pending approval',
        'greeting'  => 'Hello :name',
        'line_1'    => 'User :user has created a new communication on the condominium notice board.',
        'line_2'    => 'The communication is pending approval because the user who submitted it does not have sufficient permissions to publish it.',
        'object'    => 'Subject',
        'priority'  => 'Priority',
        'action'    => 'View communication',
    ],

    // Communication approved (ApprovedComunicazioneNotification)
    'approved_communication' => [
        'subject'   => 'Communication approved',
        'greeting'  => 'Hello :name',
        'line_1'    => 'User :user has approved the communication on the condominium notice board.',
        'object'    => 'Subject',
        'priority'  => 'Priority',
        'action'    => 'View communication',
    ],

    // New communication published (NewComunicazioneNotification)
    'new_communication' => [
        'subject'   => 'New communication on the notice board',
        'greeting'  => 'Hello :name',
        'line_1'    => 'User :user has created a new communication on the condominium notice board.',
        'object'    => 'Subject',
        'priority'  => 'Priority',
        'action'    => 'View communication',
    ],

    // Document approved (ApprovedDocumentoNotification)
    'approved_document' => [
        'subject'     => 'Document approved',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has approved the document in the condominium archive.',
        'title'       => 'Title',
        'description' => 'Description',
        'action'      => 'View documents',
    ],

    // Document approval (ApproveDocumentoNotification)
    'approve_document' => [
        'subject'     => 'New document pending approval',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has created a new document in the condominium archive.',
        'line_2'      => 'The document is waiting for approval because the user who submitted it does not have sufficient permissions to publish it.',
        'title'       => 'Title',
        'description' => 'Description',
        'action'      => 'View documents',
    ],

    // New document published (NewDocumentoNotification)
    'new_document' => [
        'subject'     => 'New document in the archive',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has published a new document in the condominium archive.',
        'title'       => 'Title',
        'description' => 'Description',
        'action'      => 'View documents',
    ],

    // Report approved (ApprovedSegnalazioneNotification)
    'approved_ticket' => [
        'subject'     => 'New fault report approved',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has approved the fault report.',
        'object'      => 'Subject',
        'priority'    => 'Priority',
        'action'      => 'View report',
    ],

    // Segnalazione privata resa pubblica (SegnalazioneResaPubblicaNotification)
    'ticket_made_public' => [
        'subject'  => 'Your report is now public',
        'subject_pending' => 'Your report is no longer private',
        'greeting' => 'Hello :name',
        'line_1'   => ':user has made your report public.',
        'line_1_pending' => ':user has decided that your report is no longer private.',
        'object'   => 'Subject',
        'line_2'   => 'All the residents of :condominio can now see it, with your name.',
        'line_2_pending' => 'All the residents of :condominio will see it, with your name, once it is published and approved.',
        'action'   => 'View report',
    ],

    // Why an email to the author has no button to the page (PulsanteSoloSeLaApre)
    'author_cannot_open' => [
        'outside_building' => 'You can no longer see it in the portal: you are not listed among the residents of :condominio.',
        'no_permission'    => 'You cannot see it in the portal: your account does not have permission to open it.',
        'not_published'    => 'You cannot see it in the portal: it is not published and approved at the moment.',
        'not_addressed'    => 'You can no longer see it in the portal: it is no longer addressed to you.',
        'no_anagrafica'    => 'You cannot see it in the portal: your account is not linked to a resident record. To use the portal, sign in and fill in your details.',
    ],

    // Ticket approval (ApproveSegnalazioneNotification)
    'approve_ticket' => [
        'subject'     => 'New ticket pending approval',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has created a new fault ticket for the condominium.',
        'line_2'      => 'The ticket is waiting for approval because the user who submitted it does not have sufficient permissions to publish it.',
        'object'      => 'Subject',
        'priority'    => 'Priority',
        'status'      => 'Status',
        'action'      => 'View ticket',
    ],

    // New ticket (NewSegnalazioneNotification)
    'new_ticket' => [
        'subject'     => 'New fault ticket',
        'greeting'    => 'Hello :name',
        'line_1'      => 'User :user has created a new fault ticket.',
        'object'      => 'Subject',
        'priority'    => 'Priority',
        'status'      => 'Status',
        'action'      => 'View ticket',
    ],

    // New comment
    'new_ticket_comment' => [
        'subject'  => 'New comment on: :entity',
        'greeting' => 'Hello!',
        'line_1'   => ':user has left a new comment:',
        'action'   => 'View the report',
        'line_2'   => 'You are receiving this email because you are involved in this report.',
    ],

    // Approved comment
    'approved_ticket_comment' => [
        'subject' => 'Your comment has been approved',
        'line_1'  => 'Your comment on the :entity has been approved and published.',
        'action'  => 'View the report',
    ],

    // Pending comment
    'pending_ticket_comment' => [
        'subject' => 'New comment pending approval on :entity',
        'line_1'  => ':user wrote a comment on ":title" that requires approval.',
        'line_2'  => 'Log in to approve or reject the comment.',
        'action'  => 'View the report',
    ],

    // Deleted comment
    'deleted_ticket_comment' => [
        'subject' => 'Your comment has been removed',
        'line_1'  => 'Your comment on the :entity has been removed or hidden by an administrator.',
    ],

    // Common strings for all notifications
    'common' => [
        'regards'             => 'Best regards',
        'copyright'           => 'All rights reserved.',
        'trouble_with_button' => 'If you\'re having trouble clicking the ":actionText" button, copy and paste the URL below into your browser:',
        'no_reply'            => 'Please do not reply to this email.',
        'auto_generated'      => 'This is an automatically generated email.',
    ],

    // ─── Update notices (beta.64) ──────────────────────────────────────────────
    'updated_communication' => [
        'subject'   => 'Notice board communication updated',
        'greeting'  => 'Hello :name',
        'line_1'    => 'The communication was updated by :user.',
        'object'    => 'Subject',
        'priority'  => 'Priority',
        'action'    => 'View communication',
    ],

    'updated_ticket' => [
        'subject'   => 'Maintenance ticket updated',
        'greeting'  => 'Hello :name',
        'line_1'    => 'The ticket was updated by :user.',
        'object'    => 'Subject',
        'status'    => 'Status',
        'action'    => 'View ticket',
    ],

    'updated_document' => [
        'subject'     => 'Archive document updated',
        'greeting'    => 'Hello :name',
        'line_1'      => "The document was updated by :user in the building's archive.",
        'title'       => 'Title',
        'description' => 'Description',
        'action'      => 'View documents',
    ],

    // ─── Security alerts to the account holder (Coda 267) ──────────────────────
    'security_alert' => [
        'greeting' => 'Hello :name,',
        'autore'   => 'The change was made by :autore.',
        'sempre'   => 'This alert is sent for every change to the security of your account and cannot be turned off.',
        'password_cambiata' => [
            'subject'   => 'Your account password has been changed',
            'line_1'    => 'On :data at :ora the password of your :appName account was changed.',
            'consiglio' => 'If you did not make this change, reset your password right away with «Password dimenticata?» on the login page and tell the administrator.',
        ],
        'password_reimpostata' => [
            'subject'   => 'Your account password has been reset',
            'line_1'    => 'On :data at :ora the password of your :appName account was reset with the «Password dimenticata?» link.',
            'consiglio' => 'If you did not make this change, reset your password right away with «Password dimenticata?» on the login page and tell the administrator.',
        ],
        'email_cambiata' => [
            'subject'   => 'The email address of your account has been changed',
            'line_1'    => 'On :data at :ora the email address of your :appName account was changed to :email. From now on the new address is used to log in, and password reset links are sent there.',
            'consiglio' => 'If you did not ask for this change, contact the administrator right away.',
        ],
        'due_fattori_attivata' => [
            'subject'   => 'Two-factor authentication turned on',
            'line_1'    => 'On :data at :ora two-factor authentication was turned on for your :appName account.',
            'consiglio' => 'If you did not make this change, change your password right away and tell the administrator.',
        ],
        'due_fattori_disattivata' => [
            'subject'   => 'Two-factor authentication turned off',
            'line_1'    => 'On :data at :ora two-factor authentication was turned off for your :appName account: the password alone is now enough to log in.',
            'consiglio' => 'If you did not make this change, change your password right away, turn two-factor authentication back on and tell the administrator.',
        ],
        'codici_rigenerati' => [
            'subject'   => 'New recovery codes',
            'line_1'    => 'On :data at :ora new two-factor recovery codes were generated for your :appName account: the previous ones no longer work.',
            'consiglio' => 'If you did not make this change, change your password right away and tell the administrator.',
        ],
    ],
];

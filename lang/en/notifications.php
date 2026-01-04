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
    ],
    
    // New user created by administrator (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Welcome to :appName',
        'greeting'  => 'Hello :name,',
        'line_1'    => 'The condominium administrator has created your profile. Click the link below to set your password.',
        'action'    => 'Set Password',
        'line_2'    => 'This link will expire in 60 minutes.',
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

    // Common strings for all notifications
    'common' => [
        'regards'             => 'Best regards',
        'copyright'           => 'All rights reserved.',
        'trouble_with_button' => 'If you\'re having trouble clicking the ":actionText" button, copy and paste the URL below into your browser:',
        'no_reply'            => 'Please do not reply to this email.',
        'auto_generated'      => 'This is an automatically generated email.',
    ],
];
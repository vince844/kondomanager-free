<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Errors Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during HTTP errors for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    '403' => [
        'invalid_signature' => 'The link you are trying to use is expired or invalid.',
        'account_suspended' => 'Your account is temporarily suspended. Please contact the system administrator to reactivate it.',
    ],

    '403_title'         => '403 - Access Denied',
    '403_heading'       => '403 - Access Denied',
    '403_message'       => 'You do not have permission to access this page.',
    '404_title'         => '404 - Page Not Found',
    '404_heading'       => '404 - Page Not Found',
    '404_message'       => 'The page you are looking for does not exist or has been removed.',
    '500_title'         => '500 - Server Error',
    '500_heading'       => '500 - Internal Server Error',
    '500_message'       => 'An unexpected error occurred. Please try again later.',
    'back_to_dashboard' => 'Back to Dashboard',
    'back_to_login'     => 'Back to Login',
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed'                    => 'The credentials you entered are incorrect.',
    'password'                  => 'The password you entered is incorrect.',
    'throttle'                  => 'Too many failed attempts. Please try again in :seconds seconds.',
    'reset_link_sent'           => 'A reset link will be sent to the email address if an associated account exists.',
    'not_authenticated'         => 'The user has not been authenticated or an associated profile is missing.',
    'missing_2fa_code'          => 'Please enter a valid two-factor authentication code.',
    'invalid_2fa_code'          => 'The provided two-factor authentication code is invalid.',
    'invalid_2fa_recovery_code' => 'The provided two-factor authentication recovery code is invalid.',
    'too_many_2fa_attempts'     => 'Too many two-factor authentication attempts. Try again in :seconds seconds.',
    
    /* ------------------------------------------------------------------
     | Headers, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'login' => [
            'head'        => 'Login',
            'title'       => 'Sign in to your account',
            'description' => 'Enter your email and password to sign in',
        ],
        'forgot_password' => [
            'head'        => 'Password reset',
            'title'       => 'Reset password',
            'description' => 'Enter your email address to receive the password reset link',
        ],
        'confirm_password' => [
            'head'        => 'Confirm password',
            'title'       => 'Confirm your password',
            'description' => 'This is a secure area. Please confirm your password before continuing.',
        ],
        'register' => [
            'head'        => 'Register',
            'title'       => 'Create an account',
            'description' => 'Enter your details to create a new account',
        ],
        'reset_password' => [
            'head'        => 'Password reset',
            'title'       => 'Reset password',
            'description' => 'Please enter your new password',
        ],
        'two_factor_challenge' => [
            'head'                             => 'Two-factor authentication',
            'title_authentication_code'        => 'Authentication code',
            'title_recovery_code'              => 'Recovery code',
            'description_authentication_code'  => 'Enter the authentication code provided by your authentication application.',
            'description_recovery_code'        => 'Confirm access to your account by entering one of your emergency recovery codes.',
        ],
        'verification_notice' => [
            'head'        => 'Email verification',
            'title'       => 'Verify email address',
            'description' => 'Please verify your email address by clicking the link we sent to your email.',
        ],
        'invitation_register' => [
            'head'        => 'Register',
            'title'       => 'Create an account',
            'description' => 'Enter your details to create a new account',
        ],
        'set_password' => [
            'head'        => 'Set password',
            'title'       => 'Reset password',
            'description' => 'Please set a new password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'login' => [
            'email'    => 'Email address',
            'password' => 'Password',
            'remember' => 'Remember me',
        ],
        'confirm_password' => [
            'password' => 'Password',
        ],
        'register' => [
            'name'                  => 'Full name',
            'email'                 => 'Email address',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'reset_password' => [
            'email'                 => 'Email address',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'two_factor_challenge' => [
            'code'          => 'Authentication code',
            'recovery_code' => 'Recovery code',
        ],
        'invitation_register' => [
            'name'                  => 'Full name',
            'email'                 => 'Email address',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'set_password' => [
            'email'                 => 'Email address',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Buttons
     | ------------------------------------------------------------------ */
    'button' => [
        'login'                => 'Sign in',
        'forgot_password'      => 'Send reset link',
        'confirm_password'     => 'Confirm password',
        'register'             => 'Create account',
        'reset_password'       => 'Reset password',
        'two_factor_challenge' => 'Continue',
        'verification_notice'  => 'Resend verification email',
        'logout'               => 'Log out',
        'invitation_register'  => 'Create account',
        'set_password'         => 'Save password',
    ],
    
    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'login' => [
            'email'    => 'example@email.com',
            'password' => 'Enter your password',
        ],
        'confirm_password' => [
            'password' => 'Enter your password',
        ],
        'register' => [
            'name'                  => 'Full name',
            'email'                 => 'email@example.com',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'reset_password' => [
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'two_factor_challenge' => [
            'recovery_code' => 'Enter recovery code',
        ],
        'invitation_register' => [
            'name'                  => 'Full name',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
        'set_password' => [
            'password'              => 'Password',
            'password_confirmation' => 'Confirm password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Links
     | ------------------------------------------------------------------ */
    'link' => [
        'forgot_password' => 'Forgot password?',
        'register'        => 'Register',
        'no_account'      => 'Don’t have an account yet?',
        'back_to_login'   => 'log in',
        'or_back_to_login'=> 'Or, go back to',
        'have_account'    => 'Already have an account?',
        'login'           => 'Sign in',
        'logout'          => 'Log out',
        'two_factor_challenge' => [
            'toggle_authentication_code' => 'sign in using an authentication code',
            'toggle_recovery_code'       => 'sign in using a recovery code',
            'or'                         => 'or you can',
        ],
    ],

    /* ------------------------------------------------------------------
     | Status Messages
     | ------------------------------------------------------------------ */
    'status' => [
        'verification_link_sent' => 'A new verification link has been sent to the email address used during registration.',
        'verification_required'  => 'Verify your email address to continue.',
        'already_verified'       => 'Your email address has already been verified.',
    ],
];

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

    'failed'                    => 'Le credenziali che hai inserito non sono corrette.',
    'password'                  => 'La password che hai inserito non è corretta.',
    'throttle'                  => 'Troppi tentativi falliti. Ti preghiamo di riprovare tra :seconds secondi.',
    'reset_link_sent'           => 'Un link di ripristino verrà inviato all\'indirizzo email se esiste un account associato.',
    'not_authenticated'         => 'L\'utente non è stato autenticato oppure manca un\'anagrafica associata.',
    'missing_2fa_code'          => 'Per favore inserisci un codice di autenticazione a due fattori valido.',
    'invalid_2fa_code'          => 'Il codice di autenticazione a due fattori fornito non è valido.',
    'invalid_2fa_recovery_code' => 'Il codice di recupero dell\'autenticazione a due fattori fornito non è valido.',
    'too_many_2fa_attempts'     => 'Troppi tentativi di autenticazione a due fattori. Riprova tra :seconds secondi.',
    
    /* ------------------------------------------------------------------
     | Headers, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'login' => [
            'head'        => 'Login',
            'title'       => 'Accedi al tuo account',
            'description' => 'Inserisci la tua email e password per accedere',
        ],
        'forgot_password' => [
            'head'        => 'Ripristino password',
            'title'       => 'Ripristina password',
            'description' => 'Inserisci il tuo indirizzo email per ricevere il link per il ripristino della password',
        ],
        'confirm_password' => [
            'head'        => 'Conferma password',
            'title'       => 'Conferma la tua password',
            'description' => 'Questa è un\'area sicura. Per favore conferma la tua password prima di continuare.',
        ],
        'register' => [
            'head'        => 'Registrati',
            'title'       => 'Crea un account',
            'description' => 'Inserisci i tuoi dettagli per creare un nuovo account',
        ],
        'reset_password' => [
            'head'        => 'Reset password',
            'title'       => 'Reimposta password',
            'description' => 'Per favore inserisci la tua nuova password',
        ],
        'two_factor_challenge' => [
            'head'                         => 'Autenticazione a Due Fattori',
            'title_authentication_code'    => 'Codice di Autenticazione',
            'title_recovery_code'          => 'Codice di Recupero',
            'description_authentication_code' => 'Inserisci il codice di autenticazione fornito dalla tua applicazione di autenticazione.',
            'description_recovery_code'    => 'Conferma l\'accesso al tuo account inserendo uno dei tuoi codici di recupero di emergenza.',
        ],
        'verification_notice' => [
            'head'        => 'Verifica email',
            'title'       => 'Verifica indirizzo email',
            'description' => 'Per favore verifica il tuo indirizzo email cliccando sul link che abbiamo inviato al tuo indirizzo email.',
        ],
        'invitation_register' => [
            'head'        => 'Registrati',
            'title'       => 'Crea un account',
            'description' => 'Inserisci i tuoi dettagli per creare un nuovo account',
        ],
        'set_password' => [
            'head'        => 'Imposta password',
            'title'       => 'Reimposta password',
            'description' => 'Per favore imposta una nuova password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'login' => [
            'email'    => 'Indirizzo email',
            'password' => 'Password',
            'remember' => 'Ricordami',
        ],
        'confirm_password' => [
            'password' => 'Password',
        ],
        'register' => [
            'name'                  => 'Nome e cognome',
            'email'                 => 'Indirizzo email',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'reset_password' => [
            'email'                 => 'Indirizzo email',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'two_factor_challenge' => [
            'code'          => 'Codice di autenticazione',
            'recovery_code' => 'Codice di recupero',
        ],
        'invitation_register' => [
            'name'                  => 'Nome e cognome',
            'email'                 => 'Indirizzo email',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'set_password' => [
            'email'                 => 'Indirizzo email',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Buttons
     | ------------------------------------------------------------------ */
    'button' => [
        'login'               => 'Accedi',
        'forgot_password'     => 'Invia link di ripristino',
        'confirm_password'    => 'Conferma password',
        'register'            => 'Crea account',
        'reset_password'      => 'Reimposta password',
        'two_factor_challenge' => 'Continua',
        'verification_notice' => 'Reinvia email di verifica',
        'logout'              => 'Log out',
        'invitation_register' => 'Crea account',
        'set_password'        => 'Salva password',
    ],
    
    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'login' => [
            'email'    => 'esempio@email.com',
            'password' => 'Inserisci la tua password',
        ],
        'confirm_password' => [
            'password' => 'Inserisci la tua password',
        ],
        'register' => [
            'name'                  => 'Nome e cognome',
            'email'                 => 'email@example.com',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'reset_password' => [
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'two_factor_challenge' => [
            'recovery_code' => 'Inserisci il codice di recupero',
        ],
        'invitation_register' => [
            'name'                  => 'Nome e cognome',
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
        'set_password' => [
            'password'              => 'Password',
            'password_confirmation' => 'Conferma password',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Links
     | ------------------------------------------------------------------ */
    'link' => [
        'forgot_password' => 'Password dimenticata?',
        'register'        => 'Registrati',
        'no_account'      => 'Non hai ancora un account?',
        'back_to_login'   => 'log in',
        'or_back_to_login' => 'Oppure, ritorna al',
        'have_account'    => 'Hai già creato un account?',
        'login'           => 'Accedi',
        'logout'          => 'Log out',
        'two_factor_challenge' => [
            'toggle_authentication_code' => 'accedere usando un codice di autenticazione',
            'toggle_recovery_code'       => 'accedere usando un codice di recupero',
            'or'                         => 'oppure puoi',
        ],
    ],

    /* ------------------------------------------------------------------
     | Status Messages
     | ------------------------------------------------------------------ */
    'status' => [
        'verification_link_sent' => 'Un nuovo link di verifica è stato inviato all\'indirizzo email usato per la registrazione sul portale.',
        'verification_required'  => 'Verifica il tuo indirizzo email per continuare.',
        'already_verified'       => 'Il tuo indirizzo email è già stato verificato.',
    ],
];
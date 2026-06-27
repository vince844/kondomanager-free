<?php

return [
    'layout' => [
        'title' => 'Impostazioni',
        'description' => 'Gestione del tuo profilo e delle impostazioni dell\'account',
        'nav' => [
            'profile' => 'Profilo',
            'password' => 'Password',
            'two_factor' => 'Sicurezza 2FA',
            'notifications' => 'Notifiche',
            'appearance' => 'Aspetto',
        ],
    ],

    'appearance' => [
        'title' => 'Impostazioni aspetto',
        'description' => 'Aggiorna le impostazioni relative all\'aspetto dell\'applicazione',
        'tabs' => [
            'light' => 'Chiaro',
            'dark' => 'Scuro',
            'system' => 'Sistema',
        ],
    ],

    'profile' => [
        'title'                 => 'Impostazioni profilo',
        'heading'               => 'Informazioni profilo',
        'description'           => 'Aggiorna il tuo nome o indirizzo email',
        'name'                  => 'Nome e cognome',
        'name_placeholder'      => 'Inserisci il tuo nome e cognome',
        'email'                 => 'Indirizzo email',
        'email_placeholder'     => 'Inserisci il tuo indirizzo email',
        'email_unverified'      => 'Il tuo indirizzo email non è verificato',
        'resend_verification'   => 'Clicca qui per ricevere una nuova eamil di verifica',
        'verification_sent'     => 'Un nuovo link di verifica è stato inviato al tuo indirizzo email',
        'save'                  => 'Salva profilo',
        'saved'                 => 'Profilo salvato',
    ],

    'password' => [
        'title'                         => 'Impostazioni password',
        'heading'                       => 'Aggiorna la tua password',
        'description'                   => 'Assicurati di utilizzare una password lunga e casuale per rimanere sicuro',
        'current_password'              => 'Password corrente',
        'current_password_placeholder'  => 'Password corrente',
        'new_password'                  => 'Nuova password',
        'new_password_placeholder'      => 'Inserisci nuova password',
        'confirm_password'              => 'Conferma password',
        'confirm_password_placeholder'  => 'Conferma la nuova password',
        'save'                          => 'Salva password',
        'saved'                         => 'Password salvata',
    ],

    'notifications' => [
        'title' => 'Impostazioni notifiche',
        'heading' => 'Impostazioni notifiche',
        'description' => 'Seleziona di seguito le notifiche via email che desideri ricevere',
        'empty' => 'Non ci sono notifiche email disponibili da selezionare.',
        'save' => 'Salva preferenze',
    ],

    'two_factor' => [
        'title'                 => 'Autenticazione a due fattori',
        'heading'               => 'Autenticazione a due fattori',
        'description'           => 'Gestione delle impostazioni per l\'autenticazione a due fattori (2FA)',
        'disabled'              => 'Disabilitato',
        'enabled'               => 'Attivato',
        'enable'                => 'Attiva',
        'disable'               => 'Disattiva 2FA',
        'intro'                 => 'Quando abiliti l\'autenticazione a due fattori (2FA), dovrai inserire un codice di sicurezza al momento dell\'accesso. Puoi ottenere questo codice dall\'app Google Authenticator sul tuo telefono cellulare.',
        'download_app'          => 'Puoi scaricare l\'applicazione Google Authenticator da qui:',
        'store_android'         => 'Google Play Store (Sistema Android)',
        'store_ios'             => 'Apple App Store (Sistema iOS)',
        'follow_steps'          => 'Per abilitare l\'autenticazione a due fattori (2FA), clicca sul pulsante attiva e segui le istruzioni guidate',
        'enabled_description'   => 'Con l\'autenticazione a due fattori abilitata, ti verrà richiesto un token sicuro e casuale durante l\'accesso, che potrai recuperare dall\'app Google Authenticator.',
        'dialog_title_enable'   => 'Attiva la verifica in due passaggi',
        'dialog_title_verify'   => 'Verifica il codice di autenticazione',
        'dialog_desc_enable'    => 'Apri la tua app di autenticazione e scegli scansiona codice QR',
        'dialog_desc_verify'    => 'Inserisci il codice a 6 cifre dalla tua app di autenticazione',
        'continue'              => 'Continua',
        'manual_code'           => 'oppure, inserisci il codice manualmente',
        'back'                  => 'Indietro',
        'confirm'               => 'Conferma',
        'recovery_codes_title'  => 'Codici di recupero 2FA',
        'recovery_codes_desc'   => 'I codici di recupero ti permettono di riottenere l\'accesso nel caso in cui tu perda il dispositivo utilizzato per la 2FA. Conservali in un gestore di password sicuro o stampali e conservali in un luogo sicuro.',
        'show_recovery_codes'   => 'Visualizza codici ripristino',
        'hide_recovery_codes'   => 'Nascondi codici ripristino',
        'regenerate_codes'      => 'Rigenera codici',
        'remaining_codes'       => 'Ti restano :count codici di recupero. Ogni codice può essere utilizzato una sola volta per accedere al tuo account e verrà rimosso dopo l\'uso. Se hai bisogno di altri codici, clicca su Rigenera Codiciqui sopra.',
        'invalid_code'          => 'Codice di verifica non valido',
        'confirm_error'         => 'Si è verificato un errore nella conferma dell\'autenticazione a due fattori',
    ],

    'delete_user' => [
        'title' => 'Elimina account',
        'description' => 'Elimina il tuo account e tutti i dati associati',
        'warning_title' => 'Attenzione',
        'warning_description' => 'Procedi con cautela, questa operazione è irreversibile.',
        'button' => 'Elimina account',
        'confirm_title' => 'Sei sicuro di voler eliminare il tuo account?',
        'confirm_description' => 'Una volta eliminato l\'account, tutti i dati associati verranno rimossi in modo permanente. Inserisci la tua password per confermare l\'eliminazione.',
        'password' => 'Password',
        'password_placeholder' => 'Password',
        'cancel' => 'Annulla',
    ],

    'notification_types' => [
        'new_communication' => [
            'label' => 'Nuova comunicazione bacheca',
            'description' => 'Ricevi una notifica quando viene creata una nuova comunicazione',
        ],
        'approved_communication' => [
            'label' => 'Comunicazione bacheca approvata',
            'description' => 'Ricevi una notifica quando viene approvata la comunicazione da te inviata',
        ],
        'new_ticket' => [
            'label' => 'Nuova segnalazione guasto',
            'description' => 'Ricevi una notifica quando viene creata una nuova segnalazione guasto',
        ],
        'approved_ticket' => [
            'label' => 'Segnalazione guasto approvata',
            'description' => 'Ricevi una notifica quando viene approvata la segnalazione guasto da te inviata',
        ],
        'new_archive_document' => [
            'label' => 'Nuovo documento in archivio',
            'description' => 'Ricevi una notifica quando viene pubblicato un nuovo documento in archivio',
        ],
        'approved_archive_document' => [
            'label' => 'Documento in archivio approvato',
            'description' => 'Ricevi una notifica quando viene approvato un documento in archivio da te inviato',
        ],
        'new_comment' => [
            'label' => 'Nuovo commento',
            'description' => 'Ricevi una notifica quando viene aggiunto un commento a una segnalazione a cui partecipi',
        ],
        'comment_approved' => [
            'label' => 'Commento approvato',
            'description' => 'Ricevi una notifica quando un tuo commento in attesa viene approvato',
        ],
        'comment_deleted' => [
            'label' => 'Commento eliminato o nascosto',
            'description' => 'Ricevi una notifica quando un tuo commento viene eliminato o nascosto',
        ],
        'new_user' => [
            'label' => 'Nuovo utente registrato',
            'description' => 'Ricevi una notifica quando un nuovo utente si registra',
        ],
        'comment_under_moderation' => [
            'label' => 'Commento da moderare',
            'description' => 'Ricevi una notifica quando un nuovo commento richiede la tua approvazione',
        ],
    ],
];
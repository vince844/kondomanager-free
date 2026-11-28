<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
        // Coda 261: la password attuale, solo quando l'email cambia.
        'current_password' => 'Password attuale',
        'current_password_placeholder' => 'Inserisci la password attuale',
        'current_password_hint' => "Per cambiare l'indirizzo email serve la password attuale.",
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
        'enable_all' => 'Attiva tutte',
        'disable_all' => 'Disattiva tutte',
        'counter' => ':attive attive su :totali',
    ],

    'two_factor' => [
        // Coda 261: `enable` con la 2FA già confermata.
        'already_enabled' => "L'autenticazione a due fattori è già attiva: per cambiarla, prima disattivala.",
        'obbligatoria_per_il_pannello' => 'Lo studio chiede la verifica in due passaggi a chi entra nel pannello: attivala per continuare.',
        'disattivazione_bloccata' => 'Lo studio chiede la verifica in due passaggi a chi entra nel pannello: non si può disattivare.',
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
        'updated_communication' => [
            'label' => 'Comunicazione bacheca aggiornata',
            'description' => 'Ricevi una notifica quando una comunicazione che hai già ricevuto viene modificata',
        ],
        'scheduled_communication_published' => [
            'label' => 'Comunicazione programmata pubblicata',
            'description' => 'Ricevi una conferma quando una comunicazione che hai programmato viene pubblicata',
        ],
        'new_ticket' => [
            'label' => 'Nuova segnalazione guasto',
            'description' => 'Ricevi una notifica quando viene creata una nuova segnalazione guasto',
        ],
        'approved_ticket' => [
            'label' => 'Segnalazione guasto approvata',
            'description' => 'Ricevi una notifica quando viene approvata la segnalazione guasto da te inviata',
        ],
        'updated_ticket' => [
            'label' => 'Segnalazione guasto aggiornata',
            'description' => 'Ricevi una notifica quando una segnalazione guasto che segui viene modificata',
        ],
        'error_reported' => [
            'label' => 'Errore avvisato',
            'description' => 'Ricevi un\'email al primo avviso di un errore da un condòmino o da un collaboratore: il codice, l\'ora, la pagina e chi ha avvisato, senza il dettaglio tecnico. Gli avvisi dopo sullo stesso errore si contano nel compito della Action inbox, senza un\'altra email.',
        ],
        'ticket_assigned' => [
            'label' => 'Segnalazione guasto assegnata',
            'description' => 'Ricevi una notifica quando una segnalazione guasto ti viene assegnata, o non è più assegnata a te',
        ],
        'new_archive_document' => [
            'label' => 'Nuovo documento in archivio',
            'description' => 'Ricevi una notifica quando viene pubblicato un nuovo documento in archivio',
        ],
        'approved_archive_document' => [
            'label' => 'Documento in archivio approvato',
            'description' => 'Ricevi una notifica quando viene approvato un documento in archivio da te inviato',
        ],
        'updated_archive_document' => [
            'label' => 'Documento in archivio aggiornato',
            'description' => 'Ricevi una notifica quando un documento che hai già ricevuto viene modificato',
        ],
        'new_comment' => [
            'label' => 'Nuovo commento (segnalazioni)',
            'description' => 'Ricevi una notifica quando viene aggiunto un commento a una segnalazione a cui partecipi',
        ],
        'comment_approved' => [
            'label' => 'Commento approvato (segnalazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una segnalazione viene approvato',
        ],
        'comment_deleted' => [
            'label' => 'Commento nascosto (segnalazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una segnalazione viene nascosto',
        ],
        'comment_removed' => [
            'label' => 'Commento eliminato (segnalazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una segnalazione viene eliminato da un altro',
        ],
        'new_communication_comment' => [
            'label' => 'Nuovo commento (comunicazioni)',
            'description' => 'Ricevi una notifica quando viene aggiunto un commento a una comunicazione che ricevi',
        ],
        'communication_comment_approved' => [
            'label' => 'Commento approvato (comunicazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una comunicazione viene approvato',
        ],
        'communication_comment_deleted' => [
            'label' => 'Commento nascosto (comunicazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una comunicazione viene nascosto',
        ],
        'communication_comment_removed' => [
            'label' => 'Commento eliminato (comunicazioni)',
            'description' => 'Ricevi una notifica quando un tuo commento a una comunicazione viene eliminato da un altro',
        ],
        'new_user' => [
            'label' => 'Nuovo utente registrato',
            'description' => 'Ricevi una notifica quando un nuovo utente si registra',
        ],
        'comment_under_moderation' => [
            'label' => 'Commento da moderare (segnalazioni)',
            'description' => 'Ricevi una notifica quando un nuovo commento a una segnalazione richiede la tua approvazione',
        ],
        'comment_edited_under_moderation' => [
            'label' => 'Commento modificato da moderare (segnalazioni)',
            'description' => 'Ricevi una notifica quando un commento modificato a una segnalazione richiede la tua approvazione',
        ],
        'communication_comment_under_moderation' => [
            'label' => 'Commento da moderare (comunicazioni)',
            'description' => 'Ricevi una notifica quando un nuovo commento a una comunicazione richiede la tua approvazione',
        ],
        'communication_comment_edited_under_moderation' => [
            'label' => 'Commento modificato da moderare (comunicazioni)',
            'description' => 'Ricevi una notifica quando un commento modificato a una comunicazione richiede la tua approvazione',
        ],
        'new_attachment' => [
            'label' => 'Nuovo allegato',
            'description' => 'Ricevi una notifica quando vengono aggiunti file a una segnalazione a cui partecipi',
        ],
        'attachment_approved' => [
            'label' => 'Allegato approvato',
            'description' => 'Ricevi una notifica quando un tuo allegato in attesa viene approvato',
        ],
        'attachment_deleted' => [
            'label' => 'Allegato eliminato',
            'description' => 'Ricevi una notifica quando un tuo allegato viene eliminato da un altro',
        ],
        'attachment_under_moderation' => [
            'label' => 'Allegato da approvare',
            'description' => 'Ricevi una notifica quando un nuovo allegato richiede la tua approvazione',
        ],
    ],
];
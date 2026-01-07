<?php

return [
    // Notifica per nuovi utenti registrati (RegisteredUserNotification)
    'new_user_registered' => [
        'subject'   => 'Nuovo utente registrato',
        'greeting'  => 'Salve :name',
        'line_1'    => 'Un nuovo utente si è registrato sul portale. Dopo che avrà confermato il suo indirizzo email potrà accedere all\'area privata.',
        'line_2'    => 'Assicurati di associare l\'anagrafica a uno o più condomini se vuoi permettere all\'utente di visualizzare i dati di questi.',
        'action'    => 'Accedi al portale',
    ],
    
    // Reset password (CustomResetPasswordNotification)
    'reset_password' => [
        'subject'   => 'Notifica di ripristino password',
        'greeting'  => 'Salve!',
        'line_1'    => 'Stai ricevendo questa email perché abbiamo ricevuto una richiesta di ripristino della password per il tuo account.',
        'action'    => 'Ripristina password',
        'line_2'    => 'Questo link di ripristino scadrà in :count minuti.',
        'line_3'    => 'Se non hai richiesto il ripristino della password, per favore ignora questa email.',
    ],
    
    // Verifica email (CustomVerifyEmailNotification)
    'verify_email' => [
        'subject'   => 'Verifica indirizzo email',
        'greeting'  => 'Salve',
        'line_1'    => 'Per favore clicca sul seguente pulsante per verificare il tuo indirizzo email.',
        'action'    => 'Verifica indirizzo email',
        'line_2'    => 'Se non hai creato un account, per favore ignora questa email.',
    ],
    
    // Invito utente (InviteUserNotification)
    'invite_user' => [
        'subject'   => 'Benvenuto su :appName',
        'line_1'    => 'L\'amministratore di condominio ti ha invitato a registrare il tuo account online.',
        'action'    => 'Registrati adesso',
        'line_2'    => 'Questo invito scadrà tra tre giorni.',
    ],
    
    // Nuovo utente creato dall'amministratore (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Benvenuto su :appName',
        'greeting'  => 'Salve :name,',
        'line_1'    => 'L\'amministratore di condominio ha creato il tuo profilo. Clicca sul seguente link per impostare la tua password.',
        'action'    => 'Imposta password',
        'line_2'    => 'Questo link scadrà in 60 minuti.',
    ],

    // Approvazione comunicazione (ApproveComunicazioneNotification)
    'approve_communication' => [
        'subject'   => 'Nuova comunicazione da approvare',
        'greeting'  => 'Salve :name',
        'line_1'    => 'L\'utente :user ha creato una nuova comunicazione nella bacheca del condominio.',
        'line_2'    => 'La comunicazione è in attesa di approvazione perché l\'utente che l\'ha inviata non ha permessi sufficienti per pubblicarla.',
        'object'    => 'Oggetto',
        'priority'  => 'Priorità',
        'action'    => 'Visualizza comunicazione',
    ],

    // Comunicazione approvata (ApprovedComunicazioneNotification)
    'approved_communication' => [
        'subject'   => 'Comunicazione approvata',
        'greeting'  => 'Salve :name',
        'line_1'    => 'L\'utente :user ha approvato la comunicazione nella bacheca del condominio.',
        'object'    => 'Oggetto',
        'priority'  => 'Priorità',
        'action'    => 'Visualizza comunicazione',
    ],

    // Nuova comunicazione pubblicata (NewComunicazioneNotification)
    'new_communication' => [
        'subject'   => 'Nuova comunicazione in bacheca',
        'greeting'  => 'Salve :name',
        'line_1'    => 'L\'utente :user ha creato una nuova comunicazione nella bacheca del condominio.',
        'object'    => 'Oggetto',
        'priority'  => 'Priorità',
        'action'    => 'Visualizza comunicazione',
    ],

    // Documento approvato (ApprovedDocumentoNotification)
    'approved_document' => [
        'subject'     => 'Nuovo documento approvato',
        'greeting'    => 'Salve :name',
        'line_1'      => "L'utente :user ha approvato il documento in archivio del condominio.",
        'title'       => 'Titolo',
        'description' => 'Descrizione',
        'action'      => 'Visualizza documenti',
    ],

    // Approvazione documento (ApproveDocumentoNotification)
    'approve_document' => [
        'subject'     => 'Nuovo documento archivio da approvare',
        'greeting'    => 'Salve :name',
        'line_1'      => "L'utente :user ha creato un nuovo documento in archivio del condominio.",
        'line_2'      => "Il documento è in attesa di essere approvato perché l'utente che l'ha inviato non ha permessi sufficienti per pubblicarlo.",
        'title'       => 'Titolo',
        'description' => 'Descrizione',
        'action'      => 'Visualizza documenti',
    ],

    // Nuovo documento pubblicato (NewDocumentoNotification)
    'new_document' => [
        'subject'     => 'Nuovo documento in archivio',
        'greeting'    => 'Salve :name',
        'line_1'      => "L'utente :user ha pubblicato un nuovo documento nell'archivio del condominio.",
        'title'       => 'Titolo',
        'description' => 'Descrizione',
        'action'      => 'Visualizza documenti',
    ],

    // Segnalazione approvata (ApprovedSegnalazioneNotification)
    'approved_ticket' => [
        'subject'     => 'Nuova segnalazione guasto approvata',
        'greeting'    => 'Salve :name',
        'line_1'      => 'L\'utente :user ha approvato la segnalazione guasto.',
        'object'      => 'Oggetto',
        'priority'    => 'Priorità',
        'action'      => 'Visualizza segnalazione',
    ],

    // Approvazione ticket (ApproveSegnalazioneNotification)
    'approve_ticket' => [
        'subject'     => 'Nuovo ticket da approvare',
        'greeting'    => 'Salve :name',
        'line_1'      => "L'utente :user ha creato un nuovo ticket guasto per il condominio.",
        'line_2'      => "Il ticket è in attesa di approvazione perché l'utente che l'ha inviato non ha permessi sufficienti per pubblicarlo.",
        'object'      => 'Oggetto',
        'priority'    => 'Priorità',
        'status'      => 'Stato',
        'action'      => 'Visualizza ticket',
    ],

    // Nuovo ticket (NewSegnalazioneNotification)
    'new_ticket' => [
        'subject'     => 'Nuovo ticket guasto',
        'greeting'    => 'Salve :name',
        'line_1'      => "L'utente :user ha creato un nuovo ticket guasto.",
        'object'      => 'Oggetto',
        'priority'    => 'Priorità',
        'status'      => 'Stato',
        'action'      => 'Visualizza ticket',
    ],

    // Stringhe comuni a tutte le notifiche
    'common' => [
        'regards'               => 'Cordiali saluti',
        'copyright'             => 'Tutti i diritti riservati.',
        'trouble_with_button'   => 'Se hai problemi a cliccare il pulsante ":actionText", copia e incolla l\'URL seguente nel tuo browser:',
        'no_reply'              => 'Si prega di non rispondere a questa email.',
        'auto_generated'        => 'Questa è un\'email generata automaticamente.',
    ],
];
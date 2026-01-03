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
        'app_name'  => config('app.name'),
    ],
    
    // Nuovo utente creato dall'amministratore (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Benvenuto su :appName',
        'greeting'  => 'Salve :name,',
        'line_1'    => 'L\'amministratore di condominio ha creato il tuo profilo. Clicca sul seguente link per impostare la tua password.',
        'action'    => 'Imposta password',
        'line_2'    => 'Questo link scadrà in 60 minuti.',
        'app_name'  => config('app.name'),
    ],
    
    // Stringhe comuni a tutte le notifiche
    'common' => [
        'regards'               => 'Cordiali saluti',
        'app_name'              => config('app.name'),
        'copyright'             => 'Tutti i diritti riservati.',
        'trouble_with_button'   => 'Se hai problemi a cliccare il pulsante ":actionText", copia e incolla l\'URL seguente nel tuo browser:',
        'no_reply'              => 'Si prega di non rispondere a questa email.',
        'auto_generated'        => 'Questa è un\'email generata automaticamente.',
    ],
];
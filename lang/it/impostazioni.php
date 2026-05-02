<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_update_notification_preferences' => 'Le tue preferenze di notifica sono state aggiornate con successo',
    'error_update_notification_preferences'   => 'Si è verificato un errore nel tentativo di aggiornare le tue preferenze di notifica',
    'success_save_general_settings'           => 'Le impostazioni generali sono state salvate con successo',
    'error_save_general_settings'             => 'Si è verificato un errore durante il salvataggio delle impostazioni generali',
    'success_save_cron_settings'              => 'Le impostazioni di automazione cloud sono state salvate con successo',
    'error_save_cron_settings'                => 'Si è verificato un errore durante il salvataggio delle impostazioni di automazione cloud',
    'success_regenerate_cron_token'           => 'Token webhook rigenerato con successo',
    'error_regenerate_cron_token'             => 'Si è verificato un errore durante la rigenerazione del token',
    'success_save_mail_settings'              => 'Configurazione email salvata con successo',
    'error_save_mail_settings'                => 'Errore durante il salvataggio della configurazione email',

    /* ------------------------------------------------------------------
     | Mail Status Badge
     | ------------------------------------------------------------------ */
    'mail_status' => [
        'database' => 'SMTP da Database',
        'env'      => 'Configurazione .env',
        'log'      => 'Modalità Sicura (Log)',
    ],

    /* ------------------------------------------------------------------
     | Driver descriptions
     | ------------------------------------------------------------------ */
    'driver' => [
        'smtp_description'     => 'Server SMTP esterno (Gmail, Brevo, ecc.)',
        'sendmail_description' => 'Mail PHP locale — ideale per hosting condivisi',
    ],

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'settings_head'                => 'Settings',
        'settings_title'               => 'Impostazioni applicazione',
        'settings_description'         => 'Di seguito un elenco di tutte le impostazioni configurabili per l\'applicazione',
        'general_settings_title'       => 'Impostazioni generali',
        'general_settings_description' => 'On this page you can manage the general settings of the application',
        'cron_settings_title'          => 'Automazione cloud (Cron esterno)',
        'cron_settings_description'    => 'Utilizza questa funzione se il tuo hosting non supporta cron jobs ogni minuto. Servizi supportati: cron-job.org',
        'mail_settings_title'          => 'Configurazione email',
        'mail_settings_description'    => 'Configura il metodo di invio per rate, solleciti e comunicazioni ufficiali ai condomini.',
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'manage'                => 'Gestisci',
        'settings'              => 'Impostazioni',
        'update_now'            => 'Aggiorna ora',
        'back_to_settings'      => 'Impostazioni',
        'mail_host'             => 'Server SMTP (Host)',
        'mail_port'             => 'Porta SMTP',
        'mail_username'         => 'Username / Email',
        'mail_password'         => 'Password SMTP',
        'mail_encryption'       => 'Crittografia (Sicurezza)',
        'mail_from_address'     => 'Indirizzo Email mittente',
        'mail_from_name'        => 'Nome visualizzato mittente',
        'save_settings'         => 'Salva configurazione',
        'send_test'             => 'Invia email di test',
        'password_is_set'       => 'Password impostata e sicura',
        'enable_db_settings'    => 'Attiva configurazione da database',
        'enable_db_description' => 'Se disattivato, il sistema userà i parametri definiti nel file .env',
        'mail_driver'           => 'Metodo di invio',
        'api_key_is_set'        => 'API key configurata e sicura',
        'encryption_none'       => 'Nessuna',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'general_settings_title'                => 'Impostazioni generali',
        'general_settings_description'          => 'Impostazioni generali di configurazione dell\'applicazione',
        'users_settings_title'                  => 'Gestione utenti',
        'users_settings_description'            => 'Impostazioni di gestione degli utenti, ruoli e permessi',
        'backups_settings_title'                => 'Gestione backups',
        'backups_settings_description'          => 'Impostazioni di gestione dei backups',
        'updates_title'                         => 'Aggiornamenti sistema',
        'updates_desc_available'                => 'Nuova versione disponibile: :version',
        'updates_desc_latest'                   => 'Il sistema è aggiornato all\'ultima versione',
        'language_settings_title'               => 'Lingua applicazione',
        'language_settings_description'         => 'Seleziona la lingua principale per l\'applicazione',
        'default_building_title'                => 'Apri condominio al login',
        'default_building_description'          => 'Se attivato, l\'utente verrà reindirizzato direttamente al condominio selezionato',
        'select_building_title'                 => 'Condominio predefinito',
        'select_building_description'           => 'Seleziona il condominio da aprire automaticamente il gestionale dopo il login',
        'user_registration_title'               => 'Abilita registrazione utenti',
        'user_registration_description'         => 'Se attivato, gli utenti possono registrarsi dalla home page',
        'mail_settings_title'                   => 'Configurazione email',
        'mail_settings_description'             => 'Scegli il metodo di invio, configura le credenziali e testa la connessione.',
        'mail_guide_title'                      => 'Guida alla configurazione SMTP',
        'mail_guide_gmail'                      => 'Gmail: Attiva la verifica in 2 passaggi e genera una "Password per le App". Usa porta 587 con TLS.',
        'mail_guide_smtp2go'                    => 'Server Gratuiti: Se usi Altervista, considera Sendmail oppure SMTP2Go per superare i blocchi delle porte.',
        'mail_guide_domain'                     => 'Consiglio Pro: Usa un dominio professionale validato per evitare lo Spam.',
        'mail_info_title'                       => 'Come funziona l\'invio email?',
        'mail_info_description'                 => 'Kondomanager supporta tre metodi di invio: <strong>SMTP</strong> per server esterni (Gmail, Brevo, ecc.), <strong>Sendmail</strong> per hosting condivisi (es. Altervista) dove le porte SMTP sono bloccate.<br><br>Se disattivi la configurazione da database, verrà usata quella del file <strong>.env</strong>.',
        'mail_legend_title'                     => 'Legenda stati',
        'mail_legend_database'                  => 'Usa le tue credenziali personalizzate (Prioritario).',
        'mail_legend_env'                       => 'Usa la configurazione di default del server.',
        'mail_legend_log'                       => 'Invio email disabilitato (Solo file di log).',
        'sendmail_guide_title'                  => 'Sendmail — nessuna credenziale richiesta',
        'sendmail_guide_description'            => 'Le email vengono inviate tramite il server di posta locale (PHP mail). Ideale per hosting condivisi come Altervista dove le porte SMTP esterne sono bloccate. La deliverability dipende dalla reputazione del server.',
        'test_header'                           => 'Test di invio immediato',
        'test_success_title'                    => 'Connessione riuscita',
        'test_success_message'                  => 'L\'email di test è stata inviata correttamente al destinatario.',
        'test_error_title'                      => 'Errore di connessione',
        'test_error_message'                    => 'Impossibile inviare l\'email. Controlla i parametri e riprova.',
        'cron_info_title'                       => 'Cos\'è l\'automazione cloud?',
        'cron_info_description'                 => 'Kondomanager esegue operazioni pianificate in background (es. generazione rate, invio email).<br><br>Di norma, il server gestisce tutto autonomamente. Attiva questa opzione <strong>SOLO</strong> se sei su un <strong>Hosting Condiviso</strong> che non permette di configurare il "Crontab" di sistema via terminale.',
        'cron_legend_title'                     => 'Modalità Operativa',
        'cron_legend_external'                  => 'Webhook (Esterno): Il sistema attende un segnale da cron-job.org.',
        'cron_legend_internal'                  => 'System Cron (Interno): Il server gestisce i processi autonomamente.',
        'cron_settings_title'                   => 'Automazione cloud',
        'cron_settings_description'             => 'Configura cron-job.org per hosting condivisi',
        'enable_external_scheduler_title'       => 'Abilita scheduler esterno',
        'enable_external_scheduler_description' => 'Permetti a servizi terzi di eseguire le automazioni',
        'webhook_url_title'                     => 'Webhook URL',
        'webhook_url_description'               => 'Copia questo URL e imposta una chiamata GET ogni 1 minuto sul tuo servizio esterno',
        'webhook_url_badge'                     => 'Segreto',
        'security_warning_title'                => 'Sicurezza IP attiva',
        'security_warning_description'          => 'Il sistema accetta chiamate solo dagli IP ufficiali di cron-job.org. Se usi un altro servizio, questa configurazione non funzionerà.',
        'logs_settings_title'                   => 'Audit & Logs di Sistema',
        'logs_settings_description'             => 'Visualizza lo storico delle email inviate, le attività degli utenti e i log di sistema.',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building'       => 'Seleziona condominio',
        'select_language'       => 'Seleziona lingua',
        'search_settings'       => 'Filtra impostazioni...',
        'mail_host'             => 'es. smtp.gmail.com',
        'mail_password'         => 'Inserisci la password SMTP',
        'mail_password_keep'    => 'Lascia vuoto per mantenere la password attuale',
        'mail_password_enter'   => 'Inserisci la password SMTP',
        'mail_from_address'     => 'es. amministrazione@studio-rossi.it',
        'test_recipient'        => 'Inserisci l\'email per il test',

        'language' => [
            'it' => 'Italiano',
            'en' => 'Inglese',
            'pt' => 'Portoghese',
        ],
    ],

    'actions' => [
        'save_settings'    => 'Salva impostazioni',
        'copy_url'         => 'Copia URL',
        'regenerate_token' => 'Rigenera token',
    ],
    'confirmations' => [
        'regenerate_token' => 'Sei sicuro? Dovrai aggiornare l\'URL su cron-job.org',
    ],
    'sidebar' => [
        'users'       => 'Utenti',
        'roles'       => 'Ruoli',
        'permissions' => 'Permessi',
        'invites'     => 'Inviti',
    ],
];
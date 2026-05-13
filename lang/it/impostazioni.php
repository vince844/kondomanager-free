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
        'database' => 'Configurazione da Database',
        'env'      => 'Configurazione .env',
        'log'      => 'Modalità Sicura (Log)',
    ],

    /* ------------------------------------------------------------------
     | Driver descriptions
     | ------------------------------------------------------------------ */
    'driver' => [
        'smtp_description'     => 'Consigliato. Usa un server SMTP esterno (Gmail, Brevo, ecc.)',
        'sendmail_description' => 'Solo per VPS o server dedicati con Postfix e SPF/DKIM configurati.',
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
        'mail_sendmail_path'      => 'Percorso Sendmail',
        'mail_sendmail_path_hint' => 'Lascia il valore di default se non sai cosa modificare. Cambia solo se il tuo server usa un percorso diverso.',
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
        'default_role_title'                    => 'Ruolo predefinito nuovi utenti',
        'default_role_description'              => 'Scegli quale ruolo assegnare automaticamente agli utenti che si registrano dal frontend.',
        'mail_settings_title'                   => 'Configurazione email',
        'mail_settings_description'             => 'Scegli il metodo di invio, configura le credenziali e testa la connessione.',
        'mail_guide_title'                      => 'Guida alla configurazione',
        'mail_guide_gmail'                      => 'Gmail (consigliato per tutti): Attiva la verifica in 2 passaggi, genera una "Password per le App" e usa smtp.gmail.com, porta 587, TLS. Funziona su qualsiasi hosting senza configurazioni DNS.',
        'mail_guide_smtp2go'                    => 'Altri provider SMTP (Brevo, SMTP2Go, ecc.): richiedono la verifica del dominio mittente tramite record DNS (SPF/DKIM). Usa questa opzione solo se hai un dominio proprio con accesso al pannello DNS.',
        'mail_guide_domain'                     => 'Sendmail: funziona solo su VPS o server dedicati con Postfix installato e SPF/DKIM configurati. Non adatto per hosting condivisi.',
        'mail_info_title'                       => 'Come funziona l\'invio email?',
        'mail_info_description'                 => 'Kondomanager supporta due metodi di invio.<br><br><strong>SMTP</strong> — il metodo consigliato per tutti. Usa un server di posta esterno (es. Gmail). Le email vengono consegnate in modo affidabile senza configurazioni avanzate.<br><br><strong>Sendmail</strong> — per utenti avanzati con VPS o server dedicati dove Postfix è installato e i record SPF/DKIM sono configurati nel DNS. Su hosting condivisi le email verranno rifiutate dai destinatari.<br><br>Se disattivi la configurazione da database, verrà usata quella del file <strong>.env</strong>.',
        'mail_legend_title'                     => 'Legenda stati',
        'mail_legend_database'                  => 'Usa le credenziali configurate in questa pagina (prioritario rispetto al .env).',
        'mail_legend_env'                       => 'Usa la configurazione definita nel file .env del server.',
        'mail_legend_log'                       => 'Invio email disabilitato — le email vengono scritte solo nel file di log.',
        'sendmail_guide_title'                  => 'Sendmail — solo per server dedicati',
        'sendmail_guide_description'            => 'Usa questa opzione SOLO se il tuo server è un VPS o server dedicato con Postfix/Exim installato e i record SPF e DKIM configurati nel DNS del dominio mittente. Su hosting condivisi (Altervista, Aruba, ecc.) le email verranno rifiutate dai destinatari perché i provider moderni (Gmail, Outlook) richiedono l\'autenticazione DNS. Per hosting condivisi usa Gmail SMTP.',
        'test_header'                           => 'Test di invio immediato',
        'test_success_title'                    => 'Connessione riuscita',
        'test_success_message'                  => 'L\'email di test è stata inviata correttamente al destinatario.',
        'test_error_title'                      => 'Errore di connessione',
        'test_error_message'                    => 'Impossibile inviare l\'email. Controlla i parametri e riprova.',
        'test_unsaved_warning'                  => 'Hai modifiche non salvate. Salva la configurazione prima di inviare il test.',
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
        'select_building'     => 'Seleziona condominio',
        'select_language'     => 'Seleziona lingua',
        'search_settings'     => 'Filtra impostazioni...',
        'mail_host'           => 'es. smtp.gmail.com',
        'mail_password'       => 'Inserisci la password SMTP',
        'mail_password_keep'  => 'Lascia vuoto per mantenere la password attuale',
        'mail_password_enter' => 'Inserisci la password SMTP',
        'mail_from_address'   => 'es. amministrazione@studio-rossi.it',
        'test_recipient'      => 'Inserisci l\'email per il test',
        'select_role'         => 'Seleziona un ruolo',

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
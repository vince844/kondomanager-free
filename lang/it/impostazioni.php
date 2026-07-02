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
    'success_save_print_settings'             => 'Impostazioni di stampa salvate con successo',

    /* ------------------------------------------------------------------
     | Mail Status Badge
     | ------------------------------------------------------------------ */
    'mail_status' => [
        'database' => 'Configurazione da Database',
        'env'      => 'Configurazione .env',
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
        'discover_patreon'      => 'Scopri Patreon',
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
        'print_legal_note'        => 'Nota Legale / Footer',
        'print_legal_note_help'   => 'Questo testo apparirà nel footer (piè di pagina) di ogni prospetto, assieme al numero di pagina.',
        'print_legal_note_tooltip' => 'Puoi premere "Invio" per andare a capo e inserire spazi multipli per allineare il testo. La formattazione verrà mantenuta fedelmente in stampa nel PDF.',
        'print_admin_signature'   => 'Firma Amministratore',
        'print_no_signature'      => 'Nessuna firma',
        'print_upload_image'      => 'Carica Immagine',
        'print_remove_signature'  => 'Rimuovi',
        'print_admin_signature_help' => 'Usa un\'immagine PNG o JPG con sfondo bianco o trasparente (max 2MB). L\'immagine verrà stampata alla fine dell\'ultimo foglio di prospetti e rendiconti.',
        'print_admin_signature_tooltip' => '<strong>Dimensioni ottimali:</strong> ~400x150 pixel.<br>Consigliato formato PNG con sfondo trasparente e ritagliato senza margini bianchi eccessivi attorno alla firma.',
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
        'app_name_settings_title'               => 'Nome applicazione',
        'app_name_settings_description'         => 'Personalizza il nome mostrato nella scheda del browser e nelle email inviate ai condomini',
        'default_building_title'                => 'Apri condominio al login',
        'default_building_description'          => 'Se attivato, l\'utente verrà reindirizzato direttamente al condominio selezionato',
        'select_building_title'                 => 'Condominio predefinito',
        'select_building_description'           => 'Seleziona il condominio da aprire automaticamente il gestionale dopo il login',
        'user_registration_title'               => 'Abilita registrazione utenti',
        'user_registration_description'         => 'Se attivato, gli utenti possono registrarsi dalla home page',
        'default_role_title'                    => 'Ruolo predefinito nuovi utenti',
        'default_role_description'              => 'Scegli quale ruolo assegnare automaticamente agli utenti che si registrano dal frontend.',
        'force_comment_moderation_title'        => 'Forza moderazione commenti',
        'force_comment_moderation_description'  => 'Se attivato, tutti i commenti degli utenti normali richiederanno l\'approvazione di un amministratore prima di essere pubblicati e visibili.',
        'mail_settings_title'                   => 'Configurazione email',
        'mail_settings_description'             => 'Scegli il metodo di invio, configura le credenziali e testa la connessione.',
        'print_settings_title'                  => 'Impostazioni Stampe PDF',
        'print_settings_description'            => 'Configura l\'aspetto e i dati predefiniti che appariranno in fondo a tutti i documenti generati dal sistema.',
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
        'cron_guide_1_title'                    => 'Il Motore Invisibile',
        'cron_guide_1_desc'                     => 'Il demone esegue automaticamente i task in background: controlla le scadenze, emette le rate e gestisce gli alert senza intervento manuale.',
        'cron_guide_2_title'                    => 'Webhook (Esterno)',
        'cron_guide_2_desc'                     => 'Identificato dal pallino arancione. Permette di attivare lo scheduler tramite un URL criptato chiamato da servizi esterni (es. cron-job.org).',
        'cron_guide_3_title'                    => 'System Cron (Nativo)',
        'cron_guide_3_desc'                     => 'Identificato dal pallino grigio. Indica che lo scheduler gira in modo ultra-veloce direttamente sul sistema operativo (es. Crontab o Plesk).',
        'cron_settings_title'                   => 'Automazione cloud',
        'cron_settings_description'             => 'Configura cron-job.org per hosting condivisi',
        'cron_status_never_run'                 => 'Mai eseguito',
        'cron_status_now'                       => 'Ora',
        'cron_status_seconds_ago'               => ':seconds sec fa',
        'cron_status_minutes_ago'               => ':minutes min fa',
        'cron_label_daemon'                     => 'Demone Cron:',
        'cron_status_active'                    => 'Attivo',
        'cron_status_error'                     => 'Fermo/Errore',
        'cron_label_last_check'                 => '(Ultimo check:',
        'enable_external_scheduler_title'       => 'Abilita scheduler esterno',
        'enable_external_scheduler_description' => 'Permetti a servizi terzi di eseguire le automazioni',
        'webhook_url_title'                     => 'Webhook URL',
        'webhook_url_description'               => 'Copia questo URL e imposta una chiamata GET ogni 1 minuto sul tuo servizio esterno',
        'webhook_url_badge'                     => 'Segreto',
        'security_warning_title'                => 'Sicurezza IP attiva',
        'security_warning_description'          => 'Il sistema accetta chiamate solo dagli IP ufficiali di cron-job.org. Se usi un altro servizio, questa configurazione non funzionerà.',
        'logs_settings_title'                   => 'Audit & Logs di Sistema',
        'patreon_title'                         => 'Supportaci su Patreon',
        'patreon_desc'                          => 'Unisciti alla community e sostieni lo sviluppo di Kondomanager.',
        'logs_settings_description'             => 'Visualizza lo storico delle email inviate, le attività degli utenti e i log di sistema.',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building'     => 'Seleziona condominio',
        'select_language'     => 'Seleziona lingua',
        'app_name'            => 'Es. Studio Amministrazioni Rossi',
        'search_settings'     => 'Filtra impostazioni...',
        'mail_host'           => 'es. smtp.gmail.com',
        'mail_password'       => 'Inserisci la password SMTP',
        'mail_password_keep'  => 'Lascia vuoto per mantenere la password attuale',
        'mail_password_enter' => 'Inserisci la password SMTP',
        'mail_from_address'   => 'es. amministrazione@studio-rossi.it',
        'test_recipient'      => 'Inserisci l\'email per il test',
        'select_role'         => 'Seleziona un ruolo',
        'print_legal_note'    => 'Es: Professione esercitata ai sensi della legge 14 gennaio 2013, n.4 - P.IVA 01234567890 - Polizza RC n. XYZ',

        'language' => [
            'it' => 'Italiano',
            'en' => 'Inglese',
            'pt' => 'Portoghese',
            'es' => 'Spagnolo',
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
    
    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'language_title' => 'Lingua e Traduzioni',
        'language_desc'  => 'Scegli la lingua predefinita con cui l\'applicazione verrà mostrata ai nuovi utenti e sui dispositivi non ancora configurati.',
        'access_title'   => 'Accesso e Condominio',
        'access_desc'    => 'Semplifica il lavoro quotidiano: imposta l\'apertura automatica di un condominio specifico subito dopo il login, saltando la dashboard.',
        'security_title' => 'Registrazione e Ruoli',
        'security_desc'  => 'Abilita la registrazione pubblica sul sito web. Ai nuovi iscritti verrà assegnato automaticamente il ruolo predefinito che scegli qui.',
        'print_footer_title' => 'Piè di pagina documenti',
        'print_footer_desc'  => 'Imposta la dicitura legale che apparirà in fondo a tutti i PDF generati.',
        'print_signature_title' => 'Firma documenti',
        'print_signature_desc'  => 'Carica o disegna la tua firma da apporre nei rendiconti e nei documenti ufficiali.',
        'print_layout_title' => 'Formato e layout',
        'print_layout_desc'  => 'Tutti i documenti vengono generati automaticamente in formato A4, con margini adattivi per l\'intestazione.',
    ],
];
<?php

return [

    'wizard' => [
        'title' => 'Kondomanager — Installazione',
        'brand_subtitle' => 'Software gestionale open source',
        'wait_default' => 'Attendere prego...',
        'wait_environment' => 'Configurazione del database in corso, non chiudere questa pagina...',
    ],

    'actions' => [
        'next' => 'Avanti',
        'back' => 'Indietro',
        'finish' => 'Fine',
        'skip' => 'Salta',
        'loading' => 'Attendere...',
        'show_password' => 'Mostra password',
        'hide_password' => 'Nascondi password',
    ],

    'steps' => [
        'welcome' => [
            'label' => 'Benvenuto su Kondomanager',
            'description' => 'Iniziare',
            'guide' => 'Questa procedura guidata ti accompagnerà passo dopo passo nell\'installazione e configurazione della piattaforma.',
        ],
        'requirements' => [
            'label' => 'Requisiti del server',
            'description' => 'Assicurarsi che tutti i requisiti necessari siano soddisfatti',
            'guide' => 'Verifica che il server soddisfi tutti i requisiti elencati sotto. Se qualcosa risulta mancante, contatta il tuo hosting per risolverlo prima di continuare.',
        ],
        'environment' => [
            'label' => 'Applicazione e database',
            'description' => 'Nome, lingua e connessione al database',
            'guide' => "Inserisci nome, indirizzo web e lingua predefinita dell'applicazione, oltre alle credenziali di accesso al database (le trovi nel pannello di controllo del tuo hosting o le hai create tu stesso).",
        ],
        'mail' => [
            'label' => 'Impostazioni di posta',
            'description' => 'Impostazioni della posta in uscita',
            'guide' => "Configura il server SMTP che Kondomanager userà per inviare email (notifiche, reimpostazione password, comunicazioni ai condomini). Puoi saltare questo step e configurarlo in un secondo momento dalle Impostazioni Generali.",
        ],
        'admin' => [
            'label' => 'Crea amministratore',
            'description' => 'Crea utente amministratore',
            'guide' => 'Crea il tuo account amministratore principale: userai queste credenziali per accedere a Kondomanager al termine dell\'installazione.',
        ],
        'finish' => [
            'label' => 'Fine',
            'description' => 'Completa la configurazione',
            'guide' => "Ultimo passaggio prima di iniziare a usare Kondomanager: ricordati di configurare il cronjob sul tuo server (da eseguire ogni minuto verso l'URL dello scheduler), altrimenti i processi in background — emissione rate, promemoria, notifiche email — non funzioneranno.",
        ],
    ],

    'welcome' => [
        'before_start' => "Prima di iniziare, tieni a portata di mano le credenziali del database e, se vuoi configurarla subito, anche quelle del server di posta SMTP. Assicurati inoltre di avere i seguenti requisiti minimi del server:",
        'php_requirement' => 'Il server deve avere installato la versione :version o maggiore.',
        'php_check_link' => 'Come controllare la versione di PHP installata',
        'extensions_label' => 'Estensioni:',
        'extensions_text' => 'Assicurati di aver le seguenti estensioni abilitate nella configurazione di PHP.',
        'extensions_check_link' => 'Come controllare quali estensioni PHP sono installate',
        'database_label' => 'Database:',
        'database_text' => "L'applicazione necessita di un database MySQL per la registrazione dei dati. Assicurati di aver creato un database e di avere a disposizione host, porta, nome del database, username e password.",
        'database_link' => 'Come creare un database su cPanel',
        'mail_label' => 'Impostazioni email:',
        'mail_text' => "L'applicazione invia email importanti come registrazione utenti, reimpostazione password e notifiche. Assicurati di avere a portata di mano un indirizzo email e una password validi, in linea con la configurazione dell'host e della porta del server.",
        'mail_link' => 'Testa la configurazione SMTP',
        'cache_label' => 'Pulisci la cache:',
        'cache_text' => 'Se necessario, pulisci la cache del server prima di procedere.',
    ],

    'requirements' => [
        'php_version' => 'Versione PHP',
        'extensions' => 'Estensioni',
        'permissions' => 'Permessi',
        'recheck_button' => 'Ricontrolla',
        'last_checked' => 'Ultimo controllo: :time',
    ],

    'sections' => [
        'app' => 'App',
        'database' => 'Database',
        'mail' => 'Mail',
    ],

    'fields' => [
        'app_name' => ['label' => 'Nome applicazione', 'tooltip' => 'Il nome mostrato nella scheda del browser e come mittente nelle email inviate ai condomini.'],
        'app_url' => ['label' => 'URL applicazione', 'tooltip' => "L'indirizzo web completo con cui raggiungerai Kondomanager, es. https://tuodominio.com."],
        'app_locale' => ['label' => 'Lingua', 'tooltip' => "La lingua predefinita con cui l'applicazione verrà mostrata ai nuovi utenti. Potrai cambiarla in qualsiasi momento dalle Impostazioni Generali."],
        'db_host' => ['label' => 'Host', 'tooltip' => 'Indirizzo del server database, generalmente 127.0.0.1 o localhost.'],
        'db_port' => ['label' => 'Porta', 'tooltip' => 'La porta del server MySQL, generalmente 3306.'],
        'db_database' => ['label' => 'Database', 'tooltip' => 'Il nome del database creato appositamente per Kondomanager.'],
        'db_username' => ['label' => 'Username', 'tooltip' => 'Utente con permessi di accesso al database.'],
        'db_password' => ['label' => 'Password', 'tooltip' => "La password dell'utente database indicato sopra."],
        'mail_mailer' => ['label' => 'Mailer', 'tooltip' => 'Il metodo di invio email, generalmente "smtp".'],
        'mail_host' => ['label' => 'Host', 'tooltip' => 'Indirizzo del server SMTP, es. smtp.gmail.com.'],
        'mail_port' => ['label' => 'Porta', 'tooltip' => 'La porta SMTP: generalmente 587 (TLS) o 465 (SSL).'],
        'mail_username' => ['label' => 'Username', 'tooltip' => "L'indirizzo email o username per autenticarsi al server SMTP."],
        'mail_password' => ['label' => 'Password', 'tooltip' => "La password dell'account email indicato sopra."],
        'mail_from_address' => ['label' => 'Indirizzo mittente', 'tooltip' => "L'indirizzo email che i condomini vedranno come mittente delle comunicazioni."],
        'mail_from_name' => ['label' => 'Nome mittente', 'tooltip' => "Il nome visualizzato accanto all'indirizzo mittente nelle email."],
        'admin_name' => ['label' => 'Nome completo', 'tooltip' => "Il nome dell'amministratore principale di Kondomanager."],
        'admin_email' => ['label' => 'Indirizzo email', 'tooltip' => 'Userai questo indirizzo per accedere come amministratore.'],
        'admin_password' => ['label' => 'Password', 'tooltip' => 'Almeno 6 caratteri. Scegli una password sicura.'],
        'admin_password_confirmation' => ['label' => 'Conferma password', 'tooltip' => 'Ripeti la password per conferma.'],
    ],

    'finish' => [
        'title' => 'Installazione completata!',
        'description' => "L'applicazione è stata installata e configurata con successo.",
        'save_settings' => 'Salva impostazioni',
        'cron_guide' => [
            'title' => 'Guida alla configurazione del cronjob',
            'subtitle' => "Scegli il tuo ambiente di hosting per le istruzioni dettagliate. Potrai rivedere questa guida in qualsiasi momento da Impostazioni > Cron.",
            'tab_webhook' => 'cron-job.org',
            'tab_cpanel' => 'cPanel',
            'tab_plesk' => 'Plesk / VPS',
            'webhook_intro' => "Ideale per hosting condivisi senza accesso al terminale (es. Altervista): un servizio gratuito \"chiama\" la tua installazione ogni minuto al posto di un vero cronjob.",
            'webhook_step1' => "Accedi a Kondomanager come amministratore, vai su Impostazioni > Cron, attiva \"Scheduler esterno\" e copia l'URL webhook generato.",
            'webhook_step2' => "Crea un account gratuito su cron-job.org, crea un nuovo cronjob incollando l'URL copiato e imposta l'esecuzione ogni minuto.",
            'cpanel_intro' => 'Configurazione nativa consigliata per hosting professionali con cPanel — più stabile ed efficiente del webhook.',
            'cpanel_step' => 'Nella sezione "Cron Jobs" di cPanel, imposta la frequenza su ogni minuto (* * * * *) e incolla questo comando (adattando il percorso):',
            'cpanel_command' => '/usr/local/bin/php /home/tuosito/public_html/artisan schedule:run >> /dev/null 2>&1',
            'plesk_intro' => 'Su server Plesk o VPS, i limiti di timeout più severi richiedono due processi separati anziché uno solo.',
            'plesk_step1' => 'Nel file .env, imposta:',
            'plesk_env' => 'SCHEDULE_QUEUE_WORKER=false',
            'plesk_step2' => 'Crea due attività pianificate distinte in Plesk, entrambe ogni minuto (* * * * *):',
            'plesk_command1_label' => 'Cron 1 — Scheduler',
            'plesk_command1' => 'php artisan schedule:run >> /dev/null 2>&1',
            'plesk_command2_label' => 'Cron 2 — Queue worker',
            'plesk_command2' => 'php artisan queue:work --stop-when-empty --max-time=55 --tries=3',
        ],
    ],

    'validation' => [
        'no_whitespace' => 'Il campo :attribute non può contenere spazi.',
    ],

    'mail' => [
        'test_button' => 'Invia email di test',
        'test_subject' => 'Email di test da Kondomanager',
        'test_body' => 'Se hai ricevuto questa email, la configurazione SMTP è corretta e Kondomanager potrà inviare notifiche, promemoria e comunicazioni ai condomini.',
        'test_success' => 'Email di test inviata con successo a :email. Controlla la tua casella di posta.',
        'test_error' => 'Invio non riuscito: :error',
    ],

    'database' => [
        'test_button' => 'Testa connessione',
        'test_success' => 'Connessione riuscita! Il database ":database" è raggiungibile con queste credenziali.',
        'test_error' => 'Connessione non riuscita: :error',
    ],

];

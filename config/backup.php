<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruttore generale
    |--------------------------------------------------------------------------
    |
    | Con false la card in impostazioni appare disabilitata e tutte le rotte
    | backup rispondono 404. Pensato per le installazioni dove i backup non
    | devono essere gestiti dall'amministratore dell'applicazione: la demo
    | pubblica (l'archivio conterrebbe il .env del server) e, in futuro, il
    | SaaS gestito. Sul server demo: BACKUP_ENABLED=false nel .env.
    |
    */

    'enabled' => (bool) env('BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Esecuzione a passi (step-runner)
    |--------------------------------------------------------------------------
    |
    | Il backup viene eseguito in passi brevi e riprendibili, ognuno dentro
    | una singola richiesta HTTP (pattern Duplicator/UpdraftPlus). Questo è
    | l'unico modello di esecuzione compatibile con gli hosting condivisi,
    | dove max_execution_time è basso, set_time_limit può essere disabilitato
    | e non esiste un queue worker sempre attivo.
    |
    */

    // Secondi di lavoro utile per ogni step HTTP. Tenuto ben sotto i
    // max_execution_time tipici (30-60s) per lasciare margine a bootstrap
    // e risposta. Abbassalo su hosting particolarmente restrittivi.
    'step_time_budget' => (int) env('BACKUP_STEP_TIME_BUDGET', 20),

    // Righe lette dal database per ogni chunk durante il dump.
    'db_chunk_rows' => (int) env('BACKUP_DB_CHUNK_ROWS', 500),

    // Dimensione massima (in byte) di un singolo statement INSERT esteso.
    // Sotto 1MB per restare importabile da phpMyAdmin e da max_allowed_packet
    // conservativi.
    'insert_max_bytes' => 900 * 1024,

    // Numero massimo di file aggiunti allo zip tra una chiusura e l'altra.
    // ZipArchive tiene aperti i file descriptor fino a close(): batch piccoli
    // evitano di esaurire i descriptor e rendono lo step riprendibile.
    'zip_batch_files' => (int) env('BACKUP_ZIP_BATCH_FILES', 200),

    // Byte massimi accumulati in un batch prima di forzare la chiusura dello
    // zip (la compressione avviene alla close(): batch enormi = step lunghi).
    'zip_batch_bytes' => 64 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Contenuto del backup
    |--------------------------------------------------------------------------
    |
    | Percorsi relativi a base_path(). Il backup include tutto ciò che serve
    | per trasferire o ripristinare l'installazione: dump del database,
    | file caricati dagli utenti e configurazione .env.
    | storage/framework e storage/logs sono volatili e restano esclusi.
    |
    */

    'include' => [
        'storage/app',
        '.env',
    ],

    'exclude' => [
        // La cartella dei backup non deve mai finire dentro sé stessa.
        'storage/app/backups',
    ],

    // Tabelle di cui il dump include la STRUTTURA ma non i DATI.
    // 'backups' contiene i checkpoint dei backup in corso (inclusa la
    // password di cifratura, cifrata con APP_KEY) e righe che comunque
    // punterebbero ad archivi inesistenti dopo un ripristino.
    'dump_exclude_data' => [
        'backups',
    ],

    // File di sistema del sistema operativo, inutili al ripristino:
    // esclusi per nome ovunque si trovino.
    'exclude_filenames' => [
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',
    ],

    /*
    |--------------------------------------------------------------------------
    | Destinazione e retention
    |--------------------------------------------------------------------------
    */

    // Disco Laravel su cui viene salvato l'archivio finale. Il plugin backup
    // potrà registrare destinazioni aggiuntive (cloud) tramite il
    // DestinationManager senza toccare questa configurazione.
    'disk' => 'backups',

    // File in cui è custodita (cifrata con APP_KEY) la password di protezione
    // dei backup. Vive dentro la cartella esclusa dagli archivi, così la
    // password non finisce mai dentro un backup. I test lo ridefiniscono per
    // non toccare il file reale (vedi Tests\TestCase). Env-driven per poter
    // isolare istanze di collaudo o spostare lo storage su un mount dedicato.
    'password_file' => env('BACKUP_PASSWORD_FILE', storage_path('app/backups/.default-password')),

    // Radice dei file temporanei di lavoro (dump parziali, zip in
    // costruzione). Anche questa è ridefinita dai test, così i processi
    // paralleli non si cancellano i temporanei a vicenda.
    'tmp_path' => env('BACKUP_TMP_PATH', storage_path('app/backups/tmp')),

    /*
    |--------------------------------------------------------------------------
    | Ripristino
    |--------------------------------------------------------------------------
    |
    | Lo stato del ripristino vive su FILE, mai nel database: l'import
    | sovrascrive il database stesso (sessioni, cache e lock inclusi, che
    | in questa applicazione stanno tutti su DB). Vedi
    | docs/ripristino_backup_design.md. Tutti e tre i percorsi sono
    | ridefiniti dai test per l'isolamento tra processi paralleli.
    |
    */

    'restore' => [
        // Stato/checkpoint del ripristino in corso (JSON, scritture atomiche).
        // Dentro la cartella backups: fuori document root, esclusa dagli archivi.
        'state_file' => env('BACKUP_RESTORE_STATE_FILE', storage_path('app/backups/.restore-state.json')),

        // Lock di mutua esclusione degli step (flock, si libera da solo se
        // il processo muore). La cache non è affidabile qui: sta nel DB.
        'lock_file' => env('BACKUP_RESTORE_LOCK_FILE', storage_path('app/backups/.restore-lock')),

        // Marker "modalità ripristino": finché esiste, il middleware blocca
        // tutta l'app tranne le rotte di ripristino. In storage/framework
        // perché deve esistere anche se storage/app/backups viene ricreata.
        'marker_file' => env('BACKUP_RESTORE_MARKER_FILE', storage_path('framework/restore.lock')),
    ],

    'filename_prefix' => 'kondomanager-backup',

    // Margine di sicurezza applicato alla stima dello spazio disco richiesto.
    'free_space_margin' => 1.5,

    // Un backup rimasto in esecuzione senza progressi oltre questo numero di
    // ore viene marcato come fallito e i suoi file temporanei ripuliti.
    'stale_after_hours' => 2,

];

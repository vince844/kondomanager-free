<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'backups' => [
            'driver' => 'local',
            // Env-driven per isolare istanze di collaudo o spostare gli archivi
            // su un mount dedicato; default invariato.
            'root' => env('BACKUP_ROOT', storage_path('app/backups')),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | I documenti caricati (archivio, unità, fornitori, fatture, titoli di subentro) e la firma
        | delle stampe su un bucket S3-compatibile (Cloudflare R2, Amazon S3, Backblaze B2, MinIO…),
        | quando `DOCUMENTI_DISK=s3`. Chi li usa non nomina questi dischi: legge
        | `config('kondomanager.disco_documenti')` e `config('kondomanager.disco_pubblici')`, che
        | senza la variabile valgono `local` e `public` — gli stessi dischi, le stesse cartelle di
        | sempre. `root` è il prefisso nel bucket: per un'installazione ospitata il suo nome, per
        | un'installazione autonoma di solito vuoto. Le credenziali sono le `AWS_*` del disco `s3`
        | qui sopra (`AWS_DEFAULT_REGION=auto` per R2).
        */
        'documenti_s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => trim((string) env('DOCUMENTI_PREFIX', ''), '/'),
            // In streaming verso il browser, non scaricato per intero sul server prima.
            'stream_reads' => true,
            'throw' => false,
            'report' => false,
        ],

        'pubblici_s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => trim(trim((string) env('DOCUMENTI_PREFIX', ''), '/').'/pubblici', '/'),
            // L'anteprima della firma è un URL firmato: se l'endpoint è raggiungibile solo dal
            // server (MinIO in rete Docker), AWS_TEMPORARY_URL è l'indirizzo che il browser vede.
            'url' => env('AWS_URL'),
            'temporary_url' => env('AWS_TEMPORARY_URL'),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

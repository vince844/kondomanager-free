<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Kondomanager'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value is the version of your application. This value is used when
    | the framework needs to place the application's version in a notification
    | or any other location as required by the application or its packages.
    |
    */

    'version' => env('APP_VERSION', '1.10.0-beta.20'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Fuso orario di riferimento dell'utente
    |--------------------------------------------------------------------------
    |
    | I timestamp restano in UTC (sopra), ma le validazioni sulle DATE inserite
    | a mano — "non può essere futura" — devono ragionare nel fuso in cui vive
    | l'amministratore. Con il solo UTC, un utente italiano che registra un
    | pagamento alle 00:30 sceglie dal calendario la data di oggi (ora locale) e
    | si vede respingere il form, perché in UTC è ancora ieri.
    |
    */

    'user_timezone' => env('APP_USER_TIMEZONE', 'Europe/Rome'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'it'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Impostazioni Specifiche Gestionale
    |--------------------------------------------------------------------------
    |
    | scheduler_queue_worker:
    | - TRUE (default distro): Shared Hosting senza accesso CLI
    |   (cPanel, Altervista, Netsons). Lancia un worker sincrono
    |   ogni minuto dentro lo scheduler. Richiede cron-job.org.
    |
    | - FALSE: Plesk, VPS o qualsiasi server con cron nativi CLI.
    |   Il worker gira come cron separato (queue:work --stop-when-empty).
    |   Impostare SCHEDULE_QUEUE_WORKER=false nel .env.
    |
    */
    'scheduler_queue_worker' => env('SCHEDULE_QUEUE_WORKER', false),

    // I proxy fidati NON sono più qui: la chiave 'app.trusted_proxies' non
    // veniva letta da nessuno (il middleware TrustProxies legge
    // config('trustedproxy.proxies')). La configurazione, con le relative note
    // di sicurezza, vive in config/trustedproxy.php.

];

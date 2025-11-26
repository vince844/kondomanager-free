<?php

return [

    'app_name' => 'KondoManager',

    'run_installer' => 'true',

    /*
    |--------------------------------------------------------------------------
    | Installation Steps
    |--------------------------------------------------------------------------
    | Define the steps of the installer wizard.
    | Each step has:
    | - key: unique identifier
    | - label: human-readable name
    | - component/controller: which Livewire component or controller handles it
    | - optional: whether this step can be skipped
    */

    'steps' => [
        [
            'key' => 'welcome',
            'label' =>  'Benvenuto',
            'description' => 'Iniziare',
            'component' => \Eii\Installer\Livewire\Install\Welcome::class,
        ],
        [
            'key' => 'requirements',
            'label' => 'Requisiti del server',
            'description' => 'Assicurarsi che tutti i requisiti necessari siano soddisfatti',
            'component' => \Eii\Installer\Livewire\Install\ServerRequirements::class,
        ],
        [
            'key' => 'environment',
            'label' => 'Impostazioni ambientali',
            'description' => 'Raccogliere le impostazioni ambientali',
            'component' => \Eii\Installer\Livewire\Install\EnvironmentSettings::class,
        ],
        [
            'key' => 'mail',
            'label' => 'Impostazioni di posta',
            'description' => 'Impostazioni della posta in uscita',
            'component' => \Eii\Installer\Livewire\Install\MailSettings::class,
            'optional' => true,
        ],
        [
            'key' => 'admin',
            'label' => 'Crea amministratore',
            'description' => 'Crea utente amministratore',
            'component' => \Eii\Installer\Livewire\Install\CreateAdmin::class,
            'optional' => true,
        ],
        [
            'key' => 'finish',
            'label' => 'Fine',
            'description' => 'Completa la configurazione',
            'component' => \Eii\Installer\Livewire\Install\Finish::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Requirements
    |--------------------------------------------------------------------------
    */
    'requirements' => [
        'php' => '8.1.0',
        'extensions' => [
            'openssl',
            'pdo',
            'mbstring',
            'tokenizer',
            'xml',
            'ctype',
            'json',
        ],
        'permissions' => [
            'storage/' => 'writable',
            'bootstrap/cache/' => 'writable',
            'storage/'          => 'writable',
            'storage/app/'      => 'writable',
            'storage/framework/' => 'writable',
            'storage/logs/'     => 'writable',
            'bootstrap/cache/'  => 'writable',
            '.env'              => 'writable',
        ],
        'environment' => [
            'production' => false,       // True for production, False for Local
            'debug' => true,            // Set debug
            'database' => true,         // Ask for mail details
            'mail' => true,
        ],
        'link_storage' => true,     // True to link storage
        'seed_database' => true,    // Enable DB seeding after migrations
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'lock_file' => storage_path('installed.lock'),
        'progress_file' => storage_path('install-progress.json'),
        'redirect_after_install' => '/',
    ],

];

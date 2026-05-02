<?php

return [

    'app_name'      => 'Kondomanager',
    'run_installer' => false,

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
            'key'           => 'welcome',
            'label'         => 'Benvenuto',
            'description'   => 'Iniziare',
            'component'     => \Eii\Installer\Livewire\Install\Welcome::class,
        ],
        [
            'key'           => 'requirements',
            'label'         => 'Requisiti del server',
            'description'   => 'Assicurarsi che tutti i requisiti necessari siano soddisfatti',
            'component'     => \Eii\Installer\Livewire\Install\ServerRequirements::class,
        ],
        [
            'key'           => 'environment',
            'label'         => 'Impostazioni ambientali',
            'description'   => 'Raccogliere le impostazioni ambientali',
            'component'     => \App\Livewire\Installer\FixedEnvironmentSettings::class,
        ],
        [
            'key'           => 'mail',
            'label'         => 'Impostazioni di posta',
            'description'   => 'Impostazioni della posta in uscita',
            'component'     => \Eii\Installer\Livewire\Install\MailSettings::class,
            'optional'      => true,
        ],
        [
            'key'           => 'admin',
            'label'         => 'Crea amministratore',
            'description'   => 'Crea utente amministratore',
            'component'     => \Eii\Installer\Livewire\Install\CreateAdmin::class,
            'optional'      => false,
        ],
        [
            'key'           => 'finish',
            'label'         => 'Fine',
            'description'   => 'Completa la configurazione',
            'component'     => \Eii\Installer\Livewire\Install\Finish::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Requirements
    |--------------------------------------------------------------------------
    */
    'requirements' => [
        'php' => '8.2.0',
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
            'storage/'              => 'writable',
            'bootstrap/cache/'      => 'writable',
            'storage/app/'          => 'writable',
            'storage/framework/'    => 'writable',
            'storage/logs/'         => 'writable',
            '.env'                  => 'writable',
        ],
        'environment' => [
            'production'    => true,  // True for production, False for Local
            'debug'         => false, // Set debug
            'database'      => true,  // Ask for database details. Set to false if there is no database. 
            'mail'          => true,
        ],
        'link_storage'      => true,  // True to link storage
        'seed_database'     => true, // Enable DB seeding after migrations
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'verify_admin_email'        => true, // Set to true to set the admin email as verified in the database
        'lock_file'                 => storage_path('installed.lock'),
        'progress_file'             => storage_path('install-progress.json'),
        'redirect_after_install'    => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spatie Permission Integration
    |--------------------------------------------------------------------------
    */
    'spatie' => [
        'enabled'       => true, // Set to false to disable role assignment
        'admin_role'    => 'amministratore', // The role name to assign to the new admin
    ],

];

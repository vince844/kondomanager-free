<?php

use App\Livewire\Installer\CreateAdmin;
use App\Livewire\Installer\Finish;
use App\Livewire\Installer\FixedEnvironmentSettings;
use App\Livewire\Installer\MailSettings;
use App\Livewire\Installer\ServerRequirements;
use App\Livewire\Installer\Welcome;

return [

    'app_name' => 'Kondomanager',
    'run_installer' => false,

    /*
    |--------------------------------------------------------------------------
    | Lingue disponibili per la selezione al momento dell'installazione
    |--------------------------------------------------------------------------
    | Chiave = codice locale (usato in APP_LOCALE e nella cartella lang/),
    | valore = etichetta mostrata nel wizard.
    */
    'available_locales' => [
        'it' => 'Italiano',
        'en' => 'English',
        'es' => 'Español',
        'pt' => 'Português',
    ],

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
            'label' => 'Benvenuto',
            'description' => 'Iniziare',
            'component' => Welcome::class,
        ],
        [
            'key' => 'requirements',
            'label' => 'Requisiti del server',
            'description' => 'Assicurarsi che tutti i requisiti necessari siano soddisfatti',
            'component' => ServerRequirements::class,
        ],
        [
            'key' => 'environment',
            'label' => 'Impostazioni ambientali',
            'description' => 'Raccogliere le impostazioni ambientali',
            'component' => FixedEnvironmentSettings::class,
        ],
        [
            'key' => 'mail',
            'label' => 'Impostazioni di posta',
            'description' => 'Impostazioni della posta in uscita',
            'component' => MailSettings::class,
            'optional' => true,
        ],
        [
            'key' => 'admin',
            'label' => 'Crea amministratore',
            'description' => 'Crea utente amministratore',
            'component' => CreateAdmin::class,
            'optional' => false,
        ],
        [
            'key' => 'finish',
            'label' => 'Fine',
            'description' => 'Completa la configurazione',
            'component' => Finish::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Requirements
    |--------------------------------------------------------------------------
    */
    /*
     * ⚠️ **Queste due liste sono un cancello: chi sta sotto viene respinto, e chi non c'è passa.**
     *
     * Non si scrivono a mano. Il minimo vero si legge da `composer.lock` — tutto Symfony chiede
     * `>=8.4.1` — e le estensioni sono quelle che i pacchetti di **runtime** dichiarano `ext-*` e
     * che un PHP standard non garantisce. `tests/Feature/System/RequisitiDichiaratiTest.php` rifà
     * quel conto a ogni esecuzione della suite e fallisce se questa lista scende sotto il vero.
     *
     * `gd` mancava fino alla beta.77, e lo pretende **mpdf**, cioè il motore di ogni PDF del
     * programma: un hosting senza `gd` superava questo controllo e poi falliva su ogni stampa.
     */
    'requirements' => [
        'php' => '8.4.1',
        'extensions' => [
            'openssl',
            'pdo',
            'mbstring',
            'tokenizer',
            'xml',
            'ctype',
            'json',
            'bcmath',
            'fileinfo',
            'gd',
            'intl',
            'zip',
        ],
        'permissions' => [
            'storage/' => 'writable',
            'bootstrap/cache/' => 'writable',
            'storage/app/' => 'writable',
            'storage/framework/' => 'writable',
            'storage/logs/' => 'writable',
            '.env' => 'writable',
        ],
        'environment' => [
            'production' => true,  // True for production, False for Local
            'debug' => false, // Set debug
            'database' => true,  // Ask for database details. Set to false if there is no database.
            'mail' => true,
        ],
        'link_storage' => true,  // True to link storage
        /*
        | Database Seeding Logic
        | Set 'enabled' to true to run seeders after migrations.
        | If 'classes' is empty, the default DatabaseSeeder will be executed.
        */
        'seeding' => [
            'enabled' => true,
            'classes' => [
                // \Database\Seeders\RoleAndPermissionSeeder::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'verify_admin_email' => true, // Set to true to set the admin email as verified in the database
        'lock_file' => storage_path('installed.lock'),
        'progress_file' => storage_path('install-progress.json'),
        'redirect_after_install' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spatie Permission Integration
    |--------------------------------------------------------------------------
    */
    'spatie' => [
        'enabled' => true, // Set to false to disable role assignment
        'admin_role' => 'amministratore', // The role name to assign to the new admin
    ],

];

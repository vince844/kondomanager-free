<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\On;
use Livewire\Component;

class FixedEnvironmentSettings extends Component
{
    // --- APP PROPERTIES ---
    public string $appName = '';

    public string $appUrl = '';

    public string $appLocale = '';

    // --- DATABASE PROPERTIES ---
    public bool $isDatabaseRequired = false;

    public string $dbConnection = 'mysql';

    public string $dbHost = '127.0.0.1';

    public string $dbPort = '3306';

    public ?string $dbDatabase = null;

    public ?string $dbUsername = null;

    public ?string $dbPassword = null;

    public ?string $dbTestStatus = null;

    public ?string $dbTestMessage = null;

    private function dbFieldRules(): array
    {
        return [
            'dbHost' => 'required|regex:/^\S*$/u',
            'dbPort' => 'required|numeric|regex:/^\S*$/u',
            'dbDatabase' => 'required|min:1|regex:/^\S*$/u',
            'dbUsername' => 'required|min:1|regex:/^\S*$/u',
            'dbPassword' => 'nullable|string',
        ];
    }

    protected function rules(): array
    {
        $rules = [
            'appName' => 'required|string|max:255',
            'appUrl' => 'required|string',
            'appLocale' => 'required|string|in:'.implode(',', array_keys(config('installer.available_locales', []))),
        ];

        if ($this->isDatabaseRequired) {
            $rules = array_merge($rules, $this->dbFieldRules());
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return [
            'appName' => __('installer.fields.app_name.label'),
            'appUrl' => __('installer.fields.app_url.label'),
            'appLocale' => __('installer.fields.app_locale.label'),
            'dbHost' => __('installer.fields.db_host.label'),
            'dbPort' => __('installer.fields.db_port.label'),
            'dbDatabase' => __('installer.fields.db_database.label'),
            'dbUsername' => __('installer.fields.db_username.label'),
            'dbPassword' => __('installer.fields.db_password.label'),
        ];
    }

    protected function messages(): array
    {
        // Il messaggio generico "formato non valido" non spiega il vincolo reale
        // (nessuno spazio consentito), quindi lo sostituiamo con uno esplicito.
        return [
            'dbHost.regex' => __('installer.validation.no_whitespace'),
            'dbPort.regex' => __('installer.validation.no_whitespace'),
            'dbDatabase.regex' => __('installer.validation.no_whitespace'),
            'dbUsername.regex' => __('installer.validation.no_whitespace'),
        ];
    }

    public function mount(): void
    {
        $this->isDatabaseRequired = config('installer.requirements.environment.database', false);
        $this->appName = config('installer.app_name', config('app.name', 'Laravel'));
        // env() diretto, non config('app.locale'): quest'ultimo può essere già stato
        // sovrascritto da App::setLocale() (usato da CheckInstaller per la lingua dei
        // testi del wizard, un concetto distinto dalla lingua di default dell'app
        // installata che questo campo propone come valore iniziale).
        $this->appLocale = env('APP_LOCALE', 'it');

        try {
            $progressFile = config('installer.options.progress_file');
            if (File::exists($progressFile)) {
                $progress = json_decode(File::get($progressFile), true);
                $data = $progress['data']['environment'] ?? [];

                $this->appName = $data['app_name'] ?? $this->appName;
                $this->appUrl = $data['app_url'] ?? $this->appUrl;
                $this->appLocale = $data['app_locale'] ?? $this->appLocale;

                $this->dbConnection = $data['db_connection'] ?? $this->dbConnection;
                $this->dbHost = $data['db_host'] ?? $this->dbHost;
                $this->dbPort = $data['db_port'] ?? $this->dbPort;
                $this->dbDatabase = $data['db_database'] ?? $this->dbDatabase;
                $this->dbUsername = $data['db_username'] ?? $this->dbUsername;
                $this->dbPassword = $data['db_password'] ?? $this->dbPassword;
            }
        } catch (\Exception $e) {
            $this->dispatch('wizard.error', ['message' => "Failed to load progress: {$e->getMessage()}"]);

            return;
        }

        $this->dispatch('wizard.canProceed');
    }

    public function updated(string $property): void
    {
        $this->validateOnly($property);
        if ($this->getErrorBag()->isEmpty($property)) {
            $this->dispatch('wizard.canProceed');
        }
    }

    #[On('completeStep')]
    public function completeStep(): void
    {
        $this->sanitizeInputs();
        $this->validate();

        $data = [
            'app_name' => $this->formatEnvValue($this->appName),
            'app_url' => $this->appUrl,
            'app_locale' => $this->appLocale,
        ];

        if ($this->isDatabaseRequired) {
            $data = array_merge($data, [
                'db_connection' => $this->dbConnection,
                'db_host' => $this->dbHost,
                'db_port' => $this->dbPort,
                'db_database' => $this->dbDatabase,
                'db_username' => $this->dbUsername,
                'db_password' => $this->formatEnvValue($this->dbPassword),
            ]);
        }

        $this->persistPendingOverrides();

        $this->dispatch('wizard.stepCompleted', ['data' => $data]);
    }

    public function testDatabaseConnection(): void
    {
        $this->sanitizeInputs();
        $this->dbTestStatus = null;
        $this->dbTestMessage = null;

        $this->validate($this->dbFieldRules());

        // Connessione dedicata "installer_test": non tocca la connessione 'mysql'
        // usata più avanti da migrate:fresh, così un test fallito non lascia
        // configurazione sporca dietro di sé.
        config(['database.connections.installer_test' => array_merge(
            config('database.connections.mysql', []),
            [
                'host' => $this->dbHost,
                'port' => $this->dbPort,
                'database' => $this->dbDatabase,
                'username' => $this->dbUsername,
                'password' => $this->dbPassword ?? '',
            ]
        )]);

        try {
            DB::connection('installer_test')->select('select 1');

            $this->dbTestStatus = 'success';
            $this->dbTestMessage = __('installer.database.test_success', ['database' => $this->dbDatabase]);
        } catch (\Throwable $e) {
            $this->dbTestStatus = 'error';
            $this->dbTestMessage = __('installer.database.test_error', ['error' => $e->getMessage()]);
        } finally {
            DB::purge('installer_test');
        }
    }

    /**
     * Salva lingua e nome app scelti nel progress file, letti dal listener
     * MigrationsEnded (App\Providers\AppServiceProvider) per applicarli a
     * GeneralSettings->language/app_name subito dopo che la tabella settings esiste.
     *
     * Necessario perché questo componente step viene sempre eseguito (referenziato
     * per FQCN in config('installer.steps'), non tramite alias Livewire), a differenza
     * di InstallerWizard che durante la prima installazione pulita resta quello del
     * vendor: un fix piazzato lì funzionerebbe solo per gli aggiornamenti.
     */
    private function persistPendingOverrides(): void
    {
        $progressFile = config('installer.options.progress_file');
        $progress = File::exists($progressFile)
            ? (json_decode(File::get($progressFile), true) ?? [])
            : [];

        $progress['pending_locale'] = $this->appLocale;
        $progress['pending_app_name'] = $this->appName;

        File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    private function sanitizeInputs(): void
    {
        $this->appName = trim($this->appName);
        $this->appUrl = trim($this->appUrl);

        if ($this->isDatabaseRequired) {
            $this->dbHost = trim($this->dbHost);
            $this->dbPort = trim($this->dbPort);
            $this->dbDatabase = trim($this->dbDatabase);
            $this->dbUsername = trim($this->dbUsername);
            $this->dbPassword = $this->dbPassword ? trim($this->dbPassword) : null;
        }
    }

    private function formatEnvValue(?string $value): ?string
    {
        if (is_null($value)) {
            return null;
        }
        if (preg_match('/\s/', $value) && ! str_starts_with($value, '"')) {
            return '"'.$value.'"';
        }

        return $value;
    }

    public function render()
    {
        return view('installer.environment-settings');
    }
}

<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
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

    protected function rules(): array
    {
        $rules = [
            'appName'   => 'required|string|max:255',
            'appUrl'    => 'required|string',
            'appLocale' => 'required|string|in:' . implode(',', array_keys(config('installer.available_locales', []))),
        ];

        if ($this->isDatabaseRequired) {
            $rules = array_merge($rules, [
                'dbHost' => 'required|regex:/^\S*$/u',
                'dbPort' => 'required|numeric|regex:/^\S*$/u',
                'dbDatabase' => 'required|min:1|regex:/^\S*$/u',
                'dbUsername' => 'required|min:1|regex:/^\S*$/u',
                'dbPassword' => 'nullable|string',
            ]);
        }

        return $rules;
    }

    public function mount(): void
    {
        $this->isDatabaseRequired = config('installer.requirements.environment.database', false);
        $this->appName = config('installer.app_name', config('app.name', 'Laravel'));
        $this->appLocale = config('app.locale', 'it');

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
            'app_name'   => $this->formatEnvValue($this->appName),
            'app_url'    => $this->appUrl,
            'app_locale' => $this->appLocale,
        ];

        if ($this->isDatabaseRequired) {
            $data = array_merge($data, [
                'db_connection' => $this->dbConnection,
                'db_host'       => $this->dbHost,
                'db_port'       => $this->dbPort,
                'db_database'   => $this->dbDatabase,
                'db_username'   => $this->dbUsername,
                'db_password'   => $this->formatEnvValue($this->dbPassword),
            ]);
        }

        $this->persistPendingOverrides();

        $this->dispatch('wizard.stepCompleted', ['data' => $data]);
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
        if (preg_match('/\s/', $value) && !str_starts_with($value, '"')) {
            return '"' . $value . '"';
        }
        return $value;
    }

    // Nota: Manteniamo il layout originale del pacchetto
    #[Layout('installer::layouts.installer')]
    public function render()
    {
        // Nota: Manteniamo la vista originale del pacchetto
        return view('installer::livewire.install.environment-settings');
    }
}

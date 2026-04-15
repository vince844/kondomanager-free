<?php

namespace App\Livewire\Installer; 

use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

class FixedEnvironmentSettings extends Component
{
    // --- DATABASE PROPERTIES ---
    public bool $isDatabaseRequired = false;
    public string $appUrl = '';
    public string $dbConnection = 'mysql';
    public string $dbHost = '127.0.0.1';
    public string $dbPort = '3306';
    public ?string $dbDatabase = null;
    public ?string $dbUsername = null;
    public ?string $dbPassword = null;

    // --- MAIL PROPERTIES (AGGIUNTE) ---
    public bool $isMailRequired = false;
    public string $mailMailer = 'smtp';
    public ?string $mailHost = '127.0.0.1';
    public ?string $mailPort = '1025';
    public ?string $mailUsername = null;
    public ?string $mailPassword = null;
    public ?string $mailFromAddress = null;
    public ?string $mailFromName = null;

    protected function rules(): array
    {
        $rules = ['appUrl' => 'required|string'];

        if ($this->isDatabaseRequired) {
            $rules = array_merge($rules, [
                'dbHost' => 'required|regex:/^\S*$/u',
                'dbPort' => 'required|numeric|regex:/^\S*$/u',
                'dbDatabase' => 'required|min:1|regex:/^\S*$/u',
                'dbUsername' => 'required|min:1|regex:/^\S*$/u',
                'dbPassword' => 'nullable|string',
            ]);
        }

        if ($this->isMailRequired) {
            $rules = array_merge($rules, [
                'mailMailer' => 'required|string',
                'mailHost' => 'required|string',
                'mailPort' => 'required|numeric',
                'mailFromAddress' => 'nullable|email',
                'mailFromName' => 'nullable|string',
            ]);
        }

        return $rules;
    }

    public function mount(): void
    {
        $this->isDatabaseRequired = config('installer.requirements.environment.database', false);
        $this->isMailRequired = config('installer.requirements.environment.mail', false);

        try {
            $progressFile = config('installer.options.progress_file');
            if (File::exists($progressFile)) {
                $progress = json_decode(File::get($progressFile), true);
                $data = $progress['data']['environment'] ?? [];

                $this->appUrl = $data['app_url'] ?? $this->appUrl;
                
                $this->dbConnection = $data['db_connection'] ?? $this->dbConnection;
                $this->dbHost = $data['db_host'] ?? $this->dbHost;
                $this->dbPort = $data['db_port'] ?? $this->dbPort;
                $this->dbDatabase = $data['db_database'] ?? $this->dbDatabase;
                $this->dbUsername = $data['db_username'] ?? $this->dbUsername;
                $this->dbPassword = $data['db_password'] ?? $this->dbPassword;

                $this->mailMailer = $data['mail_mailer'] ?? $this->mailMailer;
                $this->mailHost = $data['mail_host'] ?? $this->mailHost;
                $this->mailPort = $data['mail_port'] ?? $this->mailPort;
                $this->mailUsername = $data['mail_username'] ?? $this->mailUsername;
                $this->mailPassword = $data['mail_password'] ?? $this->mailPassword;
                $this->mailFromAddress = $data['mail_from_address'] ?? $this->mailFromAddress;
                $this->mailFromName = $data['mail_from_name'] ?? $this->mailFromName;
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

        $data = ['app_url' => $this->appUrl];

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

        if ($this->isMailRequired) {
            $data = array_merge($data, [
                'mail_mailer'       => $this->mailMailer,
                'mail_host'         => $this->mailHost,
                'mail_port'         => $this->mailPort,
                'mail_username'     => $this->formatEnvValue($this->mailUsername),
                'mail_password'     => $this->formatEnvValue($this->mailPassword),
                'mail_from_address' => $this->mailFromAddress,
                'mail_from_name'    => $this->formatEnvValue($this->mailFromName),
            ]);
        }

        $this->dispatch('wizard.stepCompleted', ['data' => $data]);
    }

    private function sanitizeInputs(): void
    {
        $this->appUrl = trim($this->appUrl);

        if ($this->isDatabaseRequired) {
            $this->dbHost = trim($this->dbHost);
            $this->dbPort = trim($this->dbPort);
            $this->dbDatabase = trim($this->dbDatabase);
            $this->dbUsername = trim($this->dbUsername);
            $this->dbPassword = $this->dbPassword ? trim($this->dbPassword) : null;
        }

        if ($this->isMailRequired) {
            $this->mailHost = trim($this->mailHost);
            $this->mailPort = trim($this->mailPort);
            $this->mailUsername = $this->mailUsername ? trim($this->mailUsername) : null;
            $this->mailPassword = $this->mailPassword ? trim($this->mailPassword) : null;
            $this->mailFromAddress = $this->mailFromAddress ? trim($this->mailFromAddress) : null;
            $this->mailFromName = $this->mailFromName ? trim($this->mailFromName) : null;
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
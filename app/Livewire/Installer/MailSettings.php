<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\On;
use Livewire\Component;

class MailSettings extends Component
{
    public bool $isMailRequired = false;

    public string $mailMailer = 'smtp';
    public string $mailHost = '127.0.0.1';
    public string $mailPort = '587';
    public ?string $mailUsername = null;
    public ?string $mailPassword = null;
    public ?string $mailFromAddress = null;
    public ?string $mailFromName = null;

    public ?string $testStatus = null;
    public ?string $testMessage = null;

    private function mailFieldRules(): array
    {
        return [
            'mailMailer' => 'required|string',
            'mailHost' => 'required|string',
            'mailPort' => 'required|numeric',
            'mailUsername' => 'required|string',
            'mailPassword' => 'required|string',
            'mailFromAddress' => 'required|email',
            'mailFromName' => 'required|string',
        ];
    }

    protected function rules(): array
    {
        return $this->isMailRequired ? $this->mailFieldRules() : [];
    }

    protected function validationAttributes(): array
    {
        return [
            'mailMailer' => __('installer.fields.mail_mailer.label'),
            'mailHost' => __('installer.fields.mail_host.label'),
            'mailPort' => __('installer.fields.mail_port.label'),
            'mailUsername' => __('installer.fields.mail_username.label'),
            'mailPassword' => __('installer.fields.mail_password.label'),
            'mailFromAddress' => __('installer.fields.mail_from_address.label'),
            'mailFromName' => __('installer.fields.mail_from_name.label'),
        ];
    }

    public function mount(): void
    {
        $this->isMailRequired = config('installer.requirements.environment.mail', false);

        try {
            $progressFile = config('installer.options.progress_file');
            if (File::exists($progressFile)) {
                $progress = json_decode(File::get($progressFile), true);
                $data = $progress['data']['mail'] ?? [];

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

        $data = [];

        if ($this->isMailRequired) {
            $this->validate();

            $data = [
                'mail_mailer' => $this->mailMailer,
                'mail_host' => $this->mailHost,
                'mail_port' => $this->mailPort,
                'mail_username' => $this->mailUsername,
                'mail_password' => $this->mailPassword,
                'mail_from_address' => $this->mailFromAddress,
                'mail_from_name' => $this->mailFromName,
            ];
        }

        $this->dispatch('wizard.stepCompleted', ['data' => $data]);
    }

    public function sendTestEmail(): void
    {
        $this->sanitizeInputs();
        $this->testStatus = null;
        $this->testMessage = null;

        $this->validate($this->mailFieldRules());

        try {
            config([
                'mail.mailers.smtp.host' => $this->mailHost,
                'mail.mailers.smtp.port' => $this->mailPort,
                'mail.mailers.smtp.username' => $this->mailUsername,
                'mail.mailers.smtp.password' => $this->mailPassword,
                'mail.from.address' => $this->mailFromAddress,
                'mail.from.name' => $this->mailFromName,
            ]);

            // Il MailManager cachea internamente i trasporti già risolti: senza questo
            // forgetInstance riutilizzerebbe un mailer costruito con le credenziali del
            // .env corrente invece di quelle appena inserite nel form.
            app()->forgetInstance('mail.manager');

            Mail::mailer($this->mailMailer)->raw(
                __('installer.mail.test_body'),
                fn($message) => $message->to($this->mailFromAddress)->subject(__('installer.mail.test_subject'))
            );

            $this->testStatus = 'success';
            $this->testMessage = __('installer.mail.test_success', ['email' => $this->mailFromAddress]);
        } catch (\Throwable $e) {
            $this->testStatus = 'error';
            $this->testMessage = __('installer.mail.test_error', ['error' => $e->getMessage()]);
        }
    }

    private function sanitizeInputs(): void
    {
        if (! $this->isMailRequired) {
            return;
        }

        $this->mailMailer = trim($this->mailMailer);
        $this->mailHost = trim($this->mailHost);
        $this->mailPort = trim($this->mailPort);
        $this->mailUsername = $this->mailUsername ? trim($this->mailUsername) : null;
        $this->mailPassword = $this->mailPassword ? trim($this->mailPassword) : null;
        $this->mailFromAddress = $this->mailFromAddress ? trim($this->mailFromAddress) : null;
        $this->mailFromName = $this->mailFromName ? trim($this->mailFromName) : null;
    }

    public function render()
    {
        return view('installer.mail-settings');
    }
}

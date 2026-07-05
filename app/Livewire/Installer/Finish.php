<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\File;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Finish extends Component
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'db_password',
        'mail_password',
    ];

    public array $settings = [];

    public function mount(): void
    {
        try {
            $lockFile = config('installer.options.lock_file');
            $progressFile = config('installer.options.progress_file');

            if (File::exists($progressFile)) {
                $data = json_decode(File::get($progressFile), true)['data'] ?? [];
                $this->settings = $this->redact($data);

                try {
                    File::delete($progressFile);
                } catch (\Exception $e) {
                    $this->dispatch('wizard.error', ['message' => "Failed to delete progress file: {$e->getMessage()}"]);

                    return;
                }
            }

            try {
                File::put($lockFile, now()->toDateTimeString());
            } catch (\Exception $e) {
                $this->dispatch('wizard.error', ['message' => "Failed to create lock file: {$e->getMessage()}"]);

                return;
            }

            $this->dispatch('wizard.canProceed');
        } catch (\Exception $e) {
            $this->dispatch('wizard.error', ['message' => "Failed to finalize installation: {$e->getMessage()}"]);
        }
    }

    #[On('completeStep')]
    public function completeStep()
    {
        $redirect_url = config('installer.options.redirect_after_install', '/');

        return redirect($redirect_url);
    }

    public function downloadSettings(): StreamedResponse
    {
        try {
            $content = "Saved Installation Settings\n".str_repeat('=', 40)."\n\n";
            foreach ($this->settings as $step => $data) {
                $content .= strtoupper($step).":\n";
                $content .= $this->formatData($data, 1);
                $content .= "\n";
            }

            $filename = 'installation_settings_'.now()->format('Y-m-d_H-i-s').'.txt';

            return response()->streamDownload(function () use ($content) {
                echo $content;
            }, $filename, [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('wizard.error', ['message' => "Failed to download settings: {$e->getMessage()}"]);

            return response()->streamDownload(function () use ($e) {
                echo "Error: {$e->getMessage()}";
            }, 'error.txt', ['Content-Type' => 'text/plain']);
        }
    }

    /**
     * Redige i campi sensibili prima che finiscano nello snapshot Livewire
     * pubblico o nel file scaricabile — vedi anche App\Livewire\Installer\CreateAdmin,
     * che ora non include mai la password in chiaro nel payload dispatchato.
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (in_array($key, self::SENSITIVE_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = '••••••••';
            }
        }

        return $data;
    }

    protected function formatData($data, int $indentLevel = 0): string
    {
        $output = '';
        $indent = str_repeat('  ', $indentLevel);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $output .= "{$indent}{$key}:\n";
                $output .= $this->formatData($value, $indentLevel + 1);
            } else {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? 'null');
                $output .= "{$indent}{$key}: {$value}\n";
            }
        }

        return $output;
    }

    public function render()
    {
        return view('installer.finish');
    }
}

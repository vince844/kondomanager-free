<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\File;
use Livewire\Attributes\On;
use Livewire\Component;

class ServerRequirements extends Component
{
    public array $requirements = [];
    public ?string $lastCheckedAt = null;

    public function mount(): void
    {
        $this->checkRequirements();
    }

    public function recheck(): void
    {
        $this->checkRequirements();
    }

    private function checkRequirements(): void
    {
        try {
            $requiredPhp = config('installer.requirements.php', '8.4.0');
            $requiredExtensions = config('installer.requirements.extensions', []);
            $requiredPermissions = config('installer.requirements.permissions', []);

            $this->requirements = [
                'php' => [
                    'current' => PHP_VERSION,
                    'required' => $requiredPhp,
                    'passed' => version_compare(PHP_VERSION, $requiredPhp, '>='),
                ],
                'extensions' => collect($requiredExtensions)->mapWithKeys(fn ($ext) => [$ext => extension_loaded($ext)])->toArray(),
                'permissions' => collect($requiredPermissions)->mapWithKeys(function ($status, $path) {
                    $fullPath = base_path($path);

                    return [
                        $path => [
                            'exists' => File::exists($fullPath),
                            'writable' => File::isWritable($fullPath),
                        ],
                    ];
                })->toArray(),
            ];

            $this->lastCheckedAt = now()->format('H:i:s');

            $this->dispatch($this->checkAllPassed() ? 'wizard.canProceed' : 'wizard.cannotProceed');
        } catch (\Exception $e) {
            $this->dispatch('wizard.cannotProceed');
            $this->dispatch('wizard.error', ['message' => "Failed to check server requirements: {$e->getMessage()}"]);
        }
    }

    #[On('completeStep')]
    public function completeStep(): void
    {
        $this->dispatch('wizard.stepCompleted', ['data' => $this->requirements]);
    }

    protected function checkAllPassed(): bool
    {
        $phpOk = $this->requirements['php']['passed'] ?? false;
        $extsOk = collect($this->requirements['extensions'])->every(fn ($loaded) => $loaded === true);
        $permsOk = collect($this->requirements['permissions'])->every(fn ($info) => $info['exists'] && $info['writable']);

        return $phpOk && $extsOk && $permsOk;
    }

    public function render()
    {
        return view('installer.server-requirements');
    }
}

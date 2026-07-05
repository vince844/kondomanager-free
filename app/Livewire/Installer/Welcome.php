<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class Welcome extends Component
{
    public string $php;

    public ?string $extensions = null;

    public bool $isDatabaseRequired = false;

    public bool $isMailRequired = false;

    public function mount(): void
    {
        try {
            $this->php = config('installer.requirements.php', '8.4.0');
            $this->extensions = implode(', ', config('installer.requirements.extensions', [])) ?: null;
            $this->isDatabaseRequired = config('installer.requirements.environment.database', false);
            $this->isMailRequired = config('installer.requirements.environment.mail', false);

            $this->dispatch('wizard.canProceed');
        } catch (\Exception $e) {
            Log::error('Welcome mount failed: '.$e->getMessage());
            $this->dispatch('wizard.cannotProceed');
            $this->dispatch('wizard.error', ['message' => "Failed to load welcome step: {$e->getMessage()}"]);
        }
    }

    #[On('completeStep')]
    public function completeStep(): void
    {
        $this->dispatch('wizard.stepCompleted');
    }

    public function render()
    {
        return view('installer.welcome');
    }
}

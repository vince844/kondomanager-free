<?php

namespace App\Livewire\Installer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateAdmin extends Component
{
    public ?string $name = null;
    public ?string $email = null;
    public ?string $password = null;
    public ?string $password_confirmation = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|min:2',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => __('installer.fields.admin_name.label'),
            'email' => __('installer.fields.admin_email.label'),
            'password' => __('installer.fields.admin_password.label'),
        ];
    }

    public function mount(): void
    {
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
        $this->validate();

        try {
            $userData = [
                'name' => trim($this->name),
                'email' => trim($this->email),
                'password' => Hash::make($this->password),
            ];

            if (config('installer.options.verify_admin_email', true)) {
                $userData['email_verified_at'] = now();
            }

            $user = User::create($userData);

            $spatieConfig = config('installer.spatie');

            if (($spatieConfig['enabled'] ?? false) && method_exists($user, 'assignRole')) {
                $roleTable = config('permission.table_names.roles', 'roles');
                $roleExists = DB::table($roleTable)
                    ->where('name', $spatieConfig['admin_role'])
                    ->exists();

                if ($roleExists) {
                    $user->assignRole($spatieConfig['admin_role']);
                }
            }

            // Nota: la password non viene mai inclusa nel payload dispatchato — non deve
            // transitare, nemmeno temporaneamente, nel progress file su disco. Lo step
            // Finish non ne ha bisogno per il riepilogo.
            $data = [
                'name' => $user->name,
                'email' => $user->email,
            ];

            $this->dispatch('wizard.stepCompleted', ['data' => $data]);
        } catch (\Exception $e) {
            $this->dispatch('wizard.error', ['message' => "Failed to create admin user: {$e->getMessage()}"]);
        }
    }

    public function render()
    {
        return view('installer.create-admin');
    }
}

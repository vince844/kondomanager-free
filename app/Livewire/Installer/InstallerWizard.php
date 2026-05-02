<?php

namespace App\Livewire\Installer;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Illuminate\Database\Seeder;

/**
 * Custom InstallerWizard — Override del package eii/installer
 *
 * SCOPO: Usato SOLO per gli aggiornamenti (non per la prima installazione pulita).
 *
 * DIFFERENZE rispetto all'originale (Eii\Installer\Livewire\Install\InstallerWizard):
 *
 * 1. FIX SOTTO-PROCESSI: updateEnvSettings() viene chiamata PRIMA di migrate e seed,
 *    così i sotto-processi Artisan leggono sempre le credenziali corrette dal .env.
 *
 * 2. FIX SPATIE CACHE: Purge della cache Spatie Permission dopo migrate:fresh.
 *    Necessario perché su DB esistente la cache contiene permessi stale
 *    dopo migrate:fresh, causando errori silenti nel seeder.
 *    Fix proposto come PR al package eii/installer.
 *
 * NOTA: Per la prima installazione pulita, Livewire usa il file originale del package
 * a causa del checksum nel snapshot DOM. Questo file viene attivato tramite
 * AppServiceProvider::boot() → Livewire::component() solo per gli aggiornamenti.
 */
class InstallerWizard extends Component
{
    public string $stepKey;
    public Collection $steps;
    public int $currentIndex = 0;
    public array $progress = [];
    public bool $canProceed = true;
    public bool $skippable = false;
    public bool $showWaitScreen = false;

    public function mount(string $step)
    {
        $this->steps = collect(config('installer.steps'));
        $this->stepKey = $step;
        $this->currentIndex = $this->steps->search(fn($s) => $s['key'] === $this->stepKey);
        $this->skippable = $this->steps[$this->currentIndex]['optional'] ?? false;

        if ($this->currentIndex === false) {
            abort(404, "Invalid installer step.");
        }

        $this->loadProgress();

        if (isset($this->progress['raw_env_data'])) {
            if (isset($this->progress['error'])) {
                session()->flash('installer.error', $this->progress['error']);
                unset($this->progress['error']);
                $this->saveProgress();
            } else {
                $this->progress['data']['environment'] = $this->progress['raw_env_data'];
                unset($this->progress['raw_env_data']);
                $this->progress['current_step'] = "environment";
                $this->saveProgress();

                $savedIndex = $this->steps->search(fn($s) => $s['key'] === 'environment');
                $nextStepRoute = $this->steps[$savedIndex + 1]['key'];
                return $this->redirect(route('install.step', $nextStepRoute));
            }
        }

        $savedStep = $this->progress['current_step'] ?? null;
        $savedIndex = $this->steps->search(fn($s) => $s['key'] === $savedStep);

        if ($savedIndex !== false && $this->currentIndex > $savedIndex + 1) {
            $nextStepRoute = $this->steps[$savedIndex + 1]['key'];
            return $this->redirect(route('install.step', $nextStepRoute));
        }
    }

    #[On('wizard.error')]
    public function handleError(array $payload): void
    {
        session()->flash('installer.error', $payload['message']);
        $this->canProceed = false;
    }

    #[On('wizard.canProceed')]
    public function enableNext(): void
    {
        $this->canProceed = true;
    }

    #[On('wizard.cannotProceed')]
    public function disableNext(): void
    {
        $this->canProceed = false;
    }

    public function completeStep(): void
    {
        $this->dispatch('completeStep', $this->stepKey);
    }

    public function skipStep(): void
    {
        $this->saveStep();
    }

    #[Layout('installer::layouts.installer')]
    public function render()
    {
        return view('installer::livewire.install.installer-wizard', [
            'step' => $this->steps[$this->currentIndex],
            'steps' => $this->steps,
            'currentIndex' => $this->currentIndex,
        ]);
    }

    #[On('wizard.stepCompleted')]
    public function saveStep($payload = [])
    {
        $this->showWaitScreen = true;

        $progressFile = config('installer.options.progress_file');
        $progress = File::exists($progressFile)
            ? json_decode(File::get($progressFile), true)
            : ['data' => []];

        try {
            if ($this->stepKey === "environment") {
                $this->runEnvironmentSetup($payload['data']);
            }

            if ($this->stepKey === "mail") {
                $this->runMailSetup($payload['data'] ?? []);
            }

            if (!empty($payload)) {
                $progress['data'][$this->stepKey] = $payload;
            }

            $progress['current_step'] = $this->stepKey;
            File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT));

            $nextStepKey = $this->getNextStepKey();
            $redirectUrl = config('installer.options.redirect_after_install', '/');

            return $this->redirect(route('install.step', $nextStepKey ?? $redirectUrl));

        } catch (\Throwable $th) {
            Log::error("Installer Failed at step [{$this->stepKey}]: " . $th->getMessage(), [
                'exception' => $th
            ]);

            $friendlyMessage = $this->friendlyErrorMessage($th); 
            $progress['error'] = $friendlyMessage;
            File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
            $this->showWaitScreen = false;
            session()->flash('installer.error', $friendlyMessage);
        }
    }

    private function getNextStepKey(): ?string
    {
        return $this->steps[$this->currentIndex + 1]['key'] ?? null;
    }

    private function loadProgress(): void
    {
        $progressFile = config('installer.options.progress_file');
        $this->progress = File::exists($progressFile)
            ? json_decode(File::get($progressFile), true)
            : [
                'current_step' => $this->steps[0]['key'],
                'data' => [],
            ];
        $this->saveProgress();
    }

    private function saveProgress(): void
    {
        File::put(config('installer.options.progress_file'), json_encode($this->progress, JSON_PRETTY_PRINT));
    }

    private function runEnvironmentSetup(array $data): void
    {
        if (config('installer.requirements.environment.database')) {
            if (empty($data['db_database'])) {
                throw new \Exception("DB_DATABASE is missing. Please provide a database name.");
            }

            // =====================================================
            // STEP 1: Scrivi subito il .env con le credenziali reali.
            // Deve essere il PRIMO passo — così migrate:fresh e db:seed
            // leggono sempre le credenziali corrette dal .env anche
            // nei sotto-processi Artisan che rileggono il file dal disco.
            // =====================================================
            $this->updateEnvSettings($data);

            Artisan::call('config:clear');
            config([
                'database.connections.mysql.host'     => $data['db_host'],
                'database.connections.mysql.port'     => $data['db_port'] ?? 3306,
                'database.connections.mysql.database' => $data['db_database'],
                'database.connections.mysql.username' => $data['db_username'],
                'database.connections.mysql.password' => $data['db_password'],
            ]);
            DB::purge('mysql');
            DB::reconnect('mysql');

            // Verifica connessione
            try {
                $dbName = DB::connection()->getDatabaseName();
                if (empty($dbName) || $dbName !== $data['db_database']) {
                    throw new \Exception("Database '{$data['db_database']}' does not exist or cannot be accessed.");
                }
            } catch (\Exception $e) {
                Log::error("Database connection failed: " . $e->getMessage());
                throw new \Exception("We couldn't connect to your database. Please check your credentials.");
            }

            $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);
            if ($exitCode !== 0) {
                throw new \Exception("Database migration failed.");
            }

            // Ripristina connessione dopo migrate:fresh che può resettarla
            DB::purge('mysql');
            DB::reconnect('mysql');

            // =====================================================
            // FIX SPATIE PERMISSION CACHE
            // Dopo migrate:fresh il DB è vuoto ma la cache Spatie
            // contiene ancora i permessi precedenti. Senza questo
            // purge il seeder trova dati stale e può fallire silenziosamente.
            // Questo fix è stato proposto come PR al package eii/installer.
            // =====================================================
            if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            }
            Artisan::call('cache:clear');

            // Seed — usa la chiave seeding.enabled per compatibilità
            // con il package originale eii/installer
            $seedingConfig = config('installer.requirements.seeding');

            if ($seedingConfig && ($seedingConfig['enabled'] ?? false)) {
                DB::beginTransaction();

                try {
                    $classes = $seedingConfig['classes'] ?? [];

                    if (empty($classes)) {
                        $seedExitCode = Artisan::call('db:seed', ['--force' => true]);
                        if ($seedExitCode !== 0) {
                            throw new \Exception("Default seeding failed: " . Artisan::output());
                        }
                    } else {
                        foreach ($classes as $class) {
                            if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
                                $seedExitCode = Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
                                if ($seedExitCode !== 0) {
                                    throw new \Exception("Seeding failed for class [{$class}]: " . Artisan::output());
                                }
                            } else {
                                Log::warning("Installer: Seeder class [{$class}] not found or invalid. Skipping.");
                            }
                        }
                    }

                    DB::commit();

                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error("Seeding rolled back: " . $e->getMessage());
                    throw $e;
                }
            }
        }

        if (config('installer.requirements.link_storage')) {
            try {
                Artisan::call('storage:link');
            } catch (\Exception $e) {
                Log::error("Storage link creation failed: " . $e->getMessage());
                throw new \Exception("Storage link creation failed.");
            }
        }

        // NON richiamare updateEnvSettings qui — già fatto all'inizio del metodo
        $this->progress['raw_env_data'] = $data;
        $this->saveProgress();
    }

    private function runMailSetup(array $data): void
    {
        $this->progress['raw_env_data'] = $data;
        $this->saveProgress();
        $this->updateMailSettings($data);
    }

    private function updateEnvSettings(array $data): void
    {
        $envPath = base_path('.env');
        $env = File::exists($envPath) ? File::get($envPath) : '';
        $quoteIfNeeded = fn($value) => preg_match('/\s/', $value ?? '') ? '"' . addslashes($value) . '"' : $value;

        $pairs = [
            'APP_NAME'      => $quoteIfNeeded(config('installer.app_name', 'Kondomanager')),
            'APP_ENV'       => config('installer.requirements.environment.production') ? 'production' : 'local',
            'APP_DEBUG'     => config('installer.requirements.environment.debug') ? 'true' : 'false',
            'APP_URL'       => $data['app_url'] ?? '',
            'DB_CONNECTION' => $data['db_connection'] ?? 'mysql',
            'DB_HOST'       => $data['db_host'] ?? '127.0.0.1',
            'DB_PORT'       => $data['db_port'] ?? '3306',
            'DB_DATABASE'   => $data['db_database'] ?? '',
            'DB_USERNAME'   => $data['db_username'] ?? '',
            'DB_PASSWORD'   => $data['db_password'] ?? '',
        ];

        foreach ($pairs as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $env = preg_match($pattern, $env)
                ? preg_replace($pattern, "{$key}={$value}", $env)
                : $env . PHP_EOL . "{$key}={$value}";
        }

        File::put($envPath, trim($env));
    }

    private function updateMailSettings(array $data): void
    {
        $envPath = base_path('.env');
        $env = File::exists($envPath) ? File::get($envPath) : '';
        $quoteIfNeeded = fn($value) => preg_match('/\s/', $value ?? '') ? '"' . addslashes($value) . '"' : $value;

        $pairs = [
            'MAIL_MAILER'       => $data['mail_mailer'] ?? 'smtp',
            'MAIL_HOST'         => $data['mail_host'] ?? '',
            'MAIL_PORT'         => $data['mail_port'] ?? '',
            'MAIL_USERNAME'     => $data['mail_username'] ?? '',
            'MAIL_PASSWORD'     => $data['mail_password'] ?? '',
            'MAIL_FROM_ADDRESS' => $data['mail_from_address'] ?? '',
            'MAIL_FROM_NAME'    => $quoteIfNeeded($data['mail_from_name'] ?? ''),
        ];

        foreach ($pairs as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $env = preg_match($pattern, $env)
                ? preg_replace($pattern, "{$key}={$value}", $env)
                : $env . PHP_EOL . "{$key}={$value}";
        }

        File::put($envPath, trim($env));
    }

    private function friendlyErrorMessage(\Throwable $th): string
    {
        $msg = $th->getMessage();

        if ($th instanceof \Exception && !str_contains($msg, 'SQLSTATE') && !str_contains($msg, 'PDOException')) {
            return $msg;
        }

        if (str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Connection refused')) {
            return "Unable to connect to the database. Please check your credentials and make sure the database exists.";
        }

        if (str_contains($msg, 'migrate')) return "Database migration failed.";
        if (str_contains($msg, 'seed'))    return "Database seeding failed.";
        if (str_contains($msg, '.env'))    return "There was a problem updating the environment file.";

        return "An unexpected error occurred.";
    }
}
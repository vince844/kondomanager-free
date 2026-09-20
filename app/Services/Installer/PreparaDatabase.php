<?php

namespace App\Services\Installer;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il passo «ambiente» del wizard, senza il wizard: migrazioni, pulizia della cache dei permessi,
 * seeder. Lo usano sia `InstallerWizard::runEnvironmentSetup()` (con `migrate:fresh`, come ha
 * sempre fatto: il wizard lavora su un database che dichiara vuoto) sia `km:install` (con
 * `migrate`, che su un database vuoto è la stessa cosa e su uno già installato non distrugge
 * nulla). Una logica sola: un installer che ne duplica un'altra resta indietro al primo cambio.
 */
class PreparaDatabase
{
    /**
     * @throws \RuntimeException se una migrazione fallisce
     */
    public function migra(bool $fresh): void
    {
        $comando = $fresh ? 'migrate:fresh' : 'migrate';
        $exitCode = Artisan::call($comando, ['--force' => true]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Database migration failed.');
        }

        // migrate:fresh può resettare la connessione: si riapre prima di continuare (come il
        // wizard ha sempre fatto). Solo in quel caso: un purge su un sqlite in memoria — la
        // suite — butterebbe via lo schema appena migrato.
        if ($fresh) {
            DB::purge(DB::getDefaultConnection());
            DB::reconnect(DB::getDefaultConnection());
        }
    }

    /**
     * Dopo migrate:fresh il database è vuoto ma la cache di Spatie contiene ancora i permessi di
     * prima: senza questo purge il seeder trova dati stale e può fallire in silenzio (fix già
     * proposto come PR al pacchetto eii/installer).
     */
    public function pulisciCache(): void
    {
        if (class_exists(PermissionRegistrar::class)) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }

        Artisan::call('cache:clear');
    }

    /** I soli ruoli e permessi: serve a chi deve creare l'amministratore PRIMA del resto dei seeder. */
    public function seedRuoli(): void
    {
        $exitCode = Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Seeding failed for class ['.RolesAndPermissionsSeeder::class.']: '.Artisan::output());
        }
    }

    /**
     * I seeder come li configura `config/installer.php` (`requirements.seeding`): il
     * DatabaseSeeder se l'elenco delle classi è vuoto, altrimenti classe per classe. Tutto in
     * una transazione, come nel wizard: un seeder a metà non lascia tabelle mezze piene.
     */
    public function seed(): void
    {
        $seedingConfig = config('installer.requirements.seeding');

        if (! $seedingConfig || ! ($seedingConfig['enabled'] ?? false)) {
            return;
        }

        DB::beginTransaction();

        try {
            $classes = $seedingConfig['classes'] ?? [];

            if (empty($classes)) {
                $seedExitCode = Artisan::call('db:seed', ['--force' => true]);
                if ($seedExitCode !== 0) {
                    throw new \RuntimeException('Default seeding failed: '.Artisan::output());
                }
            } else {
                foreach ($classes as $class) {
                    if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
                        $seedExitCode = Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
                        if ($seedExitCode !== 0) {
                            throw new \RuntimeException("Seeding failed for class [{$class}]: ".Artisan::output());
                        }
                    } else {
                        Log::warning("Installer: Seeder class [{$class}] not found or invalid. Skipping.");
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Seeding rolled back: '.$e->getMessage());
            throw $e;
        }
    }

    /** `storage:link` se la configurazione lo chiede; se il collegamento esiste già non è un errore. */
    public function collegaStorage(): void
    {
        if (! config('installer.requirements.link_storage')) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (\Exception $e) {
            Log::error('Storage link creation failed: '.$e->getMessage());
            throw new \RuntimeException('Storage link creation failed.');
        }
    }
}

<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SegnalazionePolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Queue\Events\JobProcessing;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use App\Livewire\Installer\InstallerWizard;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ====================================================================
        // OVERRIDE INSTALLER WIZARD (Fix Spatie Permission Cache)
        // ====================================================================
        // IMPORTANTE: Questo override funziona SOLO per gli aggiornamenti.
        //
        // PRIMA INSTALLAZIONE PULITA:
        //   Livewire usa il file originale del package (Eii\Installer\...)
        //   perché il checksum del snapshot nel DOM contiene il nome originale.
        //   Il seed gira correttamente tramite config('installer.requirements.seeding').
        //
        // AGGIORNAMENTI (migrate su DB esistente):
        //   Qui l'override viene applicato e il nostro InstallerWizard custom
        //   viene usato al posto dell'originale. Il fix aggiunge il purge della
        //   cache Spatie Permission PRIMA del seed, necessario perché la cache
        //   contiene ancora i permessi del DB precedente alle migrazioni.
        //   Senza questo purge, Spatie legge dati stale e il seed può fallire.
        //
        // ====================================================================
        if (config('installer.run_installer') && class_exists(Livewire::class) && class_exists(InstallerWizard::class)) {
            Livewire::component('eii.installer.livewire.install.installer-wizard', InstallerWizard::class);
        }

        // ====================================================================
        // FIX HTTPS (Mixed Content per Reverse Proxy come Altervista/Cloudflare)
        // ====================================================================

        // Se nel .env l'APP_URL inizia con https://, forziamo gli asset in HTTPS
        if (config('app.url') && str_contains(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // ====================================================================
        // GESTIONE PROXY (Spostata in bootstrap/app.php per standard Laravel 11)
        // ====================================================================

        // Sincronizza la versione dopo ogni migrazione
        Event::listen(MigrationsEnded::class, function () {
            try {
                $settings = app(GeneralSettings::class);
                $settings->version = config('app.version');

                // ====================================================================
                // LINGUA / NOME APP SCELTI IN INSTALLAZIONE (FixedEnvironmentSettings)
                // ====================================================================
                // APP_LOCALE/APP_NAME nel .env non bastano: i valori realmente usati a
                // runtime vengono letti da GeneralSettings (language, app_name) tramite
                // SetLocaleMiddleware/SetAppNameMiddleware, che sovrascrivono la config
                // in memoria su ogni richiesta. Le settings-migration inseriscono sempre
                // un default fisso ('it', config('app.name')). Qui applichiamo la scelta
                // reale dell'utente, salvata nel progress file PRIMA di migrate:fresh
                // (quindi disponibile appena la tabella settings esiste), e puliamo
                // subito i marker per non ri-applicarli su migrazioni future non collegate
                // all'installer (es. update dell'app), il che resetterebbe silenziosamente
                // una lingua o un nome cambiati nel frattempo dall'amministratore tramite
                // Impostazioni generali.
                $progressFile = config('installer.options.progress_file');
                if (File::exists($progressFile)) {
                    $progress = json_decode(File::get($progressFile), true) ?? [];
                    $dirty = false;

                    if (!empty($progress['pending_locale'])) {
                        $settings->language = $progress['pending_locale'];
                        unset($progress['pending_locale']);
                        $dirty = true;
                    }

                    if (!empty($progress['pending_app_name'])) {
                        $settings->app_name = $progress['pending_app_name'];
                        unset($progress['pending_app_name']);
                        $dirty = true;
                    }

                    if ($dirty) {
                        File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
                    }
                }

                $settings->save();
            } catch (\Exception $e) {
                // Ignora se settings non è ancora configurato
                // (prima installazione in corso)
            }
        });

        // ====================================================================
        // NOME APP NEI JOB IN CODA
        // ====================================================================
        // SetAppNameMiddleware copre solo le richieste web: i job in coda (es.
        // notifiche email inviate tramite ShouldQueue) girano in un processo
        // worker separato, senza middleware HTTP. Qui applichiamo lo stesso
        // override di config('app.name') prima che ogni job venga eseguito,
        // così oggetto/corpo delle email (config('app.name') nelle notification
        // e nei template mail di Laravel) riflettono il nome personalizzato.
        Queue::before(function (JobProcessing $event) {
            try {
                $settings = app(GeneralSettings::class);
                if (!empty($settings->app_name)) {
                    config(['app.name' => $settings->app_name]);
                }
            } catch (\Throwable $e) {
                // Se il DB non risponde, resta il valore da .env
            }
        });

        JsonResource::withoutWrapping();
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);
    }
}
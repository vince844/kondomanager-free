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
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
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
        // GESTIONE PROXY E HEADER DI RICHIESTA (X-Forwarded-*)
        // ====================================================================
        
        // 1. Legge la config. Se manca nel .env, restituisce NULL.
        $trustedProxies = config('app.trusted_proxies');

        // 2. Esegue solo se c'è una configurazione attiva
        if ($trustedProxies) {
            
            // Definiamo quali header guardare (Standard Laravel/Symfony)
            $headers = Request::HEADER_X_FORWARDED_FOR |
                       Request::HEADER_X_FORWARDED_HOST |
                       Request::HEADER_X_FORWARDED_PORT |
                       Request::HEADER_X_FORWARDED_PROTO |
                       Request::HEADER_X_FORWARDED_AWS_ELB;

            // Trasformiamo la config in array per Symfony
            if ($trustedProxies === '*') {
                // Wildcard per IPv4 e IPv6 (Fidati di tutto Internet)
                $proxies = ['0.0.0.0/0', '::/0']; 
            } else {
                // Lista IP specifici
                $proxies = is_array($trustedProxies) 
                    ? $trustedProxies 
                    : array_map('trim', explode(',', $trustedProxies));
            }

            // Applica la configurazione alla Request globale
            Request::setTrustedProxies($proxies, $headers);
        }
        
        // ====================================================================

        // Sincronizza la versione dopo ogni migrazione
        Event::listen(MigrationsEnded::class, function () {
            try {
                $settings = app(GeneralSettings::class);
                $settings->version = config('app.version');
                $settings->save();
            } catch (\Exception $e) {
                // Ignora se settings non è ancora configurato
                // (prima installazione in corso)
            }
        });

        JsonResource::withoutWrapping();
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);
    }
}
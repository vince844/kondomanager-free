<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SegnalazionePolicy;
use App\Settings\GeneralSettings;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        $this->suppressReadonlyTouchWarning();

        // ====================================================================
        // ROTTE INSTALLER
        // ====================================================================
        // Registrate qui (non in bootstrap/app.php withRouting 'then') perché
        // usano la macro Route::livewire(), disponibile solo dopo che tutti i
        // provider hanno completato la fase register() — incluso quello di
        // Livewire. Durante "composer install/update" (artisan package:discover)
        // il closure 'then' di withRouting gira troppo presto e la macro non
        // esiste ancora, causando un errore. loadRoutesFrom() in boot() non ha
        // questo problema: è lo stesso schema già usato dal vendor eii/installer.
        $this->loadRoutesFrom(base_path('routes/installer.php'));

        // ====================================================================
        // FIX HTTPS (Mixed Content per Reverse Proxy come Altervista/Cloudflare)
        // ====================================================================

        // Se nel .env l'APP_URL inizia con https://, forziamo gli asset in HTTPS.
        // Condizionato allo schema reale della richiesta: forzare SEMPRE, anche
        // quando la richiesta corrente risulta genuinamente http, genera un
        // mismatch tra lo schema della pagina e quello degli endpoint Livewire/
        // asset generati — il browser blocca queste richieste come cross-origin
        // (schema diverso = origin diversa). Osservato realmente su Altervista:
        // pagina servita in http, endpoint Livewire forzato in https, richieste
        // bloccate con errore CORS pur rispondendo 200.
        //
        // NON basta request()->isSecure(): su hosting dietro reverse proxy che
        // termina TLS (Altervista, Cloudflare) rileva lo schema tramite proxy
        // fidato solo se il middleware TrustProxies ha già processato QUESTA
        // richiesta — ma TrustProxies gira nella pipeline dei middleware, DOPO
        // che tutti i Service Provider (questo incluso) hanno già completato
        // boot() (vedi Illuminate\Foundation\Http\Kernel::sendRequestThroughRouter:
        // bootstrap() prima, Pipeline::through($middleware) dopo). Quindi qui
        // isSecure() non può MAI riflettere un proxy fidato, indipendentemente
        // da TRUSTED_PROXIES. Leggiamo perciò l'header diretto, bypassando il
        // meccanismo di trust: accettabile perché il rischio di spoofing è solo
        // estetico (schema sbagliato negli URL generati), non viene usato per
        // decisioni di autenticazione o IP. Verificato su hosting reale
        // (Altervista): nessun header X-Forwarded-Proto/Ssl viene mai visto da
        // TrustProxies in questo punto, qualunque sia TRUSTED_PROXIES.
        $isForwardedHttps = request()->header('X-Forwarded-Proto') === 'https'
            || request()->header('X-Forwarded-Ssl') === 'on';

        // In console (job in coda, comandi artisan) non c'è una request da cui
        // rilevare lo schema reale, quindi si forza comunque in base a APP_URL.
        if (config('app.url') && str_contains(config('app.url'), 'https://')) {
            if ($this->app->runningInConsole() || request()->isSecure() || $isForwardedHttps) {
                URL::forceScheme('https');
            }
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

                    if (! empty($progress['pending_locale'])) {
                        $settings->language = $progress['pending_locale'];
                        unset($progress['pending_locale']);
                        $dirty = true;
                    }

                    if (! empty($progress['pending_app_name'])) {
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
                if (! empty($settings->app_name)) {
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

    /**
     * Alcuni hosting condivisi molto restrittivi (es. Altervista) negano la
     * modifica del mtime (utime) sui file già esistenti. Laravel chiama
     * touch() internamente come pura ottimizzazione della cache delle view
     * compilate (Blade/Livewire) — evita di ricalcolare l'hash del sorgente
     * ad ogni richiesta quando il file compilato è già valido. Se touch()
     * fallisce, PHP solleva un warning che il gestore errori di Laravel
     * converte in eccezione fatale, anche se la cache compilata resta
     * perfettamente valida e utilizzabile. Sopprimiamo SOLO questo avviso
     * specifico, lasciando invariata la gestione di tutti gli altri
     * errori/warning da parte di Laravel.
     */
    private function suppressReadonlyTouchWarning(): void
    {
        $previousHandler = set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previousHandler) {
            if ($errno === E_WARNING && str_contains($errstr, 'touch(): Utime failed')) {
                return true;
            }

            return $previousHandler
                ? $previousHandler($errno, $errstr, $errfile, $errline)
                : false;
        });
    }
}

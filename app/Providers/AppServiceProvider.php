<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SegnalazionePolicy;
use App\Settings\GeneralSettings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Illuminate\Support\Facades\DB;

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
        JsonResource::withoutWrapping();
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);

        try {
            // Test if DB connection works
            DB::connection()->getPdo();

            if (Schema::hasTable('settings')) {
                try {
                    $settings = app(GeneralSettings::class);
                    app()->setLocale($settings->language ?? 'it');
                } catch (MissingSettings $e) {
                    app()->setLocale('it');
                }
            } else {
                app()->setLocale('it');
            }
        } catch (\Throwable $e) {
            // No DB connection yet → just use fallback locale
            app()->setLocale('it');
        }
    }
}

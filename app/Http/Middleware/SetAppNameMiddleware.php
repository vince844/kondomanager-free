<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Settings\GeneralSettings;

class SetAppNameMiddleware
{
    /**
     * Sovrascrive config('app.name') con il valore scelto dall'amministratore
     * in Impostazioni generali (GeneralSettings->app_name), così il titolo
     * della scheda del browser, il prop Inertia condiviso e le email
     * transazionali riflettono il nome personalizzato invece del valore
     * statico letto da APP_NAME nel .env.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = app(GeneralSettings::class);
            if (!empty($settings->app_name)) {
                config(['app.name' => $settings->app_name]);
            }
        } catch (\Throwable $e) {
            // Se il DB non risponde o la tabella non esiste, resta il valore da .env
        }

        return $next($request);
    }
}

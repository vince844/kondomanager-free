<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\App;
use App\Settings\GeneralSettings;

class SetLocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Laravel Settings carica automaticamente i dati dal DB
            $settings = app(GeneralSettings::class);
            $locale = $settings->language ?? 'it';
        } catch (\Throwable $e) {
            // Se il DB non risponde o la tabella non esiste, fallback sicuro
            $locale = 'it';
        }

        App::setLocale($locale);

        return $next($request);
    }
}

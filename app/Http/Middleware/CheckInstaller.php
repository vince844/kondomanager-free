<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class CheckInstaller
{
    /**
     * Guardia delle rotte /install: blocca l'accesso se il wizard è disattivato
     * o se l'installazione è già stata completata (storage/installed.lock).
     *
     * Imposta anche la lingua dei testi del wizard stesso (non quella scelta
     * dall'utente per l'app installata, che è un concetto separato — vedi
     * App\Livewire\Installer\FixedEnvironmentSettings): rilevata dall'header
     * Accept-Language del browser, italiano di default, inglese come fallback.
     * Non si può usare SetLocaleMiddleware qui perché legge da GeneralSettings,
     * e il database non esiste ancora durante l'installazione.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('installer.run_installer')) {
            return redirect('/');
        }

        $lockFile = config('installer.options.lock_file');

        if (File::exists($lockFile)) {
            return redirect('/');
        }

        App::setLocale($request->getPreferredLanguage(['it', 'en']) ?? 'it');

        return $next($request);
    }
}

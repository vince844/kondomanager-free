<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Settings\GeneralSettings; 
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CheckExternalCron
{
    protected $settings;

    public function __construct(GeneralSettings $settings)
    {
        $this->settings = $settings;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Verifica se abilitato nelle impostazioni
        if (!$this->settings->external_cron_enabled) {
            abort(404);
        }

        // 2. RECUPERO DATI PRELIMINARE (IP e Liste)
        $cachedIps = Cache::get('cronjob_allowed_ips', [
            '116.203.134.67', 
            '116.203.129.16', 
            '23.88.105.37', 
            '128.140.8.200', 
            '91.99.23.109'
        ]);
        
        // Fix per Localhost / Testing
        if (app()->environment('local')) {
            $cachedIps = array_merge($cachedIps, ['127.0.0.1', '::1']);
        }

        $incomingIp = $request->ip();

        // 3. VERIFICA TOKEN (Con Logica di Sicurezza Avanzata)
        // hash_equals: confronto a tempo costante, evita timing attack sul token.
        if (! hash_equals((string) $this->settings->external_cron_token, (string) $request->query('token'))) {
            
            // SECURITY CHECK: BRUTE FORCE DETECTION
            if (!in_array($incomingIp, $cachedIps)) {
                
                $attackKey = 'cron_brute_force:' . $incomingIp;

                if (RateLimiter::tooManyAttempts($attackKey, 5)) {
                    Log::critical("SECURITY ALERT: Rilevato Brute-Force su Cron Job!", [
                        'ip' => $incomingIp,
                        'user_agent' => $request->userAgent(),
                        'wrong_token' => $request->query('token'), 
                        'headers' => $request->headers->all(),
                    ]);
                }

                RateLimiter::hit($attackKey, 3600);
            }

            abort(401, 'Token di sicurezza non valido.');
        }

        // 4. CONTROLLO PRIMARIO: L'IP è già conosciuto?
        if (in_array($incomingIp, $cachedIps)) {
            return $next($request); 
        }

        // ====================================================================
        // 5. ZONA DI EMERGENZA (Auto-Healing)
        // ====================================================================
        
        $rateKey = "cron_live_check:{$incomingIp}";

        if (Cache::has($rateKey)) {
            Log::warning("CronJob: Rate limit verifica live attivo per {$incomingIp}");
            abort(429, 'Troppe richieste di verifica (Rate Limit Interno).');
        }

        Cache::put($rateKey, true, now()->addMinutes(2));

        Log::warning("CronJob: IP sconosciuto {$incomingIp} con Token valido. Avvio verifica live...");

        try {
            /** @var \Illuminate\Http\Client\Response $response */ 
            $response = Http::timeout(5)->get('https://api.cron-job.org/executor-nodes.json');

            if ($response->successful()) {
                $data = $response->json();
                
                if (!isset($data['ipAddresses']) || !is_array($data['ipAddresses'])) {
                    throw new \Exception("Risposta API malformata o struttura cambiata.");
                }

                $liveIps = $data['ipAddresses'];

                if (in_array($incomingIp, $liveIps)) {
                    
                    // Aggiorniamo cache
                    $newCache = array_unique(array_merge($liveIps, [
                        '116.203.134.67', '116.203.129.16', '23.88.105.37', '128.140.8.200', '91.99.23.109'
                    ]));
                    
                    Cache::put('cronjob_allowed_ips', $newCache, now()->addDays(7));
                    
                    Log::info("CronJob: Auto-Healing completato. IP {$incomingIp} autorizzato.");
                    
                    return $next($request);
                }
            }
        } catch (\Exception $e) {
            Log::error("CronJob: Errore verifica live: " . $e->getMessage());
        }

        Log::critical("CronJob: Accesso bloccato. IP non riconosciuto: {$incomingIp}");
        abort(403, 'Indirizzo IP non autorizzato.');
    }
}
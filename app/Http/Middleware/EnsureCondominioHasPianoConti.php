<?php

namespace App\Http\Middleware;

use App\Models\Condominio;
use App\Services\CondominioService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCondominioHasPianoConti
{
    /**
     * Garantisce che il condominio abbia i conti contabili di sistema (Cassa, Crediti, ecc.).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $condominio = $request->route('condominio');

        // Eseguiamo il controllo solo se abbiamo un'istanza valida di Condominio
        if ($condominio instanceof Condominio) {
            try {
                // Deleghiamo la logica al Service (così il codice è riutilizzabile)
                app(CondominioService::class)->ensureDefaultConti($condominio);
                
            } catch (\Throwable $e) {
                // Logghiamo l'errore ma non blocchiamo l'utente, 
                // altrimenti un errore nel seed dei conti bloccherebbe tutto il gestionale.
                Log::error("Middleware PianoConti Error per '{$condominio->nome}': " . $e->getMessage());
            }
        }

        return $next($request);
    }
}
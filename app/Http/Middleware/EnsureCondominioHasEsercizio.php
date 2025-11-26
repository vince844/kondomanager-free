<?php

namespace App\Http\Middleware;

use App\Models\Condominio;
use App\Services\CondominioService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCondominioHasEsercizio
{
    /**
     * Garantisce che ogni condominio abbia almeno un esercizio aperto.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $condominio = $request->route('condominio');

        // Se la route usa il binding, Laravel fornisce già un'istanza di Condominio
        if ($condominio instanceof Condominio) {
            try {
                // Se il condominio non ha ancora esercizi, creane uno
                if ($condominio->esercizi()->doesntExist()) {
                    Log::info("Nessun esercizio trovato per '{$condominio->nome}', creazione automatica in corso...");

                    DB::transaction(function () use ($condominio) {
                        app(CondominioService::class)->createEsercizioForCondominio($condominio);
                    });

                    Log::info("Esercizio creato correttamente per '{$condominio->nome}'");
                }
            } catch (\Throwable $e) {
                Log::error("Errore durante la creazione dell'esercizio per '{$condominio->nome}': " . $e->getMessage());
            }
        }

        return $next($request);
    }
}

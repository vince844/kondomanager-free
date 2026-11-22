<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Middleware;

use App\Services\RegistroModifiche\Autore;
use App\Services\RegistroModifiche\RegistroModifiche;
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
                // Li crea il programma, non chi ha aperto la pagina: il registro delle modifiche firma «Il programma».
                RegistroModifiche::comeAutore(Autore::programma('pagina', 'creato in automatico all\'apertura del gestionale del condominio'),
                    fn () => app(CondominioService::class)->ensureDefaultConti($condominio));
                
            } catch (\Throwable $e) {
                // Logghiamo l'errore ma non blocchiamo l'utente, 
                // altrimenti un errore nel seed dei conti bloccherebbe tutto il gestionale.
                Log::error("Middleware PianoConti Error per '{$condominio->nome}': " . $e->getMessage());
            }
        }

        return $next($request);
    }
}
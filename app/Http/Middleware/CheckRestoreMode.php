<?php

namespace App\Http\Middleware;

use App\Services\Restore\RestoreMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quando è in corso un ripristino, blocca l'intera applicazione tranne le
 * rotte del ripristino stesso e l'health check. Serve una pagina 503 statica
 * perché durante l'import il DATABASE (sessioni, cache, impostazioni) è a
 * metà sovrascrittura: il middleware non può dipendere da DB né da sessione,
 * solo dal marker su file (RestoreMode::active() = un file_exists).
 *
 * Modellato su CheckForPendingUpdates, ma SENZA Auth::check()/DB (che qui
 * sarebbero inaffidabili). Vedi docs/ripristino_backup_design.md §4.
 */
class CheckRestoreMode
{
    public function __construct(private readonly RestoreMode $mode) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->mode->active()) {
            return $next($request);
        }

        // Rotte consentite durante il ripristino: gli step/stato autenticati
        // col token, la pagina di esito e l'health check. Tutto il resto è
        // bloccato per non toccare un database a metà import.
        if ($request->is('ripristino/*') || $request->is('up')) {
            return $next($request);
        }

        $info = $this->mode->info() ?? [];

        return response()->view('restore.in-progress', [
            'restoreUuid' => $info['restore_uuid'] ?? null,
        ], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '30');
    }
}

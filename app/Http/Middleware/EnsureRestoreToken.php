<?php

namespace App\Http\Middleware;

use App\Services\Restore\RestoreState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica gli step del ripristino tramite token, SENZA sessione: la
 * sessione dell'admin muore quando l'import sovrascrive la tabella sessions.
 * Il token in chiaro è stato consegnato al browser all'avvio; qui si
 * confronta con l'hash custodito nel file di stato (RestoreState).
 *
 * Il token è cercato nell'header X-Restore-Token o nel campo `token` del
 * body: nessuna dipendenza da cookie o sessione.
 */
class EnsureRestoreToken
{
    public function __construct(private readonly RestoreState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Restore-Token') ?? $request->input('token');

        if (! is_string($token) || ! $this->state->validateToken($token)) {
            return response()->json([
                'message' => 'Token di ripristino mancante, errato o scaduto.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

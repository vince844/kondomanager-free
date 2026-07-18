<?php

namespace App\Http\Middleware;

use App\Enums\RestoreStatus;
use App\Services\Restore\RestoreMode;
use App\Services\Restore\RestoreState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Quando è in corso un ripristino, blocca l'intera applicazione tranne le
 * rotte del ripristino stesso e l'health check. Serve una pagina 503 statica
 * perché durante l'import il DATABASE (sessioni, cache, impostazioni) è a
 * metà sovrascrittura: il middleware non può dipendere da DB né da sessione,
 * solo da file (marker RestoreMode + stato RestoreState, entrambi su disco).
 *
 * La pagina ha due volti, decisi dalla FASE letta dallo stato su file:
 *  - ripristino in corso → spinner + auto-refresh (attende il completamento);
 *  - ripristino FALLITO o in STALLO oltre il timeout → pagina di recupero
 *    (niente refresh) con messaggio, log copiabile e pulsanti riprendi/annulla.
 *
 * Modellato su CheckForPendingUpdates, ma SENZA Auth::check()/DB (che qui
 * sarebbero inaffidabili). Vedi docs/ripristino_backup_design.md §4.
 */
class CheckRestoreMode
{
    private const SUPPORTED_LOCALES = ['it', 'en', 'es', 'pt'];

    public function __construct(
        private readonly RestoreMode $mode,
        private readonly RestoreState $state,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->mode->active()) {
            return $next($request);
        }

        // Rotte consentite durante il ripristino: step/stato/recupero/esito
        // (prefisso "ripristino") e l'health check. Tutto il resto è bloccato
        // per non toccare un database a metà import.
        if ($request->is('ripristino/*') || $request->is('up')) {
            return $next($request);
        }

        $data = $this->buildViewData();

        $response = response()->view('restore.in-progress', $data, Response::HTTP_SERVICE_UNAVAILABLE);

        // Auto-refresh SOLO se il ripristino sta ancora avanzando: sulla pagina
        // di recupero l'admin agisce con i pulsanti, il refresh darebbe fastidio.
        if (! $data['stuck']) {
            $response->header('Retry-After', '30');
        }

        return $response;
    }

    /**
     * Assembla i dati per la vista leggendo SOLO file (marker + stato): niente
     * DB né sessione. Robusto a stato assente o corrotto.
     */
    private function buildViewData(): array
    {
        $marker = $this->mode->info() ?? [];

        try {
            $state = $this->state->get();
        } catch (Throwable) {
            $state = null;
        }

        $phase = $state['phase'] ?? null;
        $status = $phase ? RestoreStatus::tryFrom($phase) : null;
        $failed = $status === RestoreStatus::FAILED;

        $updatedAt = (int) ($state['updated_at'] ?? $state['started_at'] ?? 0);
        $staleSeconds = (int) config('backup.stale_after_hours', 2) * 3600;
        $stale = ($status?->isRunning() ?? false)
            && $updatedAt > 0
            && (time() - $updatedAt) > $staleSeconds;

        $locale = $marker['locale'] ?? $state['locale'] ?? config('app.locale');

        return [
            'stuck' => $failed || $stale,
            'locale' => in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'it',
            'restoreUuid' => $marker['restore_uuid'] ?? ($state['uuid'] ?? null),
            'failedPhase' => $state['failed_phase'] ?? $phase,
            'error' => $state['error'] ?? null,
            'failedAt' => $state['failed_at'] ?? null,
            'appVersion' => config('app.version'),
        ];
    }
}

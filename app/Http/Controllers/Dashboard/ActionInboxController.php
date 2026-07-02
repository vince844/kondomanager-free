<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Anagrafica;
use App\Services\Gestionale\InboxService;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActionInboxController extends Controller
{
    use HasCondomini;

    public function __invoke(Request $request): Response
    {
        $filter       = $request->input('filter', 'all');
        $condominioId = $request->input('condominio_id');

        // Conteggi rispettano il filtro condominio (se attivo)
        $counts = $this->getCounts($condominioId ? (int) $condominioId : null);
        $tasks  = $this->getTasks($filter, $condominioId ? (int) $condominioId : null);

        return Inertia::render('dashboard/ActionInbox', [ 
            'tasks'           => $tasks,
            'counts'          => $counts,
            'activeFilter'    => $filter,
            'condomini'       => $this->getCondomini(),
            'condominioFilter' => $condominioId ? (int) $condominioId : null,
        ]);
    }

    private function getTasks(string $filter, ?int $condominioId = null)
    {
        $query = Evento::query()
            ->whereJsonContains('meta->requires_action', true)
            ->where(fn($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
            ->where('is_completed', false)
            ->with([
                'condomini:id,nome',
                'anagrafiche:id,nome' 
            ]);

        // Filtro per condominio specifico
        if ($condominioId) {
            $query->whereHas('condomini', fn($q) => $q->where('condomini.id', $condominioId));
        }

        match($filter) {
            'urgent'      => $query->where('start_time', '<=', now()->endOfDay()),
            'payments'    => $query->whereJsonContains('meta->type', 'verifica_pagamento'),
            'maintenance' => $query->whereJsonContains('meta->type', 'segnalazione_guasto'),
            default       => null
        };

        return Inertia::scroll(
            $query
            ->orderByRaw("CASE WHEN start_time <= NOW() THEN 0 ELSE 1 END ASC, start_time ASC")
            ->paginate(15)
            ->withQueryString()
            ->through(function ($task) {
                
                $condominio = $task->condomini->first();
                
                // --- LOGICA RECUPERO NOME ---
                // 1. Proviamo dalla relazione standard
                $nomeAnagrafica = $task->anagrafiche->first()?->nome;

                // 2. Se è null, proviamo a pescarlo dal JSON usando l'ID
                if (!$nomeAnagrafica && !empty($task->meta['context']['anagrafica_id'])) {
                    $anagraficaId = $task->meta['context']['anagrafica_id'];
                    // Recupero "al volo" (poco costoso per pochi record)
                    $anagraficaModel = Anagrafica::find($anagraficaId);
                    if ($anagraficaModel) {
                        $nomeAnagrafica = $anagraficaModel->nome;
                    }
                }
                // -----------------------------

                $status = $this->getTaskStatus($task);
                $daysPending = $status === 'expired' ? (int) floor(now()->diffInDays($task->start_time)) : 0;

                return [
                    'id'           => $task->id,
                    'title'        => $task->title,
                    'description'  => $task->description, 
                    'date'         => $task->start_time->toISOString(),
                    'condominio'   => $condominio?->nome ?? 'Generale',
                    'type'         => $task->tipo?->value ?? $task->meta['type'] ?? 'generic',
                    'amount'       => $task->meta['importo_dichiarato'] 
                                   ?? $task->meta['totale_rata'] 
                                   ?? null,
                    'status'       => $status,
                    'days_pending' => $daysPending,
                    'context'      => [
                        // Ora qui avrai il nome corretto
                        'anagrafica_nome' => $nomeAnagrafica, 
                        'action_url'      => $task->meta['action_url'] ?? null,
                        'related_id'      => $task->meta['context']['related_event_id'] ?? null,
                    ],
                ];
            })
        );
    }

    /**
     * Calcola i conteggi per le KPI card, rispettando il filtro condominio.
     * Quando $condominioId è null usa InboxService (globale, cached).
     * Quando è specificato, ricalcola filtrato per quel condominio.
     */
    private function getCounts(?int $condominioId = null): array
    {
        if (!$condominioId) {
            return InboxService::getCounts();
        }

        $deadline = now()->endOfDay()->toDateTimeString();

        $statsQuery = Evento::query()
            ->whereJsonContains('meta->requires_action', true)
            ->where(fn($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
            ->where('is_completed', false)
            ->whereHas('condomini', fn($q) => $q->where('condomini.id', $condominioId));

        // Fix cross-database per SQLite tests vs MySQL
        $isSqlite = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite';
        $typeExtract = $isSqlite 
            ? "JSON_EXTRACT(meta, '$.\"type\"')" 
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"')) USING utf8mb4)";

        $stats = $statsQuery->selectRaw("
            COUNT(*) as all_tasks,
            SUM(CASE WHEN start_time <= ? THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN {$typeExtract} = 'verifica_pagamento' THEN 1 ELSE 0 END) as payments,
            SUM(CASE WHEN {$typeExtract} = 'segnalazione_guasto' THEN 1 ELSE 0 END) as maintenance
        ", [$deadline])
            ->first();

        return [
            'all'         => (int) ($stats->all_tasks ?? 0),
            'urgent'      => (int) ($stats->urgent ?? 0),
            'payments'    => (int) ($stats->payments ?? 0),
            'maintenance' => (int) ($stats->maintenance ?? 0),
        ];
    }

    private function getTaskStatus(Evento $task): string
    {
        if (($task->meta['type'] ?? '') === 'verifica_pagamento') {
            return 'pending_verification';
        }

        if ($task->start_time->isPast()) {
            return 'expired';
        }

        return 'scheduled';
    }

    /**
     * Rifiuta una segnalazione di pagamento.
     */
    public function reject(Request $request, Evento $task)
    {
        $request->validate([
            'reason' => 'required|string|max:255', 
        ]);

        // 1. Recupera l'evento originale del condòmino (collegato via meta)
        $userEventId = $task->meta['context']['related_event_id'] ?? null;
        
        if ($userEventId) {
            /** @var \App\Models\Evento|null $userEvent */
            $userEvent = Evento::find($userEventId);
            if ($userEvent) {
                // Aggiorniamo l'evento utente diventa 'rejected'
                $meta = $userEvent->meta;
                $meta['status'] = 'rejected'; // Stato specifico per dire "ci hai provato ma no"
                $meta['rejection_reason'] = $request->input('reason');
                $meta['rejected_at'] = now()->toIso8601String();
                
                $userEvent->update(['meta' => $meta]);
                
                // QUI: Potresti inviare una notifica email al condòmino ($userEvent->created_by)
            }
        }

        // 2. Chiudi il task Admin
        $task->update([
            'is_completed' => true, // O cancellalo se preferisci
            'completed_at' => now(),
        ]);

        // CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
        InboxService::clearAdminCache();

        return back()->with('success', 'Segnalazione rifiutata e condòmino notificato.');
    }

    /**
     * Segna un Task Admin come completato dalla Inbox.
     */
    public function complete(Request $request, Evento $task)
    {
        // Evita di chiudere task già completati o task privati dei condòmini
        if ($task->is_completed || $task->visibility === 'private') {
            return back()->with('error', 'Impossibile completare questo task.');
        }

        $task->update([
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        InboxService::clearAdminCache();

        return back()->with('success', 'Task completato.');
    }
}
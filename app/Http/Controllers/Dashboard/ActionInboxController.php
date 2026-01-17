<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Anagrafica; // <--- AGGIUNTO QUESTO IMPORT
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActionInboxController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filter = $request->input('filter', 'all');
        $counts = $this->getCounts();
        $tasks = $this->getTasks($filter);

        return Inertia::render('dashboard/ActionInbox', [ // O 'Dashboard/ActionInbox' (case sensitive)
            'tasks'        => $tasks,
            'counts'       => $counts,
            'activeFilter' => $filter,
        ]);
    }

    private function getCounts(): array
    {
        $deadline = now()->endOfDay()->toDateTimeString();
        
        $stats = Evento::query()
            ->whereJsonContains('meta->requires_action', true)
            ->where(fn($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
            ->selectRaw("
                COUNT(*) as all_tasks,
                SUM(CASE WHEN start_time <= ? THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type')) = 'verifica_pagamento' THEN 1 ELSE 0 END) as payments,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type')) = 'ticket_guasto' THEN 1 ELSE 0 END) as maintenance
            ", [$deadline])
            ->first();

        return [
            'all'         => (int) ($stats->all_tasks ?? 0),
            'urgent'      => (int) ($stats->urgent ?? 0),
            'payments'    => (int) ($stats->payments ?? 0),
            'maintenance' => (int) ($stats->maintenance ?? 0),
        ];
    }

    private function getTasks(string $filter)
    {
        $query = Evento::query()
            ->whereJsonContains('meta->requires_action', true)
            ->where(fn($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
            ->with([
                'condomini:id,nome',
                'anagrafiche:id,nome' 
            ]);

        match($filter) {
            'urgent'      => $query->where('start_time', '<=', now()->endOfDay()),
            'payments'    => $query->whereJsonContains('meta->type', 'verifica_pagamento'),
            'maintenance' => $query->whereJsonContains('meta->type', 'ticket_guasto'),
            default       => null
        };

        return $query
            ->orderBy('start_time', 'asc')
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

                return [
                    'id'           => $task->id,
                    'title'        => $task->title,
                    'description'  => $task->description, 
                    'date'         => $task->start_time->toISOString(),
                    'condominio'   => $condominio?->nome ?? 'Generale',
                    
                    'type'         => $task->meta['type'] ?? 'generic',
                    'amount'       => $task->meta['importo_dichiarato'] 
                                   ?? $task->meta['totale_rata'] 
                                   ?? null,
                    
                    'status'       => $this->getTaskStatus($task),
                    
                    'context'      => [
                        // Ora qui avrai il nome corretto
                        'anagrafica_nome' => $nomeAnagrafica, 
                        'action_url'      => $task->meta['action_url'] ?? null,
                        'related_id'      => $task->meta['context']['related_event_id'] ?? null,
                    ],
                ];
            });
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
}
<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Evento;
use Carbon\Carbon;
use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Illuminate\Support\Facades\Auth;

class RecurrenceService
{
    private const MAX_DAYS = 365;

     public function getEventsInNextDays(
        int $days = 7,
        array $filters = [],
        ?int $page = null,
        ?int $perPage = null,
        ?Anagrafica $anagrafica = null,
        ?Collection $condominioIds = null
    ): Collection|LengthAwarePaginator {
        $now = Carbon::now();
        $start = !empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : $now;
        $end = !empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : $now->copy()->addDays(min($days, self::MAX_DAYS));

        if ($this->isAdmin()) {
            $oneTimeEvents = $this->getOneTimeEvents($start, $end, $filters);
            $recurringEvents = $this->getRecurringEvents($start, $end, $filters);
        } else {
            $oneTimeEvents = $this->getUserScopedOneTimeEvents($start, $end, $filters, $anagrafica, $condominioIds);
            $recurringEvents = $this->getUserScopedRecurringEvents($start, $end, $filters, $anagrafica, $condominioIds);
        }

        $combined = $oneTimeEvents->concat($recurringEvents)->sortBy('occurs_at')->values();

        return $page && $perPage
            ? $this->paginateResults($combined, $page, $perPage)
            : $combined;
    }

    private function getOneTimeEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNull('recurrence_id')
            ->with('categoria', 'condomini', 'anagrafiche');

        // LOGICA ADMIN:
        // 1. Calendario: Eventi nel range (Futuri)
        // 2. Inbox: Eventi scaduti SOLO se sono Rate da emettere
        $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_time', [$start, $end])
              ->orWhere(function ($sub) {
                  $sub->where('meta->requires_action', true)
                      ->where('meta->type', 'emissione_rata');
              });
        });

        $this->applyFilters($query, $filters);

        return $query->get()->map(function ($event) {
            $copy = clone $event;
            $copy->occurs_at = $copy->start_time;
            return $copy;
        });
    }
    
    private function getUserScopedRecurringEvents(Carbon $s, Carbon $e, array $f, ?Anagrafica $a, ?Collection $c): Collection {
        $q = Evento::query()->whereNotNull('recurrence_id')->with(['ricorrenza','categoria','condomini','anagrafiche'])->where('is_approved',true)->where('visibility','public');
        $this->applyFilters($q, $f);
        $q->where(function($qq) use ($a,$c){ $qq->whereHas('anagrafiche',fn($z)=>$z->where('anagrafica_id',$a?->id))->orWhere(function($z) use ($c){ $z->whereDoesntHave('anagrafiche')->whereHas('condomini',fn($x)=>$x->whereIn('condominio_id',$c)); }); });
        return $q->get()->flatMap(fn($ev)=>$this->expandRecurringEvent($ev,$s,$e,$f));
    }

    private function getUserScopedOneTimeEvents(
        Carbon $start,
        Carbon $end,
        array $filters,
        ?Anagrafica $anagrafica,
        ?Collection $condominioIds
    ): Collection {
        $query = Evento::query()
            ->whereNull('recurrence_id')
            ->with('categoria', 'condomini', 'anagrafiche')
            ->where('is_approved', true)
            // SICUREZZA GLOBALE: Il condòmino non deve mai vedere roba HIDDEN (dell'admin)
            ->where('visibility', '!=', 'hidden');

        // LOGICA TEMPORALE
        $query->where(function ($q) use ($start, $end) {
            
            // 1. CALENDARIO: Mostra tutto ciò che è nel range (futuro)
            $q->whereBetween('start_time', [$start, $end])
            
            // 2. INBOX DEBITI: Mostra le rate passate SE non sono pagate
            ->orWhere(function ($sub) {
                $sub->where('meta->type', 'scadenza_rata_condomino')
                    ->where('meta->status', '!=', 'paid');
            });
        });

        $this->applyFilters($query, $filters);

        // LOGICA DI APPARTENENZA (Scope)
        $query->where(function ($q) use ($anagrafica, $condominioIds) {
            
            // A. I MIEI EVENTI (PRIVATE o PUBLIC che sia)
            // Se sono nella tabella pivot anagrafica_evento, sono miei.
            $q->whereHas('anagrafiche', fn($k) =>
                $k->where('anagrafica_id', $anagrafica?->id)
            )
            
            // B. EVENTI CONDOMINIALI (Solo PUBLIC)
            // Es. Assemblea, Disinfestazione. Li vedo se sono del mio condominio.
            ->orWhere(function ($sub) use ($condominioIds) {
                $sub->where('visibility', 'public')
                    ->whereHas('condomini', fn($k) =>
                        $k->whereIn('condominio_id', $condominioIds)
                    )
                    // Opzionale: escludo quelli assegnati specificamente ad altri
                    ->whereDoesntHave('anagrafiche'); 
            });
        });

        return $query->get()->map(function ($event) {
            $copy = clone $event;
            $copy->occurs_at = $copy->start_time;
            return $copy;
        });
    }

    private function getRecurringEvents(Carbon $start, Carbon $end, array $filters): Collection {
        $query = Evento::query()->whereNotNull('recurrence_id')->with(['ricorrenza', 'categoria', 'condomini', 'anagrafiche']);
        $this->applyFilters($query, $filters);
        return $query->get()->flatMap(fn($event) => $this->expandRecurringEvent($event, $start, $end, $filters));
    }

    // --- PUNTO CRITICO: CREAZIONE CLONE RICORRENZA ---
    private function buildOccurrenceClone(Evento $original, Carbon $occursAt): Evento
    {
        $clone = $original->replicate();
        $clone->id = $original->id; 
        
        // 1. Impostiamo la data tecnica per l'ordinamento
        $clone->occurs_at = $occursAt;
        
        // 2. FIX FONDAMENTALE: Aggiorniamo la data VISIBILE!
        // Se non lo facciamo, il frontend vede la data del 2025 e dice "SCADUTO"
        $clone->start_time = $occursAt; 

        // Se c'è una data fine, trasliamo anche quella per mantenere la durata
        if ($original->start_time && $original->end_time) {
             $duration = $original->start_time->diff($original->end_time);
             $clone->end_time = $occursAt->copy()->add($duration);
        }

        return $clone;
    }

    private function expandRecurringEvent(Evento $event, Carbon $start, Carbon $end, array $filters): Collection
    {
        $rec = $event->ricorrenza;
        if (!$rec?->rrule) return collect();

        $timezone = $rec->timezone ?? config('app.timezone');
        $exceptions = $event->eccezioni()->where('is_deleted', true)->whereBetween('exception_date', [$start, $end])->get()->pluck('exception_date')->map(fn($date) => Carbon::parse($date)->format('Y-m-d H:i:s'))->toArray();

        try {
            $rule = new Rule($rec->rrule, new \DateTime($event->start_time, new \DateTimeZone($timezone)));
            $transformer = new ArrayTransformer();

            if (strtolower($rec->frequency) === 'monthly') {
                $config = new ArrayTransformerConfig();
                $config->enableLastDayOfMonthFix();
                $transformer->setConfig($config);
            }

            $constraint = new BetweenConstraint(
                new \DateTime($start, new \DateTimeZone($timezone)),
                new \DateTime($end, new \DateTimeZone($timezone)),
                true
            );

            $occurrences = $transformer->transform($rule, $constraint);

            return collect($occurrences)
                ->map(fn($occurrence) => $this->buildOccurrenceClone($event, Carbon::instance($occurrence->getStart())))
                ->filter(fn($occurrence) =>
                    $this->isNotException($occurrence, $exceptions) &&
                    $this->passesSearchFilter($occurrence, $filters['search'] ?? null)
                );

        } catch (\Exception $e) {
            Log::warning("Invalid RRULE for event ID {$event->id}: {$e->getMessage()}");
            return collect();
        }
    }

    private function isNotException(Evento $event, array $exceptions): bool
    {
        return !in_array($event->occurs_at->format('Y-m-d H:i:s'), $exceptions);
    }

    // --- FILTRI CORRETTI (FIX SQL ERROR) ---
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['title'])) {
            $query->where('title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['category_id']) && is_array($filters['category_id'])) {
            $query->whereIn('category_id', $filters['category_id']);
        }

        if (!empty($filters['exclude_type'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('meta->type', '!=', $filters['exclude_type'])
                  ->orWhereNull('meta')
                  // Uso 'orWhereNull' sulla chiave JSON che è standard e sicuro
                  ->orWhereNull('meta->type'); 
            });
        }
    }

    private function passesSearchFilter(Evento $event, ?string $search): bool {
        if (empty($search)) return true;
        $search = strtolower($search);
        return str_contains(strtolower($event->title), $search) || str_contains(strtolower($event->description ?? ''), $search);
    }

    private function paginateResults(Collection $items, int $page, int $perPage): LengthAwarePaginator {
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
        );
    }

    // ... metodi stats ...
    public function getUpcomingStats(): array {
        return ['next_seven_days' => $this->countEventsInNextDays(7), 'next_fourteen_days' => $this->countEventsInNextDays(14), 'next_twentyeight_days' => $this->countEventsInNextDays(28), 'expired_last_seven_days' => $this->countExpiredEventsLast7Days()];
    }
    private function countEventsInNextDays(int $days): int { return $this->getEventsInNextDays($days)->count(); }
    public function countExpiredEventsLast7Days(): int {
        $now = Carbon::now(); $start = $now->copy()->subDays(7); $end = $now->copy();
        $one = Evento::query()->whereNull('recurrence_id')->whereBetween('start_time', [$start, $end])->where('start_time', '<', $now)->count();
        return $one; 
    }
    
    private function isAdmin(): bool {
        $user = Auth::user();
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]) || $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
    }
}
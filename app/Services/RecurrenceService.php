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
use Illuminate\Database\Eloquent\Builder;

/**
 * Service per la gestione e l'espansione degli eventi e delle loro ricorrenze.
 *
 * Questa classe si occupa di unire eventi singoli (one-time) ed eventi ricorrenti 
 * (basati su RRULE) per un dato intervallo temporale. Gestisce inoltre lo scoping
 * dei permessi e della visibilità: discrimina tra la visualizzazione completa
 * per gli Amministratori (con task operativi come l'emissione delle rate) e la 
 * visualizzazione limitata per i Condòmini (filtrata per anagrafica e condominio).
 */
class RecurrenceService
{
    private const MAX_DAYS = 365;

    /**
     * Recupera gli eventi (singoli e ricorrenti) per i prossimi giorni, unificati.
     * Applica controlli di visibilità in base al ruolo (Admin vs Condòmino).
     *
     * @param int $days Giorni di ricerca (default 7)
     * @param array $filters Filtri (date_from, date_to, search, category_id, ecc.)
     * @param int|null $page Pagina per impaginazione
     * @param int|null $perPage Elementi per pagina
     * @param Anagrafica|null $anagrafica Anagrafica dell'utente loggato
     * @param Collection|null $condominioIds ID dei condomini associati
     * @return Collection|LengthAwarePaginator
     */
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

    /**
     * Helper cross-database per l'estrazione JSON sicura (evita errori su SQLite nei test).
     */
    private function getMetaExtractSql(string $key): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "JSON_EXTRACT(`meta`, '$.\"{$key}\"')"
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.\"{$key}\"')) USING utf8mb4)";
    }

    /**
     * Recupera eventi singoli (non ricorrenti) per l'Admin, con visibilità globale.
     */
    private function getOneTimeEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNull('recurrence_id')
            ->where('visibility', '!=', 'hidden')
            ->with('categoria', 'condomini', 'anagrafiche');

        $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_time', [$start, $end])
              ->orWhere(function ($sub) {
                  // whereJsonContains è universale: gestisce MySQL e MariaDB senza whereRaw fragili
                  $sub->whereJsonContains('meta->requires_action', true)
                      ->whereRaw(
                          $this->getMetaExtractSql('type') . " = ?",
                          ['emissione_rata']
                      );
              });
        });

        $this->applyFilters($query, $filters);

        return $query->get()->map(function ($event) {
            $copy = clone $event;
            $copy->occurs_at = $copy->start_time;
            return $copy;
        });
    }

    /**
     * Recupera eventi ricorrenti con filtri di visibilità per l'utente (condòmino/fornitore).
     */
    private function getUserScopedRecurringEvents(Carbon $s, Carbon $e, array $f, ?Anagrafica $a, ?Collection $c): Collection
    {
        $q = Evento::query()
            ->whereNotNull('recurrence_id')
            ->with(['ricorrenza', 'categoria', 'condomini', 'anagrafiche'])
            ->where('is_approved', true)
            ->where('visibility', 'public');

        $this->applyFilters($q, $f);

        $q->where(function ($qq) use ($a, $c) {
            $qq->whereHas('anagrafiche', fn($z) => $z->where('anagrafica_id', $a?->id))
               ->orWhere(function ($z) use ($c) {
                   $z->whereDoesntHave('anagrafiche')
                     ->whereHas('condomini', fn($x) => $x->whereIn('condominio_id', $c));
               });
        });

        return $q->get()->flatMap(fn($ev) => $this->expandRecurringEvent($ev, $s, $e, $f));
    }

    /**
     * Recupera eventi singoli con filtri di visibilità per l'utente (condòmino/fornitore).
     * Esclude le scadenze rate già saldate da oltre 30 giorni.
     */
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
            ->where('visibility', '!=', 'hidden');

        $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_time', [$start, $end])
              ->orWhere(function ($sub) {
                  $sub->whereRaw(
                          $this->getMetaExtractSql('type') . " = ?",
                          ['scadenza_rata_condomino']
                      )
                      ->where(function ($statusQuery) {
                          $statusQuery
                              ->whereRaw(
                                  $this->getMetaExtractSql('status') . " != ?",
                                  ['paid']
                              )
                              ->orWhere(function ($paidQuery) {
                                  $paidQuery
                                      ->whereRaw(
                                          $this->getMetaExtractSql('status') . " = ?",
                                          ['paid']
                                      )
                                      ->where('updated_at', '>=', now()->subDays(30));
                              });
                      });
              });
        });

        $this->applyFilters($query, $filters);

        $query->where(function ($q) use ($anagrafica, $condominioIds) {
            $q->whereHas('anagrafiche', fn($k) => $k->where('anagrafica_id', $anagrafica?->id))
              ->orWhere(function ($sub) use ($condominioIds) {
                  $sub->where('visibility', 'public')
                      ->whereHas('condomini', fn($k) => $k->whereIn('condominio_id', $condominioIds))
                      ->whereDoesntHave('anagrafiche');
              });
        });

        return $query->get()->map(function ($event) {
            $copy = clone $event;
            $copy->occurs_at = $copy->start_time;
            return $copy;
        });
    }

    /**
     * Recupera ed espande gli eventi ricorrenti per l'Admin (visibilità globale).
     */
    private function getRecurringEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNotNull('recurrence_id')
            ->where('visibility', '!=', 'hidden')
            ->with(['ricorrenza', 'categoria', 'condomini', 'anagrafiche']);

        $this->applyFilters($query, $filters);

        return $query->get()->flatMap(fn($event) => $this->expandRecurringEvent($event, $start, $end, $filters));
    }

    /**
     * Crea un clone "virtuale" dell'evento originale posizionato nella data della ricorrenza.
     */
    private function buildOccurrenceClone(Evento $original, Carbon $occursAt): Evento
    {
        $clone = $original->replicate();
        $clone->id = $original->id;
        $clone->occurs_at = $occursAt;
        $clone->start_time = $occursAt;

        if ($original->start_time && $original->end_time) {
            $duration = $original->start_time->diff($original->end_time);
            $clone->end_time = $occursAt->copy()->add($duration);
        }

        return $clone;
    }

    /**
     * Espande una singola RRULE in una Collection di eventi clone (occorrenze reali).
     */
    private function expandRecurringEvent(Evento $event, Carbon $start, Carbon $end, array $filters): Collection
    {
        $rec = $event->ricorrenza;
        if (!$rec?->rrule) return collect();

        $timezone = $rec->timezone ?? config('app.timezone');
        $exceptions = $event->eccezioni()
            ->where('is_deleted', true)
            ->whereBetween('exception_date', [$start, $end])
            ->get()
            ->pluck('exception_date')
            ->map(fn($date) => Carbon::parse($date)->format('Y-m-d H:i:s'))
            ->toArray();

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

    /**
     * Verifica se un'occorrenza è valida, cioè se non ricade in un'eccezione (es. cancellata).
     */
    private function isNotException(Evento $event, array $exceptions): bool
    {
        return !in_array($event->occurs_at->format('Y-m-d H:i:s'), $exceptions);
    }

    /**
     * Applica filtri testuali o per categoria alla query Builder degli Eventi.
     */
    private function applyFilters(Builder $query, array $filters): void
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
                $q->whereRaw(
                        $this->getMetaExtractSql('type') . " != ?",
                        [$filters['exclude_type']]
                    )
                  ->orWhereNull('meta')
                  ->orWhereRaw("JSON_EXTRACT(`meta`, '$.\"type\"') IS NULL");
            });
        }
    }

    /**
     * Filtra manualmente a valle (in memory) se le ricorrenze espansive devono essere filtrate per testo.
     */
    private function passesSearchFilter(Evento $event, ?string $search): bool
    {
        if (empty($search)) return true;
        $search = strtolower($search);
        return str_contains(strtolower($event->title), $search)
            || str_contains(strtolower($event->description ?? ''), $search);
    }

    /**
     * Trasforma una Collection unificata in un Paginator per il frontend.
     */
    private function paginateResults(Collection $items, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]
        );
    }

    /**
     * Recupera le statistiche in tempo reale (badge numerici) per la dashboard dell'Admin.
     */
    public function getUpcomingStats(): array
    {
        return [
            'next_seven_days'          => $this->countEventsInNextDays(7),
            'next_fourteen_days'       => $this->countEventsInNextDays(14),
            'next_twentyeight_days'    => $this->countEventsInNextDays(28),
            'expired_last_seven_days'  => $this->countExpiredEventsLast7Days(),
        ];
    }

    /**
     * Helper per il conteggio veloce degli eventi in scadenza nei prossimi X giorni.
     */
    private function countEventsInNextDays(int $days): int
    {
        return $this->getEventsInNextDays($days)->count();
    }

    /**
     * Conta gli eventi operativi (es. pagamenti o commenti) scaduti e non processati negli ultimi 7 giorni.
     */
    public function countExpiredEventsLast7Days(): int
    {
        $now   = Carbon::now();
        $start = $now->copy()->subDays(7);

        return Evento::query()
            ->whereNull('recurrence_id')
            ->whereBetween('start_time', [$start, $now])
            ->where('start_time', '<', $now)
            ->count();
    }

    /**
     * Verifica se l'utente attualmente autenticato ha i privilegi di Amministratore o Collaboratore.
     */
    private function isAdmin(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])
            || $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
    }
}
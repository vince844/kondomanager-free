<?php

namespace App\Services;

use App\Models\Evento;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;

class RecurrenceService
{
    private const MAX_DAYS = 365; // Prevent excessive date ranges

    public function getEventsInNextDays(
        int $days = 7, 
        array $filters = [], 
        ?int $page = null, 
        ?int $perPage = null
    ): Collection|LengthAwarePaginator {
        $now = Carbon::now();

        // Override default start and end with filters if present
        $start = !empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : $now;
        $end = !empty($filters['date_to']) 
            ? Carbon::parse($filters['date_to']) 
            : $now->copy()->addDays(min($days, self::MAX_DAYS));

        // Get base query with eager loading
        $oneTimeEvents = $this->getOneTimeEvents($start, $end, $filters);
        $recurringEvents = $this->getRecurringEvents($start, $end, $filters);

        $combined = $oneTimeEvents->concat($recurringEvents)
            ->sortBy('occurs_at')
            ->values();

        // Return paginated or full collection based on parameters
        return $page && $perPage 
            ? $this->paginateResults($combined, $page, $perPage)
            : $combined;
    }

    private function getOneTimeEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNull('recurrence_id')
            ->with('categoria')
            ->whereBetween('start_time', [$start, $end]);

        $this->applyFilters($query, $filters);

        return $query->get()->map(function ($event) {
            $event = $event->replicate();
            $event->occurs_at = $event->start_time;
            return $event;
        });
    }

    private function getRecurringEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNotNull('recurrence_id')
            ->with(['ricorrenza', 'categoria']);

        $this->applyFilters($query, $filters);

        return $query->get()->flatMap(function ($event) use ($start, $end, $filters) {
            return $this->expandRecurringEvent($event, $start, $end, $filters);
        });
    }

    private function expandRecurringEvent(Evento $event, Carbon $start, Carbon $end, array $filters): Collection
    {
        $rec = $event->ricorrenza;
        if (!$rec || !$rec->rrule) {
            return collect();
        }

        try {
            $rule = new Rule(
                $rec->rrule, 
                new \DateTime($event->start_time), 
                null, 
                $rec->timezone ?? config('app.timezone')
            );

            $transformer = new ArrayTransformer();
            if (strtolower($rec->frequency) === 'monthly') {
                $config = new ArrayTransformerConfig();
                $config->enableLastDayOfMonthFix();
                $transformer->setConfig($config);
            }

            $constraint = new BetweenConstraint(new \DateTime($start), new \DateTime($end), true);
            $occurrences = $transformer->transform($rule, $constraint);

            return collect($occurrences)->map(function ($occurrence) use ($event) {
                $newEvent = $event->replicate();
                $newEvent->occurs_at = Carbon::instance($occurrence->getStart());
                return $newEvent;
            })->filter(function ($event) use ($filters) {
                return $this->passesSearchFilter($event, $filters['search'] ?? null);
            });

        } catch (\Exception $e) {
            Log::warning("Invalid RRULE for event ID {$event->id}: {$e->getMessage()}");
            return collect();
        }
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['title'])) {
            $query->where('title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['category_id']) && is_array($filters['category_id'])) {
            $query->whereIn('category_id', $filters['category_id']);
        }

    }

    private function passesSearchFilter($event, ?string $search): bool
    {
        if (empty($search)) {
            return true;
        }

        $search = strtolower($search);
        return str_contains(strtolower($event->title), $search) || 
               str_contains(strtolower($event->description ?? ''), $search);
    }

    private function paginateResults(Collection $items, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
    }
}

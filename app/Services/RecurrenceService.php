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
    private const MAX_DAYS = 365;

    public function getEventsInNextDays(
        int $days = 7,
        array $filters = [],
        ?int $page = null,
        ?int $perPage = null
    ): Collection|LengthAwarePaginator {
        $now = Carbon::now();

        $start = !empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : $now;
        $end = !empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : $now->copy()->addDays(min($days, self::MAX_DAYS));

        $oneTimeEvents = $this->getOneTimeEvents($start, $end, $filters);
        $recurringEvents = $this->getRecurringEvents($start, $end, $filters);

        $combined = $oneTimeEvents->concat($recurringEvents)->sortBy('occurs_at')->values();

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
            $copy = clone $event;
            $copy->occurs_at = $copy->start_time;
            return $copy;
        });
    }

    private function getRecurringEvents(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Evento::query()
            ->whereNotNull('recurrence_id')
            ->with(['ricorrenza', 'categoria']);

        $this->applyFilters($query, $filters);

        return $query->get()->flatMap(fn($event) => $this->expandRecurringEvent($event, $start, $end, $filters));
    }

    private function expandRecurringEvent(Evento $event, Carbon $start, Carbon $end, array $filters): Collection
    {
        $rec = $event->ricorrenza;
        if (!$rec?->rrule) {
            return collect();
        }

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

    private function buildOccurrenceClone(Evento $original, Carbon $occursAt): Evento
    {
        $clone = $original->replicate();
        $clone->id = $original->id; // preserve ID
        $clone->occurs_at = $occursAt;
        return $clone;
    }

    private function isNotException(Evento $event, array $exceptions): bool
    {
        return !in_array($event->occurs_at->format('Y-m-d H:i:s'), $exceptions);
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

    private function passesSearchFilter(Evento $event, ?string $search): bool
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

    public function getUpcomingStats(): array
    {
        return [
            'next_seven_days' => $this->countEventsInNextDays(7),
            'next_fourteen_days' => $this->countEventsInNextDays(14),
            'next_twentyeight_days' => $this->countEventsInNextDays(28),
            'expired_last_seven_days' => $this->countExpiredEventsLast7Days(),
        ];
    }

    private function countEventsInNextDays(int $days): int
    {
        $events = $this->getEventsInNextDays($days);
        return $events->count();
    }

    public function countExpiredEventsLast7Days(): int
    {
        $now = Carbon::now();
        $start = $now->copy()->subDays(7);
        $end = $now->copy();

        $oneTimeEvents = Evento::query()
            ->whereNull('recurrence_id')
            ->whereBetween('start_time', [$start, $end])
            ->where('start_time', '<', $now)
            ->count();

        $recurringEvents = Evento::query()
            ->whereNotNull('recurrence_id')
            ->with('ricorrenza')
            ->get()
            ->flatMap(function ($event) use ($start, $end, $now) {
                $rec = $event->ricorrenza;
                if (!$rec?->rrule) return collect();

                $timezone = $rec->timezone ?? config('app.timezone');

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

                    return collect($transformer->transform($rule, $constraint))
                        ->map(fn($occurrence) => Carbon::instance($occurrence->getStart()))
                        ->filter(fn($occursAt) => $occursAt->lt($now));

                } catch (\Exception $e) {
                    Log::warning("Invalid RRULE for event ID {$event->id}: {$e->getMessage()}");
                    return collect();
                }
            });

        return $oneTimeEvents + $recurringEvents->count();
    }

}

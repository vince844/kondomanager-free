<?php

namespace App\Services;

use App\Models\Evento;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Illuminate\Support\Facades\Log;

class RecurrenceService
{
    public function getEventsInNextDays(int $days = 7): Collection
    {
        $now = Carbon::now();
        $end = $now->copy()->addDays($days);
        $results = collect();

        // One-time events
        Evento::whereNull('recurrence_id')
            ->with('categoria')
            ->whereBetween('start_time', [$now, $end])
            ->each(function ($event) use ($results) {
                $event = $event->replicate();
                $event->occurs_at = $event->start_time;
                $event->is_recurring = false;
                $results->push($event);
            });

        // Recurring events
        Evento::whereNotNull('recurrence_id')
            ->with(['ricorrenza', 'categoria'])
            ->get()
            ->each(function ($event) use ($now, $end, $results) {
                $rec = $event->ricorrenza;
                if (!$rec || !$rec->rrule) {
                    return;
                }

                try {
                    $rule = new Rule($rec->rrule, new \DateTime($event->start_time), null, $rec->timezone ?? config('app.timezone'));

                    $transformer = new ArrayTransformer();

                    if (strtolower($rec->frequency) === 'monthly') {
                        $config = new ArrayTransformerConfig();
                        $config->enableLastDayOfMonthFix();
                        $transformer->setConfig($config);
                    }

                    $constraint = new BetweenConstraint(new \DateTime($now), new \DateTime($end), true);
                    $transformerCollection = $transformer->transform($rule, $constraint);

                    foreach ($transformerCollection as $occurrence) {
                        $newEvent = $event->replicate();
                        $newEvent->occurs_at = Carbon::instance($occurrence->getStart());
                        $newEvent->is_recurring = true;
                        $results->push($newEvent);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid RRULE for event ID {$event->id}: {$e->getMessage()}");
                }
            });

        return $results->sortBy('occurs_at')->values();
    }
}

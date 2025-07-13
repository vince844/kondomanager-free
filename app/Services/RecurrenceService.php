<?php

namespace App\Services;

use App\Models\Evento;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RRule\RRule;
use Illuminate\Support\Facades\Log;

class RecurrenceService
{
    public function getEventsInNextDays(int $days = 7): Collection
    {
        $now = Carbon::now();
        $end = $now->copy()->addDays($days);
        $results = collect();

        // One-time events - now returns consistent model format
        Evento::whereNull('recurrence_id')
            ->with('categoria')
            ->whereBetween('start_time', [$now, $end])
            ->each(function ($event) use ($results) {
                $event = $event->replicate();
                $event->occurs_at = $event->start_time;
                $results->push($event);
            });

        // Recurrent events - maintains same model format
        Evento::whereNotNull('recurrence_id')
            ->with(['ricorrenza', 'categoria'])
            ->get()
            ->each(function ($event) use ($now, $end, $results) {
                $rec = $event->ricorrenza;
                if (!$rec || !$rec->rrule) return;

                try {
                    $rrule = new RRule($rec->rrule);
                    foreach ($rrule->getOccurrencesBetween($now, $end) as $occurrence) {
                        $newEvent = $event->replicate();
                        $newEvent->occurs_at = Carbon::instance($occurrence);
                        $results->push($newEvent);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid RRULE for event ID {$event->id}: {$e->getMessage()}");
                }
            });

        return $results->sortBy('occurs_at')->values();
    }

    public function getExpiredEvents(): Collection
    {
        $now = Carbon::now();
        $results = collect();

        // One-time expired events
        Evento::whereNull('recurrence_id')
            ->where('start_time', '<', $now)
            ->each(fn ($event) => $results->push([
                'event' => $event,
                'occurrence' => Carbon::parse($event->start_time),
            ]));

        // Recurrent expired occurrences
        Evento::whereNotNull('recurrence_id')
            ->with('ricorrenza')
            ->get()
            ->each(function ($event) use ($now, $results) {
                $rec = $event->ricorrenza;
                if (!$rec || !$rec->rrule) return;

                try {
                    $rrule = new RRule($rec->rrule);
                    foreach ($rrule->getOccurrencesBefore($now) as $occurrence) {
                        $flattened = $event->toArray();
                        $flattened['occurs_at'] = Carbon::instance($occurrence)->toDateTimeString();
                        $results->push($flattened);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid RRULE for expired event ID {$event->id}: {$e->getMessage()}");
                }
            });

        return $results->sortByDesc('occurrence')->values();
    }
}

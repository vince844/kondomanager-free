<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\EccezioneEvento;
use App\Models\RicorrenzaEvento;
use Illuminate\Support\Arr;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use DateTime;
use DateTimeZone;

/**
 * Service class for handling event operations including recurring event management
 */
class EventoService
{
    /**
     * Handle update of a single occurrence in a recurring event series
     * 
     * @param Evento $originalEvent The original recurring event
     * @param array $validated Validated request data
     * @return Evento The newly created standalone event
     * @throws \InvalidArgumentException If occurrence date is missing
     */
    public function handleSingleOccurrenceUpdate(Evento $originalEvent, array $validated): Evento
    {
        $occurrenceDate = $validated['occurrence_date'];
        
        // Create deletion exception for original occurrence
        EccezioneEvento::updateOrCreate(
            [
                'recurrence_id'  => $originalEvent->recurrence_id,
                'exception_date' => $occurrenceDate,
                'evento_id'      => $originalEvent->id,
            ],
            [
                'is_deleted' => true,
            ]
        );

        // Create new standalone event with updated data
        $newEventData = $this->prepareEventData($validated, [
            'recurrence_id' => null, // Ensure it's a single event
            'created_by'    => $validated['created_by'] // Include created_by
        ]);

        $newEvent = Evento::create($newEventData);

        // Sync relationships
        $this->syncRelationships($newEvent, $validated);

        return $newEvent;
    }

    /**
     * Update a single non-recurring event
     * 
     * @param Evento $evento The event to update
     * @param array $validated Validated request data
     * @return Evento The updated event
     */
    public function updateSingleEvent(Evento $evento, array $validated): Evento
    {
        $eventData = $this->prepareEventData($validated);
        $evento->update($eventData);
        $this->syncRelationships($evento, $validated);
        return $evento;
    }

    /**
     * Convert a recurring event to a single event
     * 
     * @param Evento $evento The event to convert
     * @param array $validated Validated request data
     * @return Evento The converted single event
     */
    public function convertToSingleEvent(Evento $evento, array $validated): Evento
    {
        // Delete recurrence if exists
        if ($evento->recurrence_id) {
            $evento->ricorrenza()->delete();
        }

        // Update as single event
        $eventData = $this->prepareEventData($validated, [
            'recurrence_id' => null,
        ]);
        
        $evento->update($eventData);
        $this->syncRelationships($evento, $validated);
        
        return $evento;
    }

    /**
     * Convert a single event to a recurring event
     * 
     * @param Evento $evento The event to convert
     * @param array $validated Validated request data
     * @return Evento The converted recurring event
     * @throws \InvalidArgumentException If recurrence frequency is missing
     */
    public function convertToRecurringEvent(Evento $evento, array $validated): Evento
    {
        // First validate we have required recurrence fields
        if (empty($validated['recurrence_frequency'])) {
            throw new \InvalidArgumentException("Recurrence frequency is required to convert to recurring event");
        }

        // First update base event data (without recurrence_id yet)
        $eventData = $this->prepareEventData($validated);
        $evento->update($eventData);
        $this->syncRelationships($evento, $validated);

        // Then create recurrence rule - this will update the event's recurrence_id
        $this->createOrUpdateRecurrenceRule($evento, $validated);
        
        return $evento->fresh(); // Return fresh instance with updated relations
    }

    /**
     * Update an entire recurring event series
     * 
     * @param Evento $evento The base event of the series
     * @param array $validated Validated request data
     * @return Evento The updated base event
     * @throws \InvalidArgumentException If recurrence frequency is missing
     */
    public function updateRecurringSeries(Evento $evento, array $validated): Evento
    {
        // Update base event
        $eventData = $this->prepareEventData($validated);
        $evento->update($eventData);
        $this->syncRelationships($evento, $validated);

        // Update recurrence rule
        $this->createOrUpdateRecurrenceRule($evento, $validated);
        
        return $evento;
    }

    /**
     * Prepare event data from validated input
     * 
     * @param array $validated Validated request data
     * @param array $additionalData Additional data to merge
     * @return array Prepared event data
     */
    protected function prepareEventData(array $validated, array $additionalData = []): array
    {
        $baseData = Arr::only($validated, [
            'title', 'description', 'start_time', 'end_time', 
            'note', 'category_id', 'visibility', 'created_by'
        ]);

        return array_merge($baseData, $additionalData);
    }

    /**
     * Sync event relationships
     * 
     * @param Evento $evento The event to sync relationships for
     * @param array $validated Validated request data
     */
    protected function syncRelationships(Evento $evento, array $validated): void
    {
        if (array_key_exists('condomini_ids', $validated)) {
            $evento->condomini()->sync($validated['condomini_ids'] ?? []);
        }

        if (array_key_exists('anagrafiche', $validated)) {
            $evento->anagrafiche()->sync($validated['anagrafiche'] ?? []);
        }
    }

    /**
     * Create or update a recurrence rule for an event
     * 
     * @param Evento $evento The event to create/update recurrence for
     * @param array $validated Validated request data
     * @throws \InvalidArgumentException If recurrence frequency is missing
     */
    protected function createOrUpdateRecurrenceRule(Evento $evento, array $validated): void
    {
        // Ensure we have required fields
        if (empty($validated['recurrence_frequency'])) {
            throw new \InvalidArgumentException("Recurrence frequency is required");
        }

        $timezone = new DateTimeZone(config('app.timezone'));
        $startDate = new DateTime($validated['start_time'], $timezone);

        $rule = (new Rule())
            ->setStartDate($startDate)
            ->setTimezone(config('app.timezone'))
            ->setFreq(strtoupper($validated['recurrence_frequency']))
            ->setInterval((int) ($validated['recurrence_interval'] ?? 1));

        if (!empty($validated['recurrence_by_day'])) {
            $byDay = is_array($validated['recurrence_by_day'])
                ? $validated['recurrence_by_day']
                : explode(',', $validated['recurrence_by_day']);
            $rule->setByDay($byDay);
        }

        if (!empty($validated['recurrence_by_month_day'])) {
            $rule->setByMonthDay([(int) $validated['recurrence_by_month_day']]);
        }

        if (!empty($validated['recurrence_until'])) {
            $rule->setUntil(new DateTime($validated['recurrence_until'], $timezone));
        }

        $transformer = new ArrayTransformer();
        if ($validated['recurrence_frequency'] === 'monthly') {
            $transformerConfig = new ArrayTransformerConfig();
            $transformerConfig->enableLastDayOfMonthFix();
            $transformer->setConfig($transformerConfig);
        }

        $transformer->transform($rule);

        // Create new recurrence rule
        $ricorrenza = RicorrenzaEvento::create([
            'frequency'     => $validated['recurrence_frequency'],
            'interval'      => $validated['recurrence_interval'] ?? 1,
            'by_day'        => !empty($byDay) ? json_encode($byDay) : null,
            'by_month_day'  => $validated['recurrence_by_month_day'] ?? null,
            'until'         => $validated['recurrence_until'] ?? null,
            'rrule'         => $rule->getString(),
            'timezone'      => config('app.timezone'),
            'type'          => 'rrule',
        ]);

        // Update the event with the new recurrence_id
        $evento->update(['recurrence_id' => $ricorrenza->id]);
    }
}
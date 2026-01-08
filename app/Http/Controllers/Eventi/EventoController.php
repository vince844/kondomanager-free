<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\CreateEventoRequest;
use App\Http\Requests\Evento\EditEventoRequest;
use App\Http\Requests\Evento\EventoIndexRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\Evento\EditEventoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\EccezioneEvento;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Services\EventoService;
use App\Services\RecurrenceService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Recurr\Rule;
use Inertia\Response;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

class EventoController extends Controller
{
    use HandleFlashMessages;

    public function __construct(
        private RecurrenceService $recurrenceService,
        private EventoService $eventoService
    ) {}

    /**
     * Display a paginated list of upcoming eventi with optional filters.
     *
     * @param EventoIndexRequest $request The validated index request.
     * @return Response The rendered event list.
     */
    public function index(EventoIndexRequest $request, Evento $evento): Response
    {
        Gate::authorize('viewAny', $evento);

        $validated = $request->validated();
        
        $perPage = min((int) ($validated['per_page'] ?? config('pagination.default_per_page')), 100);
        $page = (int) ($validated['page'] ?? 1);

        $events = $this->recurrenceService->getEventsInNextDays(
            days: 60,
            filters: Arr::only($validated, ['title', 'category_id', 'search', 'date_from', 'date_to']),
            page: $page,
            perPage: $perPage
        );

        // Get stats using the same service
        $stats = $this->recurrenceService->getUpcomingStats();

        return Inertia::render('eventi/EventiList', [
            'eventi' => EventoResource::collection($events->items()),
            'stats' => $stats,
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'filters' => $validated,
        ]);
    }

    /**
     * Show the form for creating a new Evento.
     *
     * @return Response
     */
    public function create(Evento $evento): Response
    {
        Gate::authorize('create', $evento);

        return Inertia::render('eventi/EventiNew', [
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'categorie'   => CategoriaEventoResource::collection(CategoriaEvento::all()),
            'anagrafiche' => [],
        ]);
    }

    /**
     * Store a newly created Evento in the database.
     *
     * @param CreateEventoRequest $request The validated request containing event and recurrence data.
     * @return RedirectResponse A redirect response back to the eventi index route.
     */
    public function store(CreateEventoRequest $request, Evento $evento): RedirectResponse
    {
        Gate::authorize('create', $evento);

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $evento = Evento::create([
                'title'        => $validated['title'],
                'description'  => $validated['description'] ?? null,
                'start_time'   => $validated['start_time'],
                'end_time'     => $validated['end_time'],
                'note'         => $validated['note'] ?? null,
                'created_by'   => $validated['created_by'],
                'category_id'  => $validated['category_id'] ?? null,
                'timezone'     => config('app.timezone'),
                'visibility'   => $validated['visibility'] ?? 'public',
            ]);

            if (!empty($validated['recurrence_frequency'])) {
                $rule = (new Rule())
                    ->setStartDate(new \DateTime($validated['start_time'], new \DateTimeZone(config('app.timezone'))))
                    ->setTimezone(config('app.timezone'))
                    ->setFreq(strtoupper($validated['recurrence_frequency']))
                    ->setInterval((int) ($validated['recurrence_interval'] ?? 1));

                $byDay = null;
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
                    $rule->setUntil(new \DateTime($validated['recurrence_until'], new \DateTimeZone(config('app.timezone'))));
                }

                $transformer = new ArrayTransformer();
                if ($validated['recurrence_frequency'] === 'monthly') {
                    $transformerConfig = new ArrayTransformerConfig();
                    $transformerConfig->enableLastDayOfMonthFix();
                    $transformer->setConfig($transformerConfig);
                }

                $transformer->transform($rule);

                $ricorrenza = RicorrenzaEvento::create([
                    'frequency'      => $validated['recurrence_frequency'],
                    'interval'       => $validated['recurrence_interval'] ?? 1,
                    'by_day'         => $byDay ? json_encode($byDay) : null,
                    'by_month_day'   => $validated['recurrence_by_month_day'] ?? null,
                    'until'          => $validated['recurrence_until'] ?? null,
                    'type'           => 'rrule',
                    'rrule'          => $rule->getString(),
                    'timezone'       => config('app.timezone'),
                ]);

                $evento->update(['recurrence_id' => $ricorrenza->id]);
            }

            $evento->condomini()->sync($validated['condomini_ids'] ?? []);
            
            if (!empty($validated['anagrafiche'])) {
                $evento->anagrafiche()->sync($validated['anagrafiche']);
            }

            DB::commit();

            return to_route('admin.eventi.index')->with(
                $this->flashSuccess(__('eventi.success_create_event'))
            );

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Error creating agenda event: ' . $e->getMessage());

            return to_route('admin.eventi.index')->with(
                $this->flashError(__('eventi.error_create_event'))
            );
        }
    }

    /**
     * Display the specified Evento (not yet implemented).
     *
     * @param Evento $evento
     * @return void
     */
    public function show(Evento $evento)
    {
        //
    }

    /**
     * Show the form for editing the specified Evento (not yet implemented).
     *
     * @param Evento $evento
     * @return void
     */
    public function edit(Evento $evento, Request $request): Response
    {
        Gate::authorize('update', $evento);

        $request->validate([
            'mode'            => 'nullable|in:only_this,all',
            'occurrence_date' => 'nullable|date',
        ]);

        $mode = $request->query('mode', 'only_this');
        $occurrenceDate = $request->query('occurrence_date', null);

        $evento->loadMissing(['createdBy.anagrafica', 'condomini', 'anagrafiche', 'ricorrenza', 'categoria']); 

        return Inertia::render('eventi/EventiEdit', [
         'evento'        => new EditEventoResource($evento),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all()),
         'categorie'     => CategoriaEventoResource::collection(CategoriaEvento::all()),
         'mode' => $mode,
         'occurrenceDate' => $occurrenceDate,
        ]);
    }

    /**
     * Update the specified Evento in storage (not yet implemented).
     *
     * @param Request $request
     * @param Evento $evento
     * @return void
     */
    public function update(EditEventoRequest $request, Evento $evento): RedirectResponse
    {
        Gate::authorize('update', $evento);

        $validated = $request->validated();
        $mode = $validated['mode'] ?? 'all';

        DB::beginTransaction();
        try {
            $wasRecurring = $evento->recurrence_id !== null;
            $willBeRecurring = !empty($validated['recurrence_frequency']);

            switch ($mode) {
                case 'only_this':
                    if (!$wasRecurring) {
                        if ($willBeRecurring) {
                            $this->eventoService->convertToRecurringEvent($evento, $validated);
                        } else {
                            $this->eventoService->updateSingleEvent($evento, $validated);
                        }
                    } else {
                        if (!isset($validated['occurrence_date'])) {
                            throw new \InvalidArgumentException("Occurrence date is required");
                        }
                        $this->eventoService->handleSingleOccurrenceUpdate($evento, $validated);
                    }
                    break;

                case 'all':
                    if ($wasRecurring && !$willBeRecurring) {
                        $this->eventoService->convertToSingleEvent($evento, $validated);
                    } elseif (!$wasRecurring && $willBeRecurring) {
                        $this->eventoService->convertToRecurringEvent($evento, $validated);
                    } elseif ($wasRecurring && $willBeRecurring) {
                        $this->eventoService->updateRecurringSeries($evento, $validated);
                    } else {
                        $this->eventoService->updateSingleEvent($evento, $validated);
                    }
                    break;

                default:
                    throw new \InvalidArgumentException("Invalid update mode");
            }

            DB::commit();

            return to_route('admin.eventi.index')->with(
                $this->flashSuccess(__('eventi.success_update_event'))
            );

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error("Event update failed: {$e->getMessage()}");

            return back()->with(
                $this->flashError(__('eventi.error_update_event'))
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\Evento $evento
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function destroy(Request $request, Evento $evento): RedirectResponse
    {
        Gate::authorize('delete', $evento);

        $mode = $request->input('mode', 'only_this'); 

        if (!$evento->recurrence_id) {
            // One-time event — just delete it
            $evento->delete();

            return back()->with(
                $this->flashSuccess(__('eventi.success_delete_event'))
            );
        }

        // 🔹 Recurring event
        $occurrenceDate = $request->input('occurrence_date');

        // Validate occurrenceDate once for relevant modes
        if (in_array($mode, ['only_this', 'this_and_future']) && !$occurrenceDate) {
            abort(400, 'Missing occurrence_date for recurring event.');
        }

        switch ($mode) {
            
            case 'only_this':
                EccezioneEvento::create([
                    'recurrence_id'  => $evento->recurrence_id,
                    'evento_id'      => $evento->id,
                    'exception_date' => $occurrenceDate,
                    'is_deleted'     => true,
                    'override_data'  => null,
                ]);
                break;

            case 'this_and_future':
                DB::transaction(function () use ($evento, $occurrenceDate) {
                    $ricorrenza = $evento->ricorrenza;

                    if (!$ricorrenza) {
                        abort(400, 'No recurrence rule found for this event.');
                    }

                    $timezone = new \DateTimeZone(config('app.timezone') ?? 'UTC');
                    $occurrence = new \DateTime($occurrenceDate, $timezone);

                    $cutoff = (clone $occurrence)->modify('-1 second');
                    $eventStart = new \DateTime($evento->start_time, $timezone);
                    if ($cutoff < $eventStart) {
                        $cutoff = clone $eventStart;
                    }

                    // Adjust recurrence rule to end before the cutoff date
                    $oldRule = new \Recurr\Rule(
                        $ricorrenza->rrule,
                        $eventStart,
                        null,
                        config('app.timezone')
                    );
                    $oldRule->setUntil($cutoff);

                    $ricorrenza->update([
                        'until' => $cutoff->format('Y-m-d H:i:s'),
                        'rrule' => $oldRule->getString(),
                    ]);

                    // Delete future event occurrences starting from the cutoff date
                    Evento::where('recurrence_id', $evento->recurrence_id)
                        ->where('start_time', '>=', $occurrence->format('Y-m-d H:i:s'))
                        ->delete();

                    // Check if any events remain linked to this recurrence
                    $remainingEvents = Evento::where('recurrence_id', $evento->recurrence_id)->count();

                    if ($remainingEvents === 0) {
                        // No events left, delete recurrence rule and exceptions
                        $ricorrenza->delete();
                        EccezioneEvento::where('recurrence_id', $evento->recurrence_id)->delete();
                    }
                });
                break;

            case 'all':
                DB::transaction(function () use ($evento) {
                    // Delete all events linked to recurrence, recurrence rule, and exceptions
                    Evento::where('recurrence_id', $evento->recurrence_id)->delete();
                    $evento->ricorrenza()->delete();
                    EccezioneEvento::where('recurrence_id', $evento->recurrence_id)->delete();
                });
                break;

            default:
                abort(400, 'Invalid deletion mode.');
        }

        return back()->with(
            $this->flashSuccess(__('eventi.success_delete_event'))
        );
    }

}

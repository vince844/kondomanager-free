<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\CreateEventoRequest;
use App\Http\Requests\Evento\EventoIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\EccezioneEvento;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Services\RecurrenceService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Recurr\Rule;
use Inertia\Response;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class EventoController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private RecurrenceService $recurrenceService) {}

    /**
     * Display a paginated list of upcoming eventi with optional filters.
     *
     * @param EventoIndexRequest $request The validated index request.
     * @return Response The rendered event list.
     */
    public function index(EventoIndexRequest $request): Response
    {
        $validated = $request->validated();
        
        $perPage = min((int) ($validated['per_page'] ?? 10), 100);
        $page = (int) ($validated['page'] ?? 1);

        $events = $this->recurrenceService->getEventsInNextDays(
            days: 360,
            filters: Arr::only($validated, ['title', 'category_id', 'search', 'date_from', 'date_to']),
            page: $page,
            perPage: $perPage
        );

        return Inertia::render('eventi/EventiList', [
            'eventi' => EventoResource::collection($events->items()),
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
    public function create(): Response
    {
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
    public function store(CreateEventoRequest $request): RedirectResponse
    {
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
    public function edit(Evento $evento)
    {
        //
    }

    /**
     * Update the specified Evento in storage (not yet implemented).
     *
     * @param Request $request
     * @param Evento $evento
     * @return void
     */
    public function update(Request $request, Evento $evento)
    {
        //
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
        $mode = $request->input('mode', 'only_this'); 

        if (!$evento->recurrence_id) {
            // One-time event — just delete it
            $evento->delete();
            return back()->with('success', 'Evento eliminato con successo.');
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
                    'recurrence_id' => $evento->recurrence_id,
                    'evento_id' => $evento->id,
                    'exception_date' => $occurrenceDate,
                    'is_deleted' => true,
                    'override_data' => null,
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

        return back()->with('success', 'Evento eliminato con successo.');
    }

}

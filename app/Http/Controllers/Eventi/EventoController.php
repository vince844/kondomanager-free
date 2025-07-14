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

class EventoController extends Controller
{

    use HandleFlashMessages;

    public function __construct(private RecurrenceService $recurrenceService) {}

    /**
     * Display a listing of the resource.
     */
    public function index(EventoIndexRequest $request): Response
    {
        $validated = $request->validated();
        
        // Set safe pagination limits
        $perPage = min((int) ($validated['per_page'] ?? 10), 100); // Max 100 items per page
        $page = (int) ($validated['page'] ?? 1);

        // Get filtered and paginated events
        $events = $this->recurrenceService->getEventsInNextDays(
            days: 60,
            filters: Arr::only($validated, ['title', 'category_id', 'search']),
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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('eventi/EventiNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'categorie'   => CategoriaEventoResource::collection(CategoriaEvento::all()),
            'anagrafiche' => []
        ]);  
    }

    /**
     * Store a newly created Evento in the database.
     *
     * This method handles validation, optional recurrence setup, event creation,
     * and relationship synchronization with condomini and anagrafiche.
     * It uses a database transaction to ensure atomicity.
     *
     * @param  \App\Http\Requests\Evento\CreateEventoRequest $request The validated request containing event and recurrence data.
     *
     * @return \Illuminate\Http\RedirectResponse A redirect response back to the eventi index route with a success or error message.
     *
     * @throws \Throwable If any part of the transaction fails, the exception is caught and the transaction is rolled back.
     */
    public function store(CreateEventoRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Create the base event
            $evento = Evento::create([
                'title'        => $validated['title'],
                'description'  => $validated['description'] ?? null,
                'start_time'   => $validated['start_time'],
                'note'         => $validated['note'] ?? null,
                'end_time'     => $validated['end_time'],
                'created_by'   => $validated['created_by'],
                'category_id'  => $validated['category_id'] ?? null,
                'timezone'     => config('app.timezone'),
                'visibility'   => $validated['visibility'] ?? 'public',
            ]);

            // Handle recurrence if provided
            if (!empty($validated['recurrence_frequency'])) {
                $rule = new Rule(
                    null,
                    new \DateTime($validated['start_time'], new \DateTimeZone(config('app.timezone'))),
                    !empty($validated['recurrence_until'])
                        ? new \DateTime($validated['recurrence_until'], new \DateTimeZone(config('app.timezone')))
                        : null,
                    config('app.timezone')
                );

                $rule->setFreq(strtoupper($validated['recurrence_frequency']))
                    ->setInterval($validated['recurrence_interval'] ?? 1);

                if (!empty($validated['recurrence_by_day'])) {
                    $byDay = is_array($validated['recurrence_by_day'])
                        ? $validated['recurrence_by_day']
                        : explode(',', $validated['recurrence_by_day']);
                    $rule->setByDay($byDay);
                }

                if (!empty($validated['recurrence_by_month_day'])) {
                    $rule->setByMonthDay([$validated['recurrence_by_month_day']]);
                }

                $transformer = new ArrayTransformer();
                if ($validated['recurrence_frequency'] === 'monthly') {
                    $transformerConfig = new ArrayTransformerConfig();
                    $transformerConfig->enableLastDayOfMonthFix();
                    $transformer->setConfig($transformerConfig);
                }

                $transformer->transform($rule); // Validate the rule

                $ricorrenza = RicorrenzaEvento::create([
                    'frequency'      => $validated['recurrence_frequency'],
                    'interval'       => $validated['recurrence_interval'] ?? 1,
                    'by_day'         => !empty($byDay) ? json_encode($byDay) : null,
                    'by_month_day'   => $validated['recurrence_by_month_day'] ?? null,
                    'until'          => $validated['recurrence_until'] ?? null,
                    'type'           => 'rrule',
                    'rrule'          => $rule->getString(),
                    'timezone'       => config('app.timezone'),
                ]);

                $evento->update(['recurrence_id' => $ricorrenza->id]);
            }

            // Sync relations
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
     * Display the specified resource.
     */
    public function show(Evento $evento)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Evento $evento)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Evento $evento)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Evento $evento)
    {
        //
    }
}

<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\EventoIndexRequest;
use App\Http\Requests\Evento\Utenti\CreateEventoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Services\RecurrenceService;
use App\Traits\HandleFlashMessages;
use App\Traits\HandlesUserCondominioData;
use App\Traits\HasAnagrafica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Arr;
use Inertia\Response;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class EventoController extends Controller
{
    use HasAnagrafica, HandleFlashMessages, HandlesUserCondominioData;

    public function __construct(private RecurrenceService $recurrenceService) {}

    /**
     * Display a listing of the resource.
     */
    public function index(EventoIndexRequest $request, Evento $evento): Response
    {

        Gate::authorize('view', $evento);

        $validated = $request->validated();

        $perPage = min((int) ($validated['per_page'] ?? 10), 100);
        $page = (int) ($validated['page'] ?? 1);

        try {

            $userData = $this->getUserCondominioData();

            $events = $this->recurrenceService->getEventsInNextDays(
                days: 365,
                filters: Arr::only($validated, ['title', 'category_id', 'search', 'date_from', 'date_to']),
                page: $page,
                perPage: $perPage,
                anagrafica: $userData->anagrafica,
                condominioIds: $userData->condominioIds
            );

        } catch (\Exception $e) {

            Log::error('Error getting user events: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('eventi/user/EventiList', [
            'eventi' => [
                'data' => EventoResource::collection($events->items()),
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'search' => $validated['search'] ?? null,
            'filters' => $validated,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Evento $evento): Response
    {
        Gate::authorize('create', $evento);

        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;

        return Inertia::render('eventi/user/EventiNew', [
            'condomini'   => CondominioResource::collection($condomini),
            'categorie'   => CategoriaEventoResource::collection(CategoriaEvento::all()),
        ]);
    }

    /**
     * Store a newly created resource in storage.
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

            DB::commit();

            return to_route('user.eventi.index')->with(
                $this->flashSuccess(__('eventi.success_create_event'))
            );

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Error creating agenda event: ' . $e->getMessage());

            return to_route('user.eventi.index')->with(
                $this->flashError(__('eventi.error_create_event'))
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\EventoIndexRequest;
use App\Http\Requests\Evento\Utenti\CreateEventoRequest;
use App\Http\Requests\Evento\Utenti\EditEventoRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\Evento\EditEventoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Models\CategoriaEvento;
use App\Models\EccezioneEvento;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Services\EventoService;
use App\Services\RecurrenceService;
use App\Traits\HandleFlashMessages;
use App\Traits\HandlesUserCondominioData;
use App\Traits\HasAnagrafica;
use App\Traits\CalculatesFinancialWaterfall;
use App\Traits\PaginaElenco;
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
    use HasAnagrafica, HandleFlashMessages, HandlesUserCondominioData, CalculatesFinancialWaterfall, PaginaElenco;

    public function __construct(
        private RecurrenceService $recurrenceService,
        private EventoService $eventoService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(EventoIndexRequest $request, Evento $evento): Response
    {
        Gate::authorize('view', $evento);

        $validated = $request->validated();
        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $perPage = $this->righePerPagina($request);
        $page = (int) ($validated['page'] ?? 1);

        try {
            $userData = $this->getUserCondominioData();

            // 1. Recupero eventi (Calendario completo)
            $events = $this->recurrenceService->getEventsInNextDays(
                days: 360,
                filters: Arr::only($validated, ['title', 'category_id', 'search', 'date_from', 'date_to']),
                page: $page,
                perPage: $perPage,
                anagrafica: $userData->anagrafica,
                condominioIds: $userData->condominioIds
            );

            // 2. INTELLIGENZA: Selezioniamo solo i Piani Rate dell'Esercizio "Aperto"
            // Questo esclude automaticamente i piani dell'anno scorso (2025)
            // se l'esercizio 2025 è stato chiuso o se oggi siamo nel 2026.
            
            // Recuperiamo gli esercizi aperti per i condomini dell'utente
            $eserciziApertiIds = \App\Models\Esercizio::whereIn('condominio_id', $userData->condominioIds)
                ->where('stato', 'aperto') // Assumendo che 'aperto' sia lo status attivo
                ->pluck('id');

            // Recuperiamo le Gestioni collegate a questi esercizi aperti
            // (La relazione Esercizio -> Gestioni è ManyToMany o OneToMany a seconda del tuo schema,
            // ma qui passiamo per la tabella pivot o diretta).
            // Se PianoRate ha 'gestione_id', dobbiamo trovare le gestioni attive in questo esercizio.
            
            // Alternativa più diretta: Filtriamo i Piani Rate tramite la Gestione -> Esercizio
            $activePianoRateIds = \App\Models\Gestionale\PianoRate::query()
                ->whereIn('condominio_id', $userData->condominioIds)
                ->whereHas('gestione.esercizi', function ($q) use ($eserciziApertiIds) {
                    $q->whereIn('esercizi.id', $eserciziApertiIds);
                })
                ->pluck('id')
                ->toArray();

            // 3. APPLICAZIONE WATERFALL CON FILTRI
            $this->applyFinancialWaterfall(
                $events, 
                $userData->anagrafica->id,
                $userData->condominioIds,
                $activePianoRateIds // <--- PASSIAMO SOLO I PIANI DELL'ANNO CORRENTE
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

            if($validated['is_approved']){
                return to_route('user.eventi.index')->with(
                    $this->flashSuccess(__('eventi.success_create_event'))
                );
            }else{
                return to_route('user.eventi.index')->with(
                    $this->flashInfo(__('eventi.success_create_event_in_moderation'))
                );
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating agenda event: ' . $e->getMessage());
            return to_route('user.eventi.index')->with(
                $this->flashError(__('eventi.error_create_event'))
            );
        }
    }

    public function show(string $id) {}

    public function edit(Evento $evento, Request $request): Response
    {
        Gate::authorize('update', $evento);

        $request->validate([
            'mode'            => 'nullable|in:only_this,all',
            'occurrence_date' => 'nullable|date',
        ]);

        $mode = $request->query('mode', 'only_this');
        $occurrenceDate = $request->query('occurrence_date', null);

        $anagrafica = $this->getUserAnagrafica();
        $condomini = $anagrafica->condomini;

        $evento->loadMissing(['createdBy.anagrafica', 'condomini', 'ricorrenza', 'categoria']); 

        return Inertia::render('eventi/user/EventiEdit', [
            'evento'         => new EditEventoResource($evento),
            'condomini'      => CondominioOptionsResource::collection($condomini),
            'categorie'      => CategoriaEventoResource::collection(CategoriaEvento::all()),
            'mode'           => $mode,
            'occurrenceDate' => $occurrenceDate,
        ]);
    }

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

            return to_route('user.eventi.index')->with(
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

    public function destroy(Request $request, Evento $evento): RedirectResponse
    {
        Gate::authorize('delete', $evento);

        $mode = $request->input('mode', 'only_this'); 

        if (!$evento->recurrence_id) {
            $evento->delete();
            return back()->with(
                $this->flashSuccess(__('eventi.success_delete_event'))
            );
        }

        $occurrenceDate = $request->input('occurrence_date');

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
                    if (!$ricorrenza) abort(400, 'No recurrence rule found for this event.');

                    $timezone = new \DateTimeZone(config('app.timezone') ?? 'UTC');
                    $occurrence = new \DateTime($occurrenceDate, $timezone);
                    $cutoff = (clone $occurrence)->modify('-1 second');
                    $eventStart = new \DateTime($evento->start_time, $timezone);
                    if ($cutoff < $eventStart) $cutoff = clone $eventStart;

                    $oldRule = new \Recurr\Rule($ricorrenza->rrule, $eventStart, null, config('app.timezone'));
                    $oldRule->setUntil($cutoff);

                    $ricorrenza->update([
                        'until' => $cutoff->format('Y-m-d H:i:s'),
                        'rrule' => $oldRule->getString(),
                    ]);

                    Evento::where('recurrence_id', $evento->recurrence_id)
                        ->where('start_time', '>=', $occurrence->format('Y-m-d H:i:s'))
                        ->delete();

                    $remainingEvents = Evento::where('recurrence_id', $evento->recurrence_id)->count();
                    if ($remainingEvents === 0) {
                        $ricorrenza->delete();
                        EccezioneEvento::where('recurrence_id', $evento->recurrence_id)->delete();
                    }
                });
                break;

            case 'all':
                DB::transaction(function () use ($evento) {
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
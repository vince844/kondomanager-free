<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\CreateEventoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\RicorrenzaEvento;
use App\Services\RecurrenceService;
use App\Traits\HandleFlashMessages;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RRule\RRule;

use Illuminate\Pagination\LengthAwarePaginator;

class EventoController extends Controller
{

    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        $events = (new RecurrenceService)->getEventsInNextDays(60);

        // Sort before paginating (though your service already sorts)
        $sorted = $events->sortBy('occurs_at');

        $paginated = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render('eventi/EventiList', [
            'eventi' => EventoResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ]
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

            $ricorrenza = null;

            if (!empty($validated['recurrence_frequency'])) {

                $rruleArray = [
                    'FREQ' => strtoupper($validated['recurrence_frequency']),
                    'INTERVAL' => $validated['recurrence_interval'] ?? 1,
                    'DTSTART' => Carbon::parse($validated['start_time'])->toRfc3339String(),
                ];

                if (!empty($validated['recurrence_by_day'])) {
                    $rruleArray['BYDAY'] = $validated['recurrence_by_day'];
                }

                if (!empty($validated['recurrence_until'])) {
                    $rruleArray['UNTIL'] = Carbon::parse($validated['recurrence_until'])->toRfc3339String();
                }

                $rrule = new RRule($rruleArray);

                $ricorrenza = RicorrenzaEvento::create([
                    'frequency' => $validated['recurrence_frequency'],
                    'interval' => $validated['recurrence_interval'] ?? 1,
                    'by_day' => $validated['recurrence_by_day'] ?? [],
                    'until' => $validated['recurrence_until'] ?? null,
                    'type' => 'custom',
                    'rrule' => (string) $rrule,
                ]);
            }

            // Create the event regardless
            $evento = Evento::create([
                'title'          => $validated['title'],
                'description'    => $validated['description'] ?? null,
                'start_time'     => $validated['start_time'],
                'note'           => $validated['note'],
                'end_time'       => $validated['end_time'] ?? null,
                'recurrence_id'  => $ricorrenza?->id,
                'created_by'     => $validated['created_by'],
                'category_id'    => $validated['category_id'],
            ]);

            // Relationships
            $evento->condomini()->sync($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {
                $evento->anagrafiche()->sync($validated['anagrafiche']);
            }

            DB::commit();

        } catch (\Exception $e) {

            DB::rollBack();
            Log::error('Error creating agenda event: ' . $e->getMessage());

            return to_route('admin.eventi.index')->with(
                $this->flashError(__('eventi.error_create_event'))
            );
        }

        return to_route('admin.eventi.index')->with(
            $this->flashSuccess(__('eventi.success_create_event'))
        );
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

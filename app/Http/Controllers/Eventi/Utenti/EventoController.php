<?php

namespace App\Http\Controllers\Eventi\Utenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evento\EventoIndexRequest;
use App\Http\Resources\Evento\EventoResource;
use App\Models\Evento;
use App\Services\RecurrenceService;
use App\Traits\HandlesUserCondominioData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Arr;
use Inertia\Response;

class EventoController extends Controller
{
    use HandlesUserCondominioData;

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
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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

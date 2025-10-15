<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\CreatePianoContoRequest;
use App\Http\Requests\Gestionale\PianoConto\PianoContoIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiDeiConti\PianoDeiContiResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class PianoContiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a paginated listing of chart of accounts (Piani dei Conti) for the specified condominio.
     *
     * This method handles the index page for the chart of accounts. It validates the request,
     * filters accounts based on search criteria, and returns a paginated list of chart of accounts
     * associated with the given condominio. It also provides additional context data including
     * the current esercizio and list of condomini for navigation.
     *
     * @param PianoContoIndexRequest $request The validated request containing filters and pagination parameters
     * @param Condominio $condominio The condominio instance (from route binding) whose chart of accounts are being accessed
     * 
     * @return Response Returns an Inertia.js response rendering the chart of accounts list view
     * 
     * @uses PianoContoIndexRequest For request validation
     * @uses Condominio To access the condominio's chart of accounts relationship
     * @uses PianoDeiContiResource For transforming chart of accounts data for the frontend
     * 
     * @example
     * // Typical request: GET /condomini/1/piani-dei-conti?nome=spese&per_page=15
     * // Returns paginated chart of accounts for condominio ID 1, filtered by name containing "spese"
     * 
     * @throws \Illuminate\Validation\ValidationException If request validation fails
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio not found
     * 
     * @query_parameters
     * - nome: (optional) Filter chart of accounts by name containing the specified string
     * - per_page: (optional) Number of items per page (defaults to config value)
     * 
     * @response_data
     * - condominio: Current condominio context
     * - esercizio: Current active financial period for navigation
     * - condomini: List of all condomini for context switching
     * - pianiDeiConti: Paginated chart of accounts data
     * - meta: Pagination metadata
     * - filters: Current filter values for UI state persistence
     */
    public function index(PianoContoIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Filtriamo i piani dei conti del condominio, relativi alle gestioni collegate all'esercizio selezionato
        $pianiDeiConti = $condominio->pianiDeiConti()
            ->whereHas('gestione.esercizi', function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            })
            ->with(['gestione.esercizi' => function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            }])
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Tutti gli esercizi del condominio, ordinati
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiList', [
            'condominio'      => $condominio,
            'esercizio'       => $esercizio,
            'esercizi'        => $esercizi,
            'condomini'       => CondominioResource::collection($this->getCondomini()),
            'pianiDeiConti'   => PianoDeiContiResource::collection($pianiDeiConti)->resolve(),
            'meta' => [
                'current_page' => $pianiDeiConti->currentPage(),
                'last_page'    => $pianiDeiConti->lastPage(),
                'per_page'     => $pianiDeiConti->perPage(),
                'total'        => $pianiDeiConti->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    /**
     * Show the form for creating a new chart of accounts entry (Piano dei Conti).
     *
     * This method displays the chart of accounts creation form for a specific condominio.
     * It prepares the necessary context data for the frontend form including:
     * - List of all condomini for navigation
     * - Current active esercizio for financial context
     * - Available gestioni with open esercizi for the current condominio
     *
     * The gestioni are filtered to only include those that have open esercizi
     * associated with the current condominio, ensuring proper financial period context.
     *
     * @param Condominio $condominio The condominio instance (from route binding) that will own the new chart of accounts entry
     * 
     * @return Response Returns an Inertia.js response rendering the chart of accounts creation form
     * 
     * @uses Condominio To establish the current condominio context
     * @uses Gestione To retrieve available management periods with open exercises
     * 
     * @example
     * // Typical request: GET /condomini/1/piani-dei-conti/create
     * // Returns the chart of accounts creation form for condominio ID 1
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio not found
     * 
     * @context_data
     * - condominio: Current condominio context
     * - esercizio: Current active financial period for navigation
     * - condomini: List of all condomini for context switching
     * - gestioni: Available management periods with open exercises for the current condominio
     * 
     * @gestioni_filtering
     * - Loads gestioni with their related esercizi
     * - Filters esercizi to only those belonging to current condominio and in 'aperto' (open) state
     * - Ensures only gestioni that have valid open exercises are included
     * - Provides management context for financial operations
     */
    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        
        $condomini = $this->getCondomini();

        // Tutti gli esercizi del condominio, ordinati
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        // Recupera solo le gestioni associate allo specifico esercizio
        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreatePianoContoRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            
            $pianoConto = PianoConto::create($data);

            return to_route('admin.gestionale.spese.index', [
                'condominio' => $condominio->id,
                'conto'    => $pianoConto->id,
            ])->with('success', __('gestionale.success_create_piano_conto'));


        } catch (\Throwable $e) {

            Log::error('Error creating piano conti', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.conti.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_piano_conto')));
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
    public function edit(Condominio $condominio, Esercizio $esercizio, PianoConto $conto): Response
    {

        $conto->loadMissing(['gestione']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

         return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiEdit', [
            'condominio'   => $condominio,
            'esercizio'    => $esercizio,
            'gestioni'     => $gestioni,
            'pianoConti'   => new PianoDeiContiResource($conto),
        ]);
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

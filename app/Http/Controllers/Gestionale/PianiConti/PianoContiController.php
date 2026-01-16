<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\CreatePianoContoRequest;
use App\Http\Requests\Gestionale\PianoConto\PianoContoIndexRequest;
use App\Http\Requests\Gestionale\PianoConto\UpdatePianoContoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiDeiConti\Conti\ContoResource;
use App\Http\Resources\Gestionale\PianiDeiConti\PianoDeiContiResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class PianoContiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a paginated listing of chart ofexpenses (Piani dei Conti) for the specified condominio.
     *
     * This method handles the index page for the chart of accounts. It validates the request,
     * filters accounts based on search criteria, and returns a paginated list of chart of expenses
     * associated with the given condominio. It also provides additional context data including
     * the current esercizio and list of condomini for navigation.
     *
     * @param PianoContoIndexRequest $request The validated request containing filters and pagination parameters
     * @param Condominio $condominio The condominio instance (from route binding) whose chart of accounts are being accessed
     * 
     * @return Response Returns an Inertia.js response rendering the chart of expenses list view
     * 
     * @uses PianoContoIndexRequest For request validation
     * @uses Condominio To access the condominio's chart of accounts relationship
     * @uses PianoDeiContiResource For transforming chart of expenses data for the frontend
     * @uses CondominioResource For transforming condominio data for the frontend
     * 
     * @throws \Illuminate\Validation\ValidationException If request validation fails
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominio not found
     * 
     */
    public function index(PianoContoIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
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
     * @param Condominio $condominio The condominio instance
     * @param Esercizio $esercizio The active exercise
     * @return Response|RedirectResponse Returns an Inertia.js response or redirects if no available gestioni
     * @since v1.8.0
     */
    public function create(Condominio $condominio, Esercizio $esercizio)
    {
        $condomini = $this->getCondomini();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        // LOGICA DI FILTRO AVANZATO:
        // 1. Prendi le gestioni collegate a questo esercizio
        // 2. ESCLUDI quelle che hanno già un piano dei conti associato (relazione pianoConto)
        //    (La relazione pianoConto() l'ho vista nel tuo Model Gestione, è corretta)
        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->whereDoesntHave('pianoConto') // <--- IL FILTRO MAGICO
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        // 3. BLOCCO PREVENTIVO:
        // Se tutte le gestioni dell'esercizio hanno già un piano dei conti,
        // non ha senso mostrare il form vuoto. Rimandiamo indietro l'utente.
        if ($gestioni->isEmpty()) {
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio' => $esercizio->id
            ])->with($this->flashWarning(__('gestionale.warning_all_gestioni_have_piano_conti'))); 
        }

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Create a new chart of expenses (Piano Conto) for a specific fiscal year.
     *
     * This method handles the creation of a new chart of expenses within a given condominium and fiscal year.
     * It processes validated request data to create a new PianoConto record and redirects to the newly created chart of expenses.
     * The method includes comprehensive error handling and logging for troubleshooting.
     *
     * @param \App\Http\Requests\CreatePianoContoRequest $request The validated form request containing chart of accounts data
     * @param \App\Models\Condominio $condominio The condominium entity where the chart of accounts will be created
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity to associate with the chart of accounts
     * 
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Throwable Any exception that occurs during the creation process
     * 
     * @validation Uses CreatePianoContoRequest for input validation and authorization
     * 
     * @uses \App\Models\PianoConto
     * @uses \Illuminate\Support\Facades\Log
     * @uses \App\Traits\HandleFlashMessages
     * 
     * @workflow
     * 1. Validate incoming request data using CreatePianoContoRequest
     * 2. Create new PianoConto record with validated data
     * 3. Redirect to the show page of the newly created chart of expenses
     * 4. Handle any exceptions with proper logging and error feedback
     * 
     */
    public function store(CreatePianoContoRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            
            $pianoConto = PianoConto::create($data);

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                'condominio'   => $condominio->id,
                'esercizio'    => $esercizio->id,
                'pianoConto'   => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_create_piano_conto')));


        } catch (\Throwable $e) {

            Log::error('Error creating piano conti', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_create_piano_conto')));
        }
    }

    /**
     * Display the details of a specific chart of expenses with its hierarchical structure.
     *
     * This method retrieves and displays a chart of expenses (Piano Conto) along with its complete
     * hierarchical account structure. It loads all top-level expenses (conti) with their nested
     * sub-accounts (sottoconti) and associated millesimal tables and distributions.
     * The data is formatted for display in an Inertia.js Vue component with optimized relationships.
     *
     * @param \App\Models\Condominio $condominio The condominium entity that owns the chart of accounts
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity context
     * @param \App\Models\PianoConto $pianoConto The chart of accounts to display
     * 
     * @return \Inertia\Response Inertia response rendering the accounts management interface
     * 
     */
    public function show(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $conti = Conto::with([
            'sottoconti.tabelleMillesimali.tabella',
            'sottoconti.tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto'),
            'tabelleMillesimali.tabella', 
            'tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto')
        ])
        ->where('piano_conto_id', $pianoConto->id)
        ->whereNull('parent_id')
        ->orderBy('nome')
        ->get();

        return Inertia::render('gestionale/pianiDeiConti/conti/ContiNew', [
            'condominio'   => [
                'id'   => $condominio->id,
                'nome' => $condominio->nome
            ],
            'esercizio'  => [
                'id'   => $esercizio->id,
                'nome' => $esercizio->nome
            ],
            'pianoConti' => new PianoDeiContiResource($pianoConto),
            'conti'      => ContoResource::collection($conti),
        ]);
    }

    /**
     * Display the form for editing an existing chart of expenses (Piano Conto).
     *
     * This method prepares and displays the edit form for a specific chart of expenses.
     * It loads the chart of expenses with its related management data and retrieves
     * available management options (gestioni) filtered by the current fiscal year.
     * The data is formatted for display in an Inertia.js Vue component.
     *
     * @param \App\Models\Condominio $condominio The condominium entity that owns the chart of expenses
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity context
     * @param \App\Models\PianoConto $pianoConto The chart of expenses to be edited
     * 
     * @return \Inertia\Response Inertia response rendering the chart of expenses edit form
     * 
     */
    public function edit(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $pianoConto->loadMissing(['gestione']);

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
            'pianoConti'   => new PianoDeiContiResource($pianoConto),
        ]);
    }

    /**
     * Update an existing chart of expenses (Piano Conto) with validated data.
     *
     * This method handles the modification of an existing chart of expenses within a condominium and fiscal year context.
     * It processes validated request data to update the PianoConto record and provides appropriate user feedback.
     * The method includes comprehensive error handling and logging for troubleshooting purposes.
     *
     * @param \App\Http\Requests\UpdatePianoContoRequest $request The validated form request containing updated chart of expenses data
     * @param \App\Models\Condominio $condominio The condominium entity that owns the chart of expenses
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity context
     * @param \App\Models\PianoConto $pianoConto The chart of expenses to be updated
     * 
     * @return \Illuminate\Http\RedirectResponse Redirects to the chart of expenses index page with flash message
     * 
     * @throws \Throwable Any exception that occurs during the update process
     * 
     * @validation Uses UpdatePianoContoRequest for input validation and authorization
     * 
     * @uses \Illuminate\Support\Facades\Log
     * @uses \App\Traits\HandleFlashMessages
     * 
     * @workflow
     * 1. Validate incoming request data using UpdatePianoContoRequest
     * 2. Update the PianoConto record with validated data
     * 3. On success: redirect to index with success message
     * 4. On error: log detailed error information and redirect to index with error message
     * 
     */
    public function update(UpdatePianoContoRequest $request,Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            
            $pianoConto->update($data);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio'   => $condominio->id,
                'esercizio'    => $esercizio->id,
                'pianoConto'   => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_update_piano_conto')));

        } catch (\Throwable $e) {

            Log::error('Error updating piano conti', [
                'condominio_id'  => $condominio->id,
                'piano_conto_id' => $pianoConto->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
                'pianoConto'  => $pianoConto->id,
            ])->with($this->flashError(__('gestionale.error_update_piano_conto')));
        }
    }

    /**
     * Delete a chart of expenses (Piano Conto) for a specific condominium and fiscal year.
     *
     * This method handles the soft deletion (if implemented) or permanent deletion of a chart of expenses.
     * It removes the specified PianoConto record and provides appropriate feedback to the user.
     * The method includes comprehensive error handling and logging for troubleshooting purposes.
     *
     * @param \App\Models\Condominio $condominio The condominium entity that owns the chart of expenses
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity associated with the chart of expenses
     * @param \App\Models\PianoConto $pianoConto The chart of accounts to be deleted
     * 
     * @return \Illuminate\Http\RedirectResponse Redirects to the chart of expenses index page with flash message
     * 
     * @throws \Throwable Any exception that occurs during the deletion process
     * 
     * @uses \Illuminate\Support\Facades\Log
     * @uses \App\Traits\HandleFlashMessages
     * 
     * @workflow
     * 1. Attempt to delete the specified PianoConto record
     * 2. On success: redirect to index with success message
     * 3. On error: log detailed error information and redirect to index with error message
     * 
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {

            $pianoConto->delete();

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashSuccess(__('gestionale.success_delete_piano_conto')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting piano conto', [
                'condominio_id'  => $condominio->id,
                'esercizio_id'   => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashError(__('gestionale.error_delete_piano_conto')));
        }
    }
}

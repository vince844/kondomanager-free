<?php

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Esercizio\CreateEsercizioRequest;
use App\Http\Requests\Gestionale\Esercizio\EsercizioIndexRequest;
use App\Http\Requests\Gestionale\Esercizio\UpdateEsercizioRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\Esercizi\EsercizioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class EsercizioController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a paginated list of exercises for a specific condominium.
     * 
     * This method retrieves and filters exercises based on the request parameters,
     * and returns the data needed to render the exercises list view. It also provides
     * additional context for navigation and breadcrumb components.
     *
     * @param EsercizioIndexRequest $request The validated request containing filters and pagination parameters
     * @param Condominio $condominio The condominium for which to retrieve exercises
     * 
     * @return Response Inertia response rendering the exercises list view with paginated data
     * 
     * @uses Condominio::esercizi() Eloquent relationship to access condominium's exercises
     * @uses EsercizioResource::collection() Transforms exercise data for the frontend
     * 
     * @requestParameters
     * - nome (optional): Filter exercises by name (partial match)
     * - per_page (optional): Number of items per page (default from config)
     * 
     * @responseIncludes
     * - condominio: Current condominium data
     * - esercizio: Currently active/open exercise for navigation
     * - condomini: List of all condominiums for breadcrumb dropdown
     * - esercizi: Paginated list of exercises with metadata
     * - filters: Current filter values for UI state persistence
     */
    public function index(EsercizioIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Get a list of all the esercizi create to show in the datatable
        $esercizi = $condominio->esercizi()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/esercizi/EserciziList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'esercizi'   => EsercizioResource::collection($esercizi)->resolve(),
            'meta'       => [
                'current_page' => $esercizi->currentPage(),
                'last_page'    => $esercizi->lastPage(),
                'per_page'     => $esercizi->perPage(),
                'total'        => $esercizi->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Display the form for creating a new exercise for a condominium.
     * 
     * This method prepares and returns the necessary data to render the exercise creation form.
     * It provides the current condominium context, active exercise for navigation,
     * and the list of all condominiums for breadcrumb navigation.
     *
     * @param Condominio $condominio The condominium for which the new exercise will be created
     * 
     * @return Response Inertia response rendering the exercise creation form with context data
     * 
     * @uses getCondomini() Retrieves all condominiums for navigation dropdown
     * @uses getEsercizioCorrente() Gets the current active exercise for menu navigation
     * 
     * @responseIncludes
     * - condominio: Current condominium context
     * - esercizio: Currently active exercise for navigation menu
     * - condomini: List of all condominiums for breadcrumb dropdown
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/esercizi/EserciziNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
        ]);
    }

    /**
     * Store a newly created exercise in storage.
     * 
     * This method handles the creation of a new exercise for the specified condominium.
     * It validates the incoming request data, creates the exercise record, and returns
     * appropriate feedback to the user. Any exceptions during the process are logged
     * for debugging purposes.
     *
     * @param CreateEsercizioRequest $request The validated request containing exercise data
     * @param Condominio $condominio The condominium to which the exercise belongs
     * 
     * @return RedirectResponse Redirects to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the creation process
     * 
     * @uses CreateEsercizioRequest::validated() Gets validated exercise data
     * @uses Esercizio::create() Persists the new exercise record
     * 
     * @flashMessages
     * - Success: 'gestionale.success_create_esercizio'
     * - Error: 'gestionale.error_create_esercizio'
     * 
     * @logContext
     * - condominio_id: ID of the related condominium
     * - message: Exception message
     * - trace: Full exception stack trace
     */
    public function store(CreateEsercizioRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            Esercizio::create($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_create_esercizio')));

        } catch (\Throwable $e) {
            Log::error('Error creating esercizio', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_esercizio')));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Esercizio $esercizio)
    {
        //
    }

    /**
     * Display the form for editing an existing exercise.
     * 
     * This method retrieves the specified exercise and prepares the data needed
     * to render the exercise editing form. The exercise data is transformed
     * using EsercizioResource for consistent API response formatting.
     *
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise instance to be edited
     * 
     * @return Response Inertia response rendering the exercise edit form with exercise data
     * 
     * @uses EsercizioResource Transforms exercise data for frontend consumption
     * 
     * @responseIncludes
     * - condominio: Current condominium context
     * - esercizio: Exercise data formatted via EsercizioResource for editing
     */
    public function edit(Condominio $condominio, Esercizio $esercizio): Response
    {
        return Inertia::render('gestionale/esercizi/EserciziEdit', [
            'condominio' => $condominio,
            'esercizio'  => new EsercizioResource($esercizio),
        ]);
    }

    /**
     * Update the specified exercise in storage.
     * 
     * This method handles updating an existing exercise with validated request data.
     * It processes the update operation and provides appropriate feedback to the user.
     * Any exceptions during the update process are logged with detailed context for debugging.
     *
     * @param UpdateEsercizioRequest $request The validated request containing updated exercise data
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise instance to be updated
     * 
     * @return RedirectResponse Redirects to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the update process
     * 
     * @uses UpdateEsercizioRequest::validated() Gets validated update data
     * @uses Esercizio::update() Persists the changes to the exercise record
     * 
     * @flashMessages
     * - Success: 'gestionale.success_update_esercizio'
     * - Error: 'gestionale.error_update_esercizio'
     * 
     * @logContext
     * - esercizio_id: ID of the exercise being updated
     * - condominio_id: ID of the related condominium
     * - message: Exception message
     * - trace: Full exception stack trace
     */
    public function update(UpdateEsercizioRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {

            $data = $request->validated();
            $esercizio->update($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_update_esercizio')));

        } catch (\Throwable $e) {

            Log::error('Error updating esercizio', [
                'esercizio_id'  => $esercizio->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_update_esercizio')));
            
        }
    }

    /**
     * Delete a specific exercise for a condominium.
     * 
     * This method handles the safe deletion of an exercise by enforcing business rules:
     * - Prevents deletion if it's the last exercise for the condominium
     * - Prevents deletion if the exercise is currently in 'open' state
     * - Only allows deletion of closed exercises when multiple exercises exist
     * 
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise to be deleted
     * 
     * @return RedirectResponse Redirects back to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the deletion process
     * 
     * @uses Condominio::esercizi() Relationship to count total exercises
     * @uses Esercizio::delete() To permanently remove the exercise
     * 
     * @flashMessages
     * - Success: 'gestionale.success_delete_esercizio'
     * - Error: 'gestionale.error_delete_last_esercizio' (if last exercise)
     * - Error: 'gestionale.error_delete_opened_esercizio' (if exercise is open)
     * - Error: 'gestionale.error_delete_esercizio' (for general errors)
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {

            $numeroEsercizi = $condominio->esercizi()->count();

            // Impedisci sempre eliminazione se:
            // 1. È l'ultimo esercizio
            // 2. È l'esercizio aperto
            if ($numeroEsercizi <= 1) {
                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(__('gestionale.error_delete_last_esercizio')));
            }

            if ($esercizio->stato === 'aperto') {
                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(__('gestionale.error_delete_opened_esercizio')));
            }

            // Elimina solo esercizi chiusi che non sono l'ultimo
            $esercizio->delete();

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_esercizio')));

        } catch (\Throwable $e) {

            Log::error('Error deleting esercizio', [
                'esercizio_id'   => $esercizio->id,
                'condominio_id'  => $condominio->id,
                'message'        => $e->getMessage(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_esercizio')));
        }
    }

}

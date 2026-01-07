<?php

namespace App\Http\Controllers\Gestionale\Immobili;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\CreateImmobileRequest;
use App\Http\Requests\Gestionale\Immobile\ImmobileIndexRequest;
use App\Http\Requests\Gestionale\Immobile\UpdateImmobileRequest;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Http\Resources\Gestionale\Immobili\TipologiaImmobileResource;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing Immobili (properties) within a Condominio.
 *
 * Handles all CRUD operations, including:
 *  - Listing immobili
 *  - Creating new immobile
 *  - Viewing immobile details
 *  - Editing immobile
 *  - Updating immobile
 *  - Deleting immobile
 *
 * Integrates flash messages via the HandleFlashMessages trait
 * Integrates list of registered condomini via the HasCondomini trait
 * Integrates current opened esercio via the HasEsercizio trait
 * Uses RedirectHelper to manage "back or fallback" redirects cleanly.
 */
class ImmobileController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a paginated listing of immobili (properties) for a specific condominio and esercizio.
     *
     * This method handles the index page for immobili management, providing filtered and paginated results
     * based on request parameters. It includes related data for the datatable view.
     *
     * @param \App\Http\Requests\ImmobileIndexRequest $request The validated request containing filter parameters
     * @param \App\Models\Condominio $condominio The condominio context for the immobili
     * @param \App\Models\Esercizio $esercizio The esercizio context for the immobili
     * 
     * @return \Inertia\Response Inertia response with immobili data and related context
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     *
     * @example
     * GET /gestionale/condomini/1/esercizi/1/immobili?nome=villa&per_page=15
     */
    public function index(ImmobileIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();

        // Get a list of all the esercizi create to show in the datatable
        $immobili = $condominio
            ->immobili()
            ->with(['palazzina', 'scala', 'tipologiaImmobile'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Get a list of all the registered condomini 
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio 
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/immobili/ImmobiliList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'immobili'   => ImmobileResource::collection($immobili)->resolve(),
            'meta'       => [
                'current_page' => $immobili->currentPage(),
                'last_page'    => $immobili->lastPage(),
                'per_page'     => $immobili->perPage(),
                'total'        => $immobili->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Display the immobile creation form for a specific condominio.
     *
     * Prepares and returns all necessary data for rendering the immobile creation interface:
     * - Condominio context and available condomini for navigation
     * - Current esercizio for financial context
     * - Related entities (palazzine, scale, tipologie) for form selection
     *
     * @param \App\Models\Condominio $condominio The condominio instance where the new immobile will be created.
     *                                           Loaded with palazzine and scale relationships for the form.
     * 
     * @return \Inertia\Response Returns an Inertia response containing:
     *                           - condominio: Current condominio context
     *                           - esercizio: Current active esercizio
     *                           - condomini: List of available condomini for navigation
     *                           - palazzine: Collection of palazzine in the condominio
     *                           - scale: Collection of scale in the condominio  
     *                           - tipologie: All available tipologie immobile
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to create immobili
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When condominio doesn't exist
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException When esercizio is not available
     *
     * @example
     * // Typical usage - navigates to immobile creation form
     * $response = $controller->create($condominio);
     */
    public function create(Condominio $condominio): Response
    {
        $condominio->load(['palazzine', 'scale']);

        $condomini = $this->getCondomini();

        // Get the current active and open esercizio 
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/ImmobiliNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
            'tipologie'  => TipologiaImmobile::all(),
        ]);
    }

    /**
     * Create a new immobile record in the database.
     *
     * Processes the validated form data to create a new immobile associated with the given condominio.
     * The method includes try-catch error handling with detailed logging for troubleshooting.
     * On success, redirects with a success message; on failure, redirects with an error message.
     *
     * @param \App\Http\Requests\CreateImmobileRequest $request The form request containing validated immobile data.
     *                                                         Includes authorization and validation rules.
     * @param \App\Models\Condominio $condominio The parent condominio to which the immobile will belong.
     * 
     * @return \Illuminate\Http\RedirectResponse 
     *         - On success: Redirects to immobili index with success flash message
     *         - On error: Redirects to immobili index with error flash message and logs the exception
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to create immobili
     * @throws \Illuminate\Validation\ValidationException When request data fails validation
     * 
     * @example
     * // Successful creation
     * $response = $controller->store($request, $condominio);
     * // Redirects with success message: "Immobile created successfully"
     * 
     * // Failed creation
     * $response = $controller->store($request, $condominio);  
     * // Redirects with error message: "Error creating immobile" and logs details
     *
     * @uses \App\Models\Immobile::create()
     * @see \App\Http\Requests\CreateImmobileRequest
     */
    public function store(CreateImmobileRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            
            Immobile::create($data);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_create_immobile')));

        } catch (\Throwable $e) {
            
            Log::error('Error creating immobile', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_immobile')));
        }
    }

    /**
     * Show detailed information for a specific immobile.
     *
     * Retrieves and displays the complete details of an immobile including its relationships
     * with palazzina, scala, and tipologia. The method ensures all required relationships
     * are loaded and provides the current esercizio context for financial reference.
     *
     * @param \App\Models\Condominio $condominio The parent condominio model that contains the immobile.
     *                                           Used for context and authorization.
     * @param \App\Models\Immobile $immobile The immobile model instance to display.
     *                                       Automatically loaded with missing relationships:
     *                                       - palazzina: The building reference
     *                                       - scala: The staircase/reference  
     *                                       - tipologiaImmobile: The property type classification
     * 
     * @return \Inertia\Response Returns an Inertia response containing:
     *                           - condominio: The parent condominio context
     *                           - esercizio: The current active esercizio for financial context
     *                           - immobile: The immobile data formatted via ImmobileResource
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to view the immobile
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When immobile or condominio doesn't exist
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException When esercizio is not available
     *
     * @example
     * // Typical usage - displays immobile details page
     * $response = $controller->show($condominio, $immobile);
     * 
     * // Route definition would typically be:
     * // Route::get('/condomini/{condominio}/immobili/{immobile}', [ImmobileController::class, 'show']);
     *
     * @uses \App\Models\Immobile::loadMissing() For eager loading relationships
     * @uses \App\Http\Resources\ImmobileResource For API resource transformation
     * @see \App\Models\Palazzina
     * @see \App\Models\Scala
     * @see \App\Models\TipologiaImmobile
     */
    public function show(Condominio $condominio, Immobile $immobile): Response
    {
        $immobile->loadMissing(['palazzina', 'scala', 'tipologiaImmobile']);

        // Get the current active and open esercizio 
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/ImmobiliView', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => new ImmobileResource($immobile),
        ]);
    }

    /**
     * Display the immobile editing form with pre-populated data.
     *
     * Prepares the edit interface for an existing immobile by loading its current data
     * and all necessary related entities for form selection. The method includes URL
     * remembering functionality to facilitate proper redirects after form submission.
     *
     * @param \App\Models\Condominio $condominio The parent condominio model that contains the immobile.
     *                                           Used for context and to fetch related palazzine and scale.
     * @param \App\Models\Immobile $immobile The immobile model instance to be edited.
     *                                       Loaded with missing relationships:
     *                                       - palazzina: Current building assignment
     *                                       - scala: Current staircase assignment
     *                                       - tipologiaImmobile: Current property type
     * 
     * @return \Inertia\Response Returns an Inertia response containing:
     *                           - condominio: Parent condominio context
     *                           - esercizio: Current active esercizio for navigation context
     *                           - immobile: The immobile data formatted via ImmobileResource
     *                           - palazzine: All palazzine in condominio for dropdown
     *                           - scale: All scale in condominio for dropdown
     *                           - tipologie: All available property types for dropdown
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to edit immobili
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When immobile or condominio doesn't exist
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException When esercizio is not available
     *
     * @example
     * // Typical usage - displays immobile edit form
     * $response = $controller->edit($condominio, $immobile);
     * 
     * // The method remembers the current URL for post-update redirects
     * RedirectHelper::rememberUrl(); // Enables returning to this page after update
     *
     * @uses \App\Helpers\RedirectHelper::rememberUrl() Stores current URL for redirect back after update
     * @uses \App\Http\Resources\ImmobileResource For transforming immobile data
     * @uses \App\Http\Resources\PalazzinaResource For transforming palazzina data  
     * @uses \App\Http\Resources\ScalaResource For transforming scala data
     * @uses \App\Http\Resources\TipologiaImmobileResource For transforming tipologie data
     */
    public function edit(Condominio $condominio, Immobile $immobile): Response
    {
        $immobile->loadMissing(['palazzina', 'scala', 'tipologiaImmobile']);

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        RedirectHelper::rememberUrl();

        return Inertia::render('gestionale/immobili/ImmobiliEdit', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => new ImmobileResource($immobile),
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
            'tipologie'  => TipologiaImmobileResource::collection(TipologiaImmobile::all()),
        ]);
    }

    /**
     * Update an existing immobile record in the database.
     *
     * Processes validated form data to update the specified immobile. The method features
     * intelligent redirect behavior that returns to the previously remembered URL (typically
     * from the edit form) or falls back to the immobili index page. Includes comprehensive
     * error handling with detailed logging for debugging purposes.
     *
     * @param \App\Http\Requests\UpdateImmobileRequest $request The form request containing validated update data.
     *                                                         Includes authorization and validation rules.
     * @param \App\Models\Condominio $condominio The parent condominio model for context and authorization.
     * @param \App\Models\Immobile $immobile The immobile model instance to be updated.
     * 
     * @return \Illuminate\Http\RedirectResponse 
     *         - On success: Redirects to remembered URL or index with success flash message
     *         - On error: Redirects to remembered URL or index with error flash message and logs exception
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to update immobili
     * @throws \Illuminate\Validation\ValidationException When request data fails validation
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When immobile or condominio doesn't exist
     *
     * @example
     * // Successful update - redirects to previously remembered URL (edit page)
     * $response = $controller->update($request, $condominio, $immobile);
     * // Redirects with success message: "Immobile updated successfully"
     * 
     * // Failed update - redirects with error message
     * $response = $controller->update($request, $condominio, $immobile);
     * // Redirects with error message: "Error updating immobile" and logs details
     *
     * @uses \App\Helpers\RedirectHelper::backOr() For intelligent redirect behavior
     * @uses \App\Models\Immobile::update() For database update operation
     * @see \App\Http\Requests\UpdateImmobileRequest For validation rules
     */
    public function update(UpdateImmobileRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        try {

            $data = $request->validated();
            $immobile->update($data);

            return RedirectHelper::backOr(
                route('admin.gestionale.immobili.index', $condominio),
                $this->flashSuccess(__('gestionale.success_update_immobile'))
            );

        } catch (\Throwable $e) {

            Log::error('Error updating immobile', [
                'immobile_id'   => $immobile->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return RedirectHelper::backOr(
                route('admin.gestionale.immobili.index', $condominio),
                $this->flashError(__('gestionale.error_update_immobile'))
            );
            
        }
    }

    /**
     * Delete an immobile record from the database.
     *
     * Permanently removes the specified immobile from the system. This operation
     * includes comprehensive error handling and logging to track deletion issues.
     * The method always redirects back to the immobili index page with appropriate
     * flash messages indicating success or failure of the operation.
     *
     * @param \App\Models\Condominio $condominio The parent condominio model for context and authorization.
     *                                           Used to maintain proper navigation context after deletion.
     * @param \App\Models\Immobile $immobile The immobile model instance to be deleted.
     * 
     * @return \Illuminate\Http\RedirectResponse 
     *         - On success: Redirects to immobili index with success flash message
     *         - On error: Redirects to immobili index with error flash message and logs exception
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException When user lacks permission to delete immobili
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When immobile or condominio doesn't exist
     * @throws \Illuminate\Database\QueryException When database constraints prevent deletion
     *
     * @example
     * // Successful deletion
     * $response = $controller->destroy($condominio, $immobile);
     * // Redirects with success message: "Immobile deleted successfully"
     * 
     * // Failed deletion (e.g., due to foreign key constraints)
     * $response = $controller->destroy($condominio, $immobile);
     * // Redirects with error message: "Error deleting immobile" and logs details
     *
     * @uses \App\Models\Immobile::delete() Performs the actual deletion operation
     * 
     * @warning This operation may be irreversible if soft deletes are not implemented.
     * @note Consider implementing soft deletes if you need to preserve data for audit purposes.
     */
    public function destroy(Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        try {

            $immobile->delete();

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_immobile')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting immobile', [
                'immobile_id'   => $immobile->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_immobile')));
        }
    }
}

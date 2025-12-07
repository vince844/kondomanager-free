<?php

namespace App\Http\Controllers\Condomini;

use App\Http\Controllers\Controller;
use App\Http\Requests\Condominio\CreateCondominioRequest;
use App\Http\Requests\Condominio\UpdateCondominioRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Services\CondominioService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

class CondominioController extends Controller
{
    use HandleFlashMessages;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\CondominioService
     */
    public function __construct(
        private CondominioService $condominioService,
    ) {}

    /**
     * Display a list of condomini with optional filtering and pagination.
     *
     * This method retrieves a list of condominios from the database, applying filters
     * (e.g., by name) if provided in the request, and returns a paginated list of results.
     * The method ensures that the user has permission to view the condominio list.
     *
     * @param \Illuminate\Http\Request $request The incoming HTTP request containing filter parameters.
     * @param \App\Models\Condominio $condominio The condominio model instance, used for authorization.
     *
     * @return \Inertia\Response A response that renders the building list view with the paginated data and filters.
     */
    public function index(Request $request, Condominio $condominio): Response
    {
        Gate::authorize('view', $condominio);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:10,100'],
            'nome' => ['sometimes', 'string', 'max:255'], 
        ]);
    
        $condomini = Condominio::query()
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
    
        return Inertia::render('buildings/BuildingsList', [
            'buildings' => CondominioResource::collection($condomini)->response()->getData(true)['data'],
            'meta' => [
                'current_page' => $condomini->currentPage(),
                'last_page'    => $condomini->lastPage(),
                'per_page'     => $condomini->perPage(),
                'total'        => $condomini->total(),
            ],
            // Optional: Return current filters to maintain UI state
            'filters' => $request->only(['nome']) 
        ]);
        
    }

    /**
     * Show the form for creating a new condominio.
     *
     * This method ensures that the user is authorized to create a new condominio and
     * renders the page with the form to create a new condominio. It uses Inertia to 
     * render the `BuildingsNew` component.
     *
     * @param \App\Models\Condominio $condominio The condominio model instance, used for authorization.
     *
     * @return \Inertia\Response A response that renders the new condominio creation form.
     */
    public function create(Condominio $condominio): Response
    {
        Gate::authorize('create', $condominio);

        return Inertia::render('buildings/BuildingsNew');
    }

    /**
     * Store a newly created condominio in the database.
     *
     * This method handles the process of storing a new condominio, including
     * validating the incoming request, handling the transaction, and providing
     * feedback to the user on success or failure. In case of failure, the exception
     * is logged for further investigation.
     *
     * @param \App\Http\Requests\CreateCondominioRequest $request The validated request data for creating a condominio.
     * 
     * @return \Illuminate\Http\RedirectResponse A redirect response to the index page, with a success or error message.
     */
    public function store(CreateCondominioRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('create', $condominio);

        try {

            $this->condominioService->createCondominioWithEsercizio($request->validated());

            return to_route('condomini.index')->with(
                $this->flashSuccess(__('condomini.success_create_building'))
            );

        } catch (\Exception $e) {

            Log::error('Error during condominio creation: ' . $e->getMessage());

            return to_route('condomini.index')->with(
                $this->flashError(__('condomini.error_create_building'))
            );
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Condominio $condominio)
    {
        //
    }

    /**
     * Show the form for editing the specified condominio.
     *
     * This method ensures that the user is authorized to update the specified condominio,
     * and then it renders the page with the form to edit the condominio details. It uses 
     * Inertia to render the `BuildingsEdit` component, passing the condominio data as a 
     * resource to the component.
     *
     * @param \App\Models\Condominio $condominio The condominio instance to be edited.
     *
     * @return \Inertia\Response A response that renders the condominio editing form with the existing data.
     */
    public function edit(Condominio $condominio): Response
    {
        Gate::authorize('update', $condominio);

        return Inertia::render('buildings/BuildingsEdit', [
            'building' => new CondominioResource($condominio),
        ]);
    }

    /**
     * Update the specified condominio in storage.
     *
     * This method is responsible for updating the condominio's data. It starts a database transaction, 
     * performs the update, and commits the transaction. If an error occurs during the update, 
     * the transaction is rolled back and the error is logged.
     *
     * @param \App\Http\Requests\UpdateCondominioRequest $request The request object containing validated input data.
     * @param \App\Models\Condominio $condominio The Condominio model instance to be updated.
     * @return \Illuminate\Http\RedirectResponse Redirects to the condominio index page with a success or error message.
     */
    public function update(UpdateCondominioRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('update', $condominio);

        try {

            DB::beginTransaction();

            $condominio->update($request->validated());

            DB::commit();

            return to_route('condomini.index')->with(
                $this->flashSuccess(__('condomini.success_edit_building'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error during condominio update: ' . $e->getMessage());

            return to_route('condomini.index')->with(
                $this->flashError(__('condomini.error_edit_building'))
            );
        }

    }

    public function options()
    {
        return CondominioOptionsResource::collection(Condominio::all());
    }

    /**
     * Remove the specified condominio from storage.
     *
     * This method is responsible for deleting the specified condominio. It checks if the user
     * has permission to delete the condominio and then performs the delete operation. If an error occurs, 
     * it logs the exception and returns an error message.
     *
     * @param \App\Models\Condominio $condominio The Condominio model instance to be deleted.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page with a success or error message.
     */
    public function destroy(Condominio $condominio): RedirectResponse
    {
        Gate::authorize('delete', Condominio::class);

        try {
           
            $condominio->delete();

            return back()->with(
                $this->flashSuccess(__('condomini.success_delete_building'))
            );
            
        } catch (\Exception $e) {

            Log::error('Error deleting condominio: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('condomini.error_delete_building'))
            );
        }
      
    }
}

<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\AnagraficaIndexRequest;
use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use App\Http\Requests\Anagrafica\UpdateAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Anagrafica\EditAnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class AnagraficaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a paginated list of anagrafiche with optional filtering.
     *
     * This method validates query parameters for pagination and filtering.
     * It retrieves a list of `Anagrafica` records with their associated `condomini`,
     * applies optional filtering by `nome`, and paginates the results.
     * The results are then passed to the Inertia.js frontend.
     *
     * Query Parameters:
     * - page (integer, optional): The page number for pagination.
     * - per_page (integer, optional): The number of results per page (between 10 and 100).
     * - nome (string, optional): Filter anagrafiche by their nome (name).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(AnagraficaIndexRequest $request): Response
    {   

        $validated = $request->validated();

        $anagrafiche = Anagrafica::with(['condomini:id,nome'])
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->withQueryString();
    
        return Inertia::render('anagrafiche/AnagraficheList', [
            'anagrafiche' => AnagraficaResource::collection($anagrafiche)->resolve(),
            'meta' => [
                'current_page' => $anagrafiche->currentPage(),
                'last_page'    => $anagrafiche->lastPage(),
                'per_page'     => $anagrafiche->perPage(),
                'total'        => $anagrafiche->total(),
            ],
            'filters' => $request->only(['nome']) 
        ]);
    }

    /**
     * Show the form for creating a new anagrafica.
     *
     * This method:
     * - Retrieves all buildings (condomini) from the database.
     * - Returns an Inertia view (`anagrafiche/AnagraficheNew`) with the building data.
     *
     * @return \Inertia\Response The rendered view with required data for the creation form.
     */
    public function create(): Response
    {
        return Inertia::render('anagrafiche/AnagraficheNew', [
            'condomini' => CondominioResource::collection(Condominio::all())
        ]);
    }

    /**
     * Store a newly created anagrafica in storage.
     *
     * This method:
     * - Validates the request using `CreateAnagraficaRequest`.
     * - Creates a new `Anagrafica` record and attaches related condomini (buildings).
     * - Wraps the operation in a database transaction to ensure atomicity.
     * - Logs errors and rolls back in case of failure.
     * - Redirects to the anagrafiche index with a success or error flash message.
     *
     * @param  \App\Http\Requests\Anagrafica\CreateAnagraficaRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Throwable
     */
    public function store(CreateAnagraficaRequest $request): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafica = Anagrafica::create($validated);
            $anagrafica->condomini()->attach($validated['condomini']);

            DB::commit();

            return to_route('admin.anagrafiche.index')->with(
                $this->flashSuccess(__('anagrafiche.success_create_anagrafica'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error creating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with(
                $this->flashError(__('anagrafiche.error_create_anagrafica'))
            );

        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Anagrafica $anagrafica)
    {
        //
    }

    /**
     * Show the form for editing the specified anagrafica.
     *
     * This method:
     * - Loads missing relationships (specifically 'condomini') for the given Anagrafica instance.
     * - Returns an Inertia response rendering the edit form with:
     *   - The current anagrafica data wrapped in `EditAnagraficaResource`.
     *   - A collection of all available condomini (buildings), transformed with `CondominioResource`.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Inertia\Response
     */
    public function edit(Anagrafica $anagrafica): Response
    {
        
       $anagrafica->loadMissing(['condomini']);

       return Inertia::render('anagrafiche/AnagraficheEdit', [
            'anagrafica'  => new EditAnagraficaResource($anagrafica),
            'condomini'   => CondominioResource::collection(Condominio::all())
       ]);
       
    }

    /**
     * Update the specified anagrafica in storage.
     *
     * This method:
     * - Validates the incoming request using `UpdateAnagraficaRequest`.
     * - Begins a database transaction to ensure atomicity.
     * - Updates the `Anagrafica` model with validated data.
     * - Syncs the associated `condomini` (buildings).
     * - Commits the transaction on success and returns a redirect with a success message.
     * - Rolls back and logs the error on failure, then redirects with an error message.
     *
     * @param  \App\Http\Requests\UpdateAnagraficaRequest  $request
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateAnagraficaRequest $request, Anagrafica $anagrafica): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafica->update($validated);
            $anagrafica->condomini()->sync($validated['condomini']); 

            DB::commit();

            return to_route('admin.anagrafiche.index')->with(
                $this->flashSuccess(__('anagrafiche.success_update_anagrafica'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with(
                $this->flashError(__('anagrafiche.error_update_anagrafica'))
            );
        }

    }

    /**
     * Remove the specified anagrafica from storage.
     *
     * This method:
     * - Checks if the specified `anagrafica` has any associated condomini.
     * - If associated condomini exist, redirects back with an error message.
     * - If no associated condomini are found, attempts to delete the `anagrafica`.
     * - On success, redirects back with a success message.
     * - On failure, logs the error and redirects with an error message.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Anagrafica $anagrafica): RedirectResponse
    {
        // Check if the anagrafica has any condomini associated with it
        if ($anagrafica->condomini()->exists()) {
            return back()->with(
                $this->flashError(__('anagrafiche.anagrafica_has_building'))
            );
        }
    
        try {

            $anagrafica->delete();

            return back()->with(
                $this->flashSuccess(__('anagrafiche.success_delete_anagrafica'))
            );

        } catch (\Exception $e) {

            Log::error('Error deleting anagrafica: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('anagrafiche.error_delete_anagrafica'))
            );

        }

    }
}

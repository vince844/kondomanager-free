<?php

namespace App\Http\Controllers\Gestionale\Gestioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Gestione\CreateGestioneRequest;
use App\Http\Requests\Gestionale\Gestione\GestioneIndexRequest;
use App\Http\Requests\Gestionale\Gestione\UpdateGestioneRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GestioneController extends Controller
{
     use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the management entities for a specific condominium and fiscal year.
     *
     * This method handles the main index page for management entities, providing filtered
     * and paginated results based on the request parameters. It supports search by name
     * and integrates with Inertia.js for Vue.js frontend rendering.
     *
     * @param \App\Http\Requests\GestioneIndexRequest $request The validated request containing filters and pagination parameters
     * @param \App\Models\Condominio $condominio The condominium entity from route binding
     * @param \App\Models\Esercizio $esercizio The fiscal year entity from route binding
     * 
     * @return \Inertia\Response Inertia.js response with all data needed for the frontend
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException If user is not authorized
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If models not found
     * 
     * @uses \App\Http\Requests\GestioneIndexRequest For request validation
     * @uses \App\Http\Resources\CondominioResource For condominium data transformation
     * @uses \App\Http\Resources\GestioneResource For management entities data transformation
     * 
     * @action GET /condominio/{condominio}/esercizio/{esercizio}/gestioni
     * @name gestioni.index
     */
    public function index(GestioneIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = $esercizio->gestioni()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        return Inertia::render('gestionale/gestioni/GestioniList', [
            'condominio' => $condominio,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'gestioni'   => GestioneResource::collection($gestioni)->resolve(),
            'meta' => [
                'current_page' => $gestioni->currentPage(),
                'last_page'    => $gestioni->lastPage(),
                'per_page'     => $gestioni->perPage(),
                'total'        => $gestioni->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new management entity for a specific condominium and fiscal year.
     *
     * This method displays the creation form for a new management entity within the context
     * of a specific condominium and fiscal year. It prepares all necessary data for the
     * frontend form and handles the condominium selection for navigation purposes.
     *
     * @param \App\Models\Condominio $condominio The condominium entity from route binding
     * @param \App\Models\Esercizio $esercizio The fiscal year entity from route binding
     * 
     * @return \Inertia\Response Inertia.js response with data for the creation form
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException If user is not authorized to create management entities
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominium or fiscal year not found
     * 
     * @uses \App\Models\Condominio For condominium data
     * @uses \App\Models\Esercizio For fiscal year data
     * 
     * @action GET /condominio/{condominio}/esercizio/{esercizio}/gestioni/create
     * @name gestioni.create
     * 
     * @middleware auth Required for accessing the creation form
     * @middleware can:create,App\Models\Gestione Authorization check for creation permission
     */
    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/gestioni/GestioniNew', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizio'  => $esercizio
        ]);
    }

    /**
     * Store a newly created management entity in storage.
     *
     * This method handles the creation of a new management entity (Gestione) within the context
     * of a specific condominium and fiscal year. It performs the creation within a database
     * transaction to ensure data consistency and automatically calculates the validity period
     * based on the intersection between the management dates and fiscal year dates.
     *
     * @param \App\Http\Requests\CreateGestioneRequest $request The validated request containing management data
     * @param \App\Models\Condominio $condominio The condominium entity from route binding
     * @param \App\Models\Esercizio $esercizio The fiscal year entity from route binding
     * 
     * @return \Illuminate\Http\RedirectResponse Redirects to the management index with flash message
     * 
     * @throws \Throwable On any error during the creation process, triggers transaction rollback
     * 
     * @uses \App\Models\Gestione For creating the management entity
     * @uses \App\Http\Requests\CreateGestioneRequest For request validation and data sanitization
     * @uses \Illuminate\Support\Facades\DB For database transaction management
     * @uses \Illuminate\Support\Facades\Log For error logging
     * 
     * @action POST /condominio/{condominio}/esercizio/{esercizio}/gestioni
     * @name gestioni.store
     * 
     * @transaction Ensures atomic creation of management entity and its association
     * @validation Uses CreateGestioneRequest for data validation
     * @logging Logs detailed error information on failure
     */
    public function store(CreateGestioneRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {
            DB::beginTransaction();
            
            $data = $request->validated();

            // Crea la gestione
            $gestione = Gestione::create($data);

            // Calcola il periodo di validità in base all’esercizio selezionato
            $pivotInizio = $gestione->data_inizio->greaterThan($esercizio->data_inizio)
                ? $gestione->data_inizio
                : $esercizio->data_inizio;

            $pivotFine = $gestione->data_fine->lessThan($esercizio->data_fine)
                ? $gestione->data_fine
                : $esercizio->data_fine;

            // Associa la gestione all’esercizio selezionato (non per forza aperto)
            $esercizio->gestioni()->attach($gestione->id, [
                'attiva'      => true,
                'data_inizio' => $pivotInizio,
                'data_fine'   => $pivotFine,
            ]);

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error creating gestione', [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'exception'     => $e,
            ]);

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_create_gestione')));
        }

        return to_route('admin.gestionale.esercizi.gestioni.index', [
            'condominio' => $condominio->id,
            'esercizio'  => $esercizio->id,
        ])->with($this->flashSuccess(__('gestionale.success_create_gestione')));
    }
    

    /**
     * Display the specified resource.
     */
    public function show(Gestione $gestione)
    {
        //
    }

    /**
     * Show the form for editing an existing management entity.
     *
     * This method displays the edit form for an existing management entity (Gestione)
     * within the context of a specific condominium and fiscal year. It prepares the
     * management entity data for the frontend form using a resource for proper data
     * transformation and serialization.
     *
     * @param \App\Models\Condominio $condominio The condominium entity from route binding
     * @param \App\Models\Esercizio $esercizio The fiscal year entity from route binding
     * @param \App\Models\Gestione $gestione The management entity to edit from route binding
     * 
     * @return \Inertia\Response Inertia.js response with data for the edit form
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException If user is not authorized to edit management entities
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If condominium, fiscal year, or management entity not found
     * 
     * @uses \App\Http\Resources\GestioneResource For transforming management entity data for frontend
     * @uses \Inertia\Inertia For rendering the Vue.js component
     * 
     * @action GET /condominio/{condominio}/esercizio/{esercizio}/gestioni/{gestione}/edit
     * @name gestioni.edit
     * 
     * @middleware auth Required for accessing the edit form
     * @middleware can:update,gestione Authorization check for update permission on the management entity
     */
    public function edit(Condominio $condominio, Esercizio $esercizio, Gestione $gestione): Response
    {
        return Inertia::render('gestionale/gestioni/GestioniEdit', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'gestione'   => new GestioneResource($gestione),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGestioneRequest $request, Condominio $condominio, Esercizio $esercizio, Gestione $gestione): RedirectResponse
    {
        try {

            DB::beginTransaction();
            
            $data = $request->validated();

            // Recupero esercizio aperto
            $esercizio = $condominio->esercizi()
                ->where('stato', 'aperto')
                ->firstOrFail();

            $gestione->update($data);

            // Calcolo intervallo di validità della gestione nell’esercizio corrente
            $pivotInizio = $gestione->data_inizio->greaterThan($esercizio->data_inizio) 
                ? $gestione->data_inizio 
                : $esercizio->data_inizio;

            $pivotFine = $gestione->data_fine->lessThan($esercizio->data_fine) 
                ? $gestione->data_fine 
                : $esercizio->data_fine;

            // Associa/aggiorna la gestione all’esercizio aperto
            $esercizio->gestioni()->syncWithoutDetaching([
                $gestione->id => [
                    'attiva'      => true,
                    'data_inizio' => $pivotInizio,
                    'data_fine'   => $pivotFine,
                ]
            ]);

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error updating gestione', [
                'condominio_id' => $condominio->id,
                'gestione_id'   => $gestione->id,
                'exception'     => $e,
            ]);

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_update_gestione')));

        }

        return to_route('admin.gestionale.esercizi.gestioni.index', [
            'condominio'  => $condominio->id,
            'esercizio'   => $esercizio->id,
        ])->with($this->flashSuccess(__('gestionale.success_update_gestione')));

    }

    /**
     * Remove the specified management entity from storage.
     *
     * This method handles the soft deletion (or permanent deletion if configured)
     * of a management entity (Gestione) within the context of a specific condominium
     * and fiscal year. It provides appropriate feedback to the user through flash messages
     * and logs any errors that occur during the deletion process.
     *
     * @param \App\Models\Condominio $condominio The condominium entity from route binding
     * @param \App\Models\Esercizio $esercizio The fiscal year entity from route binding
     * @param \App\Models\Gestione $gestione The management entity to delete from route binding
     * 
     * @return \Illuminate\Http\RedirectResponse Redirects to the management index with flash message
     * 
     * @throws \Throwable On any error during the deletion process
     * @throws \Illuminate\Auth\Access\AuthorizationException If user is not authorized to delete management entities
     * 
     * @uses \App\Models\Gestione::delete() For soft deletion of the management entity
     * @uses \Illuminate\Support\Facades\Log For error logging
     * 
     * @action DELETE /condominio/{condominio}/esercizio/{esercizio}/gestioni/{gestione}
     * @name gestioni.destroy
     * 
     * @middleware auth Required for deletion
     * @middleware can:delete,gestione Authorization check for delete permission on the management entity
     * 
     * @warning This operation may be irreversible if soft deletes are not implemented
     * @note The relationship with the fiscal year is typically handled via cascade deletion or manual cleanup
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, Gestione $gestione): RedirectResponse
    {
        try {

            $gestione->delete();

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashSuccess(__('gestionale.success_delete_gestione')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting gestione', [
                'gestione_id'    => $gestione->id,
                'condominio_id'  => $condominio->id,
                'exception'      => $e,
            ]);

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_delete_gestione')));

        }
    }
}

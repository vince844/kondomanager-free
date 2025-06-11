<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Events\Comunicazioni\NotifyUserOfCreatedComunicazione;
use App\Http\Controllers\Controller;
use App\Traits\HandleFlashMessages;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Requests\Comunicazione\CreateComunicazioneRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Services\ComunicazioneService;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class ComunicazioneController extends Controller
{
    use HandleFlashMessages;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicazioneService 
     */
    public function __construct(
        private ComunicazioneService $comunicazioneService,
    ) {}

    /**
     * Displays a list of comunicazioni based on validated filter parameters.
     *
     * This method handles the index route for comunicazioni. It validates the incoming request,
     * fetches the filtered list of comunicazioni via the service layer, and renders the Inertia view
     * with the data and pagination metadata.
     *
     * Currently, it passes `null` for `anagrafica` and `condominioIds`, meaning it retrieves all comunicazioni
     * that match the validated filter criteria (like subject or priority).
     *
     * @param  \App\Http\Requests\ComunicazioneIndexRequest $request  The validated request containing filter inputs.
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response The Inertia view with a list of comunicazioni, pagination metadata, and filters applied.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to view the comunicazione.
     */
    public function index(ComunicazioneIndexRequest $request, Comunicazione $comunicazione): Response
    {
        Gate::authorize('view', $comunicazione);

        $validated = $request->validated();

        $comunicazioni = $this->comunicazioneService->getComunicazioni(  
            anagrafica: null,
            condominioIds: null,
            validated: $validated
        );

        // Get stats using the same service
        $stats = $this->comunicazioneService->getComunicazioniStats();
    
        return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection($comunicazioni)->resolve(),
            'stats' => $stats, // Add stats to the response
            'meta' => [
                'current_page' => $comunicazioni->currentPage(),
                'last_page'    => $comunicazioni->lastPage(),
                'per_page'     => $comunicazioni->perPage(),
                'total'        => $comunicazioni->total(),
            ],
            'filters' => Arr::only($validated, ['subject', 'priority'])
        ]);
    } 

    /**
     * Show the form to create a new comunicazione.
     *
     * This method first authorizes the user to create a new comunicazione.
     * Then it renders the Inertia.js page for creating a comunicazione, providing
     * a list of all condomini and an empty anagrafiche list.
     *
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response The Inertia view for creating a new comunicazione.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create a comunicazione.
     */
    public function create(Comunicazione $comunicazione): Response
    {
        Gate::authorize('create', $comunicazione);

        return Inertia::render('comunicazioni/ComunicazioniNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => []
        ]);  
    }

    /**
     * Store a newly created comunicazione in storage.
     *
     * This method handles the authorization, validation, creation, and association
     * of a new comunicazione. It also sends notifications and handles potential
     * errors using a transaction and proper logging.
     *
     * @param  \App\Http\Requests\CreateComunicazioneRequest $request  The validated request containing comunicazione data.
     * @param  \App\Models\Comunicazione $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\RedirectResponse Redirects to the index route with a success or error message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  If the user is not authorized to create a comunicazione.
     */
    public function store(CreateComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('create', $comunicazione);

        $validated = $request->validated();

        try {

            DB::beginTransaction();
        
            $comunicazione = Comunicazione::create($validated);
            $comunicazione->condomini()->attach($validated['condomini_ids'] ?? []);
        
            if (!empty($validated['anagrafiche'])) {
                $comunicazione->anagrafiche()->attach($validated['anagrafiche'] ?? []);
            }
        
            DB::commit();

        } catch (\Exception $e) {

            DB::rollback();
            
            Log::error('Error creating comunicazione: ' . $e->getMessage());
        
            return to_route('admin.comunicazioni.index')->with(
                $this->flashError(__('comunicazioni.error_create_communication'))
            );

        }
        
        try {

            NotifyUserOfCreatedComunicazione::dispatch($validated, $comunicazione);

        } catch (\Exception $emailException) {

            Log::error('Error sending email for comunicazione ID ' . $comunicazione->id . ': ' . $emailException->getMessage());
        
            return to_route('admin.comunicazioni.index')->with(
                $this->flashWarning(__('comunicazioni.error_notify_new_communication'))
            );

        }
        
        return to_route('admin.comunicazioni.index')->with(
            $this->flashSuccess(__('comunicazioni.success_create_communication'))
        );

    }

    /**
     * Display the specified comunicazione.
     *
     * This method handles the authorization check for the current user to view the specified
     * comunicazione. It then fetches the related data (e.g., the `createdBy` relationship with its `anagrafica`)
     * and passes the information to the Inertia view for rendering.
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione to be displayed.
     * @return \Inertia\Response The Inertia response containing the view and data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the comunicazione.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the comunicazione is not found.
     */
    public function show(Comunicazione $comunicazione): Response
    {
        Gate::authorize('show', $comunicazione);

        return Inertia::render('comunicazioni/ComunicazioniView', [
            'comunicazione' => new ComunicazioneResource(
                Comunicazione::with('createdBy.anagrafica')->findOrFail($comunicazione->id)
            ),
        ]);
    }

    /**
     * Show the form for editing the specified Comunicazione.
     *
     * Authorizes the user to edit the comunicazione and loads the necessary relationships
     * such as createdBy, condomini, and anagrafiche. The method then renders the edit view
     * using Inertia with the data required for the form.
     *
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the comunicazione
     */
    public function edit(Comunicazione $comunicazione): Response
    {
        Gate::authorize('update', $comunicazione);

        $comunicazione->loadMissing(['createdBy.anagrafica', 'condomini', 'anagrafiche']);

        return Inertia::render('comunicazioni/ComunicazioniEdit', [
         'comunicazione'  => new ComunicazioneResource($comunicazione),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified Comunicazione in storage.
     *
     * Authorizes the user to update the comunicazione, performs the update, and syncs associated condomini and anagrafiche.
     * If an error occurs during the update, a log entry is created, and the user is notified.
     *
     * @param  \App\Http\Requests\CreateComunicazioneRequest $request
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the comunicazione
     * @throws \Exception If the update fails
     */
    public function update(CreateComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('update', $comunicazione);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $comunicazione->update($validated);

            $comunicazione->condomini()->sync($validated['condomini_ids'] ?? []);

            if (!empty($validated['anagrafiche'])) {

                $comunicazione->anagrafiche()->sync($validated['anagrafiche'] ?? []);
        
            }

            DB::commit();

            return to_route('admin.comunicazioni.index')->with(
                $this->flashSuccess(__('comunicazioni.success_update_communication'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating comunicazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with(
                $this->flashError(__('comunicazioni.error_update_communication'))
            );

        }
    }

    /**
     * Remove the specified Comunicazione from storage.
     *
     * Authorizes the user to delete the comunicazione and performs the deletion. If an error occurs during deletion,
     * a log entry is created, and the user is notified.
     *
     * @param  \App\Models\Comunicazione $comunicazione
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the comunicazione
     * @throws \Exception If deletion fails
     */
    public function destroy(Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('delete', $comunicazione);

        try {

            $comunicazione->delete();

            return back()->with(
                $this->flashSuccess(__('comunicazioni.success_delete_communication'))
            );
            
        } catch (\Exception $e) {
            
            Log::error('Error deleting comunicazione: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('comunicazioni.error_delete_communication'))
            );
        }

    }

}

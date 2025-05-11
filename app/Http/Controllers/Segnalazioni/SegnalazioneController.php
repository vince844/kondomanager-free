<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Events\Segnalazioni\NotifyUserOfCreatedSegnalazione;
use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\CreateSegnalazioneRequest;
use App\Http\Requests\Segnalazione\SegnalazioneIndexRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Services\SegnalazioneService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Illuminate\Support\Arr;

class SegnalazioneController extends Controller
{
    use HandleFlashMessages;

    /**
     * SegnalazioneController constructor.
     *
     * @param SegnalazioneService $segnalazioneService
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
    ) {}

    /**
     * Display a listing of segnalazioni.
     *
     * This method handles the retrieval and display of segnalazioni based on the validated filter parameters from the request.
     * It first authorizes the user for the given segnalazione and then retrieves the list of segnalazioni using the `segnalazioneService`.
     * The results are returned to the frontend via an Inertia.js response with pagination data and filters.
     *
     * @param  \App\Http\Requests\SegnalazioneIndexRequest  $request  The request object containing the filter parameters.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model instance used for authorization.
     * @return \Inertia\Response Returns the rendered Inertia.js response with segnalazioni data, pagination, and filters.
     */
    public function index(SegnalazioneIndexRequest $request, Segnalazione $segnalazione): Response
    {
        Gate::authorize('view', $segnalazione);

        $validated = $request->validated();

        $segnalazioni = $this->segnalazioneService->getSegnalazioni(  
            anagrafica: null,
            condominioIds: null,
            validated: $validated
        );
    
        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => SegnalazioneResource::collection($segnalazioni)->resolve(),
            'meta' => [
                'current_page' => $segnalazioni->currentPage(),
                'last_page' => $segnalazioni->lastPage(),
                'per_page' => $segnalazioni->perPage(),
                'total' => $segnalazioni->total(),
            ],
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato'])
        ]);
    }
  
    /**
     * Show the form for creating a new segnalazione.
     *
     * This method authorizes the user to create a new segnalazione and then returns the Inertia.js response 
     * to render the creation form. The form includes a list of all condomini and anagrafiche, fetched from 
     * the respective resources.
     *
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione model used for authorization.
     * @return \Inertia\Response Returns the rendered Inertia.js response with the list of condomini and anagrafiche.
     */
    public function create(Segnalazione $segnalazione): Response
    {
        Gate::authorize('create', $segnalazione);

        return Inertia::render('segnalazioni/SegnalazioniNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Store a newly created segnalazione in the database.
     *
     * This method handles the process of creating a new segnalazione, including validating the incoming request,
     * attaching any related anagrafiche, and committing the transaction. It also sends notifications to users 
     * upon successful creation.
     * If an error occurs during the creation process, it will roll back the transaction and log the error.
     *
     * @param  \App\Http\Requests\CreateSegnalazioneRequest  $request  The request object containing validated data for the segnalazione.
     * @param  \App\Models\Segnalazione  $segnalazione  The segnalazione model used for authorization.
     * @return \Illuminate\Http\RedirectResponse A redirect response to the segnalazioni index with a success or error message.
     * 
     * @throws \Exception If an error occurs during the creation process, an exception is thrown, and the transaction is rolled back.
     */
    public function store(CreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('create', $segnalazione);
        
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create($validated);

            if (!empty($validated['anagrafiche'])) {
                $segnalazione->anagrafiche()->attach($validated['anagrafiche']);
            }

            DB::commit();

            NotifyUserOfCreatedSegnalazione::dispatch($segnalazione);

            return to_route('admin.segnalazioni.index')->with(
                $this->flashSuccess(__('segnalazioni.success_create_ticket'))
            );

        } catch (\Exception $e) {
        
            DB::rollback();

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_create_ticket'))
            );

        }

    }

    /**
     * Display the specified segnalazione.
     *
     * @param Segnalazione $segnalazione
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Segnalazione $segnalazione): Response
    {
        Gate::authorize('view', $segnalazione);

        return Inertia::render('segnalazioni/SegnalazioniView', [
            'segnalazione' => new SegnalazioneResource(
                Segnalazione::with('createdBy.anagrafica',  'assignedTo', 'condominio', 'anagrafiche')->findOrFail($segnalazione->id)
            ),
        ]);
    }

    /**
     * Show the form for editing the specified segnalazione.
     *
     * @param Segnalazione $segnalazione
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function edit(Segnalazione $segnalazione): Response
    {
        Gate::authorize('update', $segnalazione);
        
        $segnalazione->loadMissing(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniEdit', [
            'segnalazione'  => new SegnalazioneResource($segnalazione),
            'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
            'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified segnalazione in storage.
     *
     * @param CreateSegnalazioneRequest $request
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(CreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione->update($validated);
            
            $segnalazione->anagrafiche()->sync($validated['anagrafiche']);

            DB::commit();

            return to_route('admin.segnalazioni.index')->with(
                $this->flashSuccess(__('segnalazioni.success_update_ticket'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with(
                $this->flashError(__('segnalazioni.error_update_ticket'))
            );
            
        }
    }

    /**
     * Toggle the 'locked' status of a segnalazione (resolve/unresolve).
     *
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function toggleResolve(Segnalazione $segnalazione): RedirectResponse
    {

        Gate::authorize('update', $segnalazione);

        try {

            $segnalazione->is_locked = !$segnalazione->is_locked;
            $segnalazione->save();

            return back()->with([
                'message' => [
                    'type' => 'success',
                    'message' => "Lo stato della segnalazione è stato aggiornato con successo."
                ]
            ]);

        } catch (\Exception $e) {

            Log::error('Error toggling resolve status segnalazione: ' . $e->getMessage());
            return back()->with([
                'message' => [
                    'type' => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento dello stato della segnalazione."
                ]
            ]);

        }
        
    }

    /**
     * Remove the specified segnalazione from storage.
     *
     * @param Segnalazione $segnalazione
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('delete', $segnalazione);

        try {

            $segnalazione->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "La segnalazione è stata eliminata con successo"
                ]
            ]);

        } catch (\Exception $e) {
            
            Log::error('Error deleting segnalazione: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare la segnalazione"
                ]
            ]);
        }
       
    }
}

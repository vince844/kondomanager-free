<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Requests\Comunicazione\CreateComunicazioneRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Services\ComunicazioneNotificationService;
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
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicazioneService 
     * @param  \App\Services\ComunicazioneNotificationService 
     */
    public function __construct(
        private ComunicazioneService $comunicazioneService,
        private ComunicazioneNotificationService $notificationService
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
     * @param  \App\Http\Requests\ComunicazioneIndexRequest  $request  The validated request containing filter inputs.
     * @param  \App\Models\Comunicazione  $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response  The Inertia view with a list of comunicazioni, pagination metadata, and filters applied.
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
    
        return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection($comunicazioni)->resolve(),
            'meta' => [
                'current_page' => $comunicazioni->currentPage(),
                'last_page' => $comunicazioni->lastPage(),
                'per_page' => $comunicazioni->perPage(),
                'total' => $comunicazioni->total(),
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
     * @param  \App\Models\Comunicazione  $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\Response  The Inertia view for creating a new comunicazione.
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
     * @param  \App\Http\Requests\CreateComunicazioneRequest  $request  The validated request containing comunicazione data.
     * @param  \App\Models\Comunicazione  $comunicazione  A model instance used for authorization purposes.
     * @return \Illuminate\Http\RedirectResponse  Redirects to the index route with a success or error message.
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

            $comunicazione->condomini()->attach($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {
                $comunicazione->anagrafiche()->attach($validated['anagrafiche']);
            }

            DB::commit();

            $this->notificationService->sendUserNotifications(
                validated: $validated,
                comunicazione: $comunicazione
            );

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova comunicazione è stata creata con successo!"
                ]
            ]);

        } catch (\Exception $e) {
        
            DB::rollback();

            Log::error('Error creating comunicazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione della comunicazione!"
                ]
            ]);

        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Comunicazione $comunicazione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Comunicazione $comunicazione): Response
    {
        Gate::authorize('update', $comunicazione);

        $comunicazione->load(['createdBy', 'condomini', 'anagrafiche']);

        return Inertia::render('comunicazioni/ComunicazioniEdit', [
         'comunicazione'  => new ComunicazioneResource($comunicazione),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified comunicazione with validated input data.
     *
     * @param CreateComunicazioneRequest $request
     * @param Comunicazione $comunicazione
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(CreateComunicazioneRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('update', $comunicazione);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $comunicazione->update($validated);

            $comunicazione->condomini()->sync($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {

                $comunicazione->anagrafiche()->sync($validated['anagrafiche']);
        
            }

            DB::commit();

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La comunicazone è stata aggiornata con successo!"
                ]
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating comunicazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della comunicazione!"
                ]
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('delete', $comunicazione);

        try {

            $comunicazione->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "La comunicazione è stata eliminata con successo"
                ]
            ]);

        } catch (\Exception $e) {
            
            Log::error('Error deleting comunicazione: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare la comunicazione"
                ]
            ]);
        }

    }

}

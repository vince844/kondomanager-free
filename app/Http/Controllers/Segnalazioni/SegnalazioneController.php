<?php

namespace App\Http\Controllers\Segnalazioni;

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
use App\Services\SegnalazioneNotificationService;
use App\Services\SegnalazioneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Illuminate\Support\Arr;

class SegnalazioneController extends Controller
{
    /**
     * SegnalazioneController constructor.
     *
     * @param SegnalazioneService $segnalazioneService
     * @param SegnalazioneNotificationService $notificationService
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
        private SegnalazioneNotificationService $notificationService
    ) {}

    /**
     * Display a listing of segnalazioni with optional filters.
     *
     * @param SegnalazioneIndexRequest $request
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(SegnalazioneIndexRequest $request): Response
    {
        Gate::authorize('view', Segnalazione::class);

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
     * @return Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create(): Response
    {
        Gate::authorize('create', Segnalazione::class);

        return Inertia::render('segnalazioni/SegnalazioniNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

     /**
     * Store a newly created segnalazione in storage.
     *
     * @param CreateSegnalazioneRequest $request
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(CreateSegnalazioneRequest $request): RedirectResponse
    {
        Gate::authorize('create', Segnalazione::class);
        
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create($validated);

            if (!empty($validated['anagrafiche'])) {
                $segnalazione->anagrafiche()->attach($validated['anagrafiche']);
            }

            DB::commit();

            $this->notificationService->sendUserNotifications(
                validated: $validated,
                segnalazione: $segnalazione
            );

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova segnalazione guasto è stata creata con successo!"
                ]
            ]);

        } catch (\Exception $e) {
        
            DB::rollback();

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione della segnalazione guasto!"
                ]
            ]);

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
        Gate::authorize('view', Segnalazione::class);

        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniView', [
         'segnalazione'  => new SegnalazioneResource($segnalazione)
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
        
        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

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

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La segnalazione è stata aggiornata con successo!"
                ]
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della segnalazione!"
                ]
            ]);
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

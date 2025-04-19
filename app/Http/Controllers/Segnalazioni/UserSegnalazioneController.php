<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\SegnalazioneIndexRequest;
use App\Http\Requests\Segnalazione\UserCreateSegnalazioneRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Segnalazione;
use App\Services\SegnalazioneNotificationService;
use App\Services\SegnalazioneService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Response;

class UserSegnalazioneController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\SegnalazioneService 
     * @param  \App\Services\SegnalazioneNotificationService 
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
        private SegnalazioneNotificationService $notificationService
    ) {}
    
    /**
     * Display a paginated list of segnalazioni for authorized users.
     *
     * Applies optional filters from the validated request (e.g., subject, priority, stato),
     * retrieves the paginated list using the SegnalazioneService, and returns it to the
     * Inertia-powered frontend along with pagination metadata and applied filters.
     *
     * @param  \App\Http\Requests\SegnalazioneIndexRequest  $request
     * @return \Inertia\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(SegnalazioneIndexRequest $request): Response
    {
        Gate::authorize('view', Segnalazione::class);  

        $validated = $request->validated();
    
        try {

            $user = Auth::user();

            if (!$user?->anagrafica) {
                abort(403, __('auth.not_authenticated'));
            }

            // Get user anagrafica
            $anagrafica = $user->anagrafica;
            // Fetch the related condominio IDs
            $condominioIds = $anagrafica->condomini->pluck('id');
            // Get filtered segnalazioni from the service
            $segnalazioni = $this->segnalazioneService->getSegnalazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: $validated
            );

        } catch (\Exception $e) {

            Log::error('Error getting user segnalazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('create', Segnalazione::class);

        $user = Auth::user();

        if (!$user || !$user->anagrafica) {
            abort(403, __('auth.not_authenticated'));
        }

        $anagrafica = $user->anagrafica;
        // Fetch the anagrafica related condomini
        $condomini = $anagrafica->condomini()->get();

        return Inertia::render('segnalazioni/UserSegnalazioniNew',[
            'condomini' => CondominioOptionsResource::collection($condomini)
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserCreateSegnalazioneRequest $request): RedirectResponse
    {
  
        Gate::authorize('create', Segnalazione::class);

        $validated = $request->validated();

        try {

            $segnalazione = Segnalazione::create($validated);

            $this->notificationService->notify($segnalazione);

            $messageText = $segnalazione->is_approved
            ? "La nuova segnalazione guasto è stata pubblicata con successo!"
            : "La segnalazione è stata inviata e sarà pubblicata dopo l'approvazione di un amministratore.";

            return to_route('user.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => $messageText
                ]
            ]);

        } catch (\Exception $e) {

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('user.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione della segnalazione guasto!"
                ]
            ]);

        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Segnalazione $segnalazione)
    {
        Gate::authorize('show', $segnalazione);

        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniView', [
         'segnalazione'  => new SegnalazioneResource($segnalazione)
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Segnalazione $segnalazione)
    {
        Gate::authorize('update', $segnalazione);
        
        $user = Auth::user();

        if (!$user || !$user->anagrafica) {
            abort(403, __('auth.not_authenticated'));
        }

        $anagrafica = $user->anagrafica;
        // Fetch the anagrafica related condomini
        $condomini = $anagrafica->condomini()->get();

        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/UserSegnalazioniEdit', [
         'segnalazione'  => new SegnalazioneResource($segnalazione),
         'condomini'     => CondominioOptionsResource::collection($condomini)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserCreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validated(); 

        try {

            $segnalazione->update($validated);

            return to_route('user.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La segnalazione è stata aggiornata con successo!"
                ]
            ]);

        } catch (\Exception $e) {

            Log::error('Error updating segnalazione: ' . $e->getMessage());

            return to_route('user.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della segnalazione!"
                ]
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Segnalazione $segnalazione)
    {
        abort(403, 'Non sei autorizzato a cancellare una segnalazione.');
    }
}

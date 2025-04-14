<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\UserCreateSegnalazioneRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Segnalazione;
use App\Services\SegnalazioneNotificationService;
use App\Services\SegnalazioneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;

class UserSegnalazioneController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\SegnalazioneService  $segnalazioneService
     * @param  \App\Services\SegnalazioneNotificationService  $notificationService
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
        private SegnalazioneNotificationService $notificationService,
    ) {}
    
    /**
     * Display the user's dashboard.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        Gate::authorize('view', Segnalazione::class);

        try {

            $user = Auth::user();

            if (!$user || !$user->anagrafica) {
                abort(403, __('auth.not_authenticated'));
            }

            $anagrafica = $user->anagrafica;
            // Fetch the related condominio IDs
            $condominioIds = $anagrafica->condomini->pluck('id');
            // Get filtered segnalazioni from the service
            $segnalazioni = $this->segnalazioneService->getSegnalazioni($anagrafica, $condominioIds);
            // Condomini options for dropdown or filtering
            $condomini = $anagrafica->condomini()->orderBy('nome')->get();
        
        } catch (\Exception $e) {

            Log::error('Error getting user segnalazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => SegnalazioneResource::collection($segnalazioni),
            'condominioOptions' => CondominioOptionsResource::collection($condomini)
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
    public function show(Segnalazione $segnalazioni)
    {
        Gate::authorize('show', $segnalazioni);

        $segnalazioni->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniView', [
         'segnalazione'  => new SegnalazioneResource($segnalazioni)
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Segnalazione $segnalazione)
    {
        //
    }
}

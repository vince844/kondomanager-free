<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Models\Comunicazione;
use App\Services\ComunicazioneService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class UserComunicazioneController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ComunicaizoneService 
     */
    public function __construct(
        private ComunicazioneService $comunicazioneService
    ) {}

    /**
     * Display a listing of the user's comunicazioni.
     *
     * This method handles the process of fetching the authenticated user's comunicazioni based on their `anagrafica` and related `condomini`. 
     * It includes authorization checks and filters the results based on the validated request data.
     * If an error occurs while retrieving the data, it logs the error and aborts with a 500 status.
     *
     * @param \App\Http\Requests\ComunicazioneIndexRequest $request The validated request data.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model used for authorization.
     * @return \Inertia\Response The Inertia response containing the view and paginated comunicazioni data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the comunicazioni.
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user does not have a valid anagrafica.
     * @throws \Exception If an unexpected error occurs while fetching comunicazioni.
     */
    public function index(ComunicazioneIndexRequest $request, Comunicazione $comunicazione): Response
    {
        Gate::authorize('view', $comunicazione);

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
            $comunicazioni = $this->comunicazioneService->getComunicazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: $validated
            );

        } catch (\Exception $e) {

            Log::error('Error getting user comunicazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('comunicazioni/UserComunicazioniList', [
            'comunicazioni' => [
                'data' => ComunicazioneResource::collection($comunicazioni)->resolve(),
                'current_page' => $comunicazioni->currentPage(),
                'last_page' => $comunicazioni->lastPage(),
                'per_page' => $comunicazioni->perPage(),
                'total' => $comunicazioni->total(),
            ],
            'search' => $validated['search'] ?? '',
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato'])
        ]);
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
                Comunicazione::with('createdBy')->findOrFail($comunicazione->id)
            ),
        ]);
    }

}

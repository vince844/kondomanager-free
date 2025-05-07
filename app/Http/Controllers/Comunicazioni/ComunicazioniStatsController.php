<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Models\Comunicazione;
use App\Services\ComunicazioneService;
use Illuminate\Support\Facades\Auth;

class ComunicazioniStatsController extends Controller
{
    protected ComunicazioneService $comunicazioneService;

    /**
     * Controller constructor.
     *
     * Initializes the controller with the ComunicazioneService dependency, which is used
     * to build scoped queries for retrieving communication counts based on user context.
     *
     * @param \App\Services\ComunicazioneService $comunicazioneService The service handling communication logic
     */
    public function __construct(ComunicazioneService $comunicazioneService)
    {
        $this->comunicazioneService = $comunicazioneService;
    }

    /**
     * Handles the incoming request to retrieve communication counts based on user role.
     *
     * If the authenticated user is an administrator or collaborator, or has the permission
     * to access the admin panel, it returns the total communication counts system-wide.
     * Otherwise, it returns counts scoped to the user's associated condomini.
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing priority counts (bassa, media, alta, urgente)
     */
    public function __invoke()
    {
        $user = Auth::user();

        if ($user->hasRole(['amministratore', 'collaboratore']) || $user->hasPermissionTo('Accesso pannello amministratore')) {
            $counts = $this->adminCounts();
        } else {
            $counts = $this->userCounts();
        }

        return response()->json($counts);
    }

    /**
     * Retrieves a count of all communications grouped by priority level
     * (bassa, media, alta, urgente) for administrators.
     *
     * This method queries the Comunicazione model directly without user scoping
     * and returns the total number of communications for each priority level.
     *
     * @return \Illuminate\Support\Collection|object|null An object with priority counts (e.g., ->bassa, ->media, etc.)
     */
    private function adminCounts()
    {
        return Comunicazione::selectRaw("
            SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
            SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
            SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
            SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
        ")->first();
    }

    /**
     * Retrieves a count of communications grouped by priority level
     * (bassa, media, alta, urgente) for the authenticated user.
     *
     * This method fetches the authenticated user's related "anagrafica" and associated
     * condomini IDs, then queries the communications using a service to get a base query
     * scoped to the user's permissions. It returns the sum of communications per priority.
     *
     * @return \Illuminate\Support\Collection|object|null An object with priority counts (e.g., ->bassa, ->media, etc.)
     */
    private function userCounts()
    {
        $user = Auth::user();
        $anagrafica = $user->anagrafica;
        $condominioIds = $anagrafica->condomini->pluck('id');

        $query = $this->comunicazioneService->getUserScopedBaseQuery($anagrafica, $condominioIds);

        return $query->selectRaw("
            SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
            SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
            SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
            SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
        ")->first();
    }
}

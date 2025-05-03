<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\SegnalazioneService;
use App\Services\ComunicazioneService;
use Illuminate\Support\Facades\App;
use Inertia\Response;

class UserDashboardController extends Controller
{
    
    /**
     * Inject the SegnalazioneService.
     *
     * @param  \App\Services\SegnalazioneService  $segnalazioneService
     */
    public function __construct(
        private SegnalazioneService $segnalazioneService,
        private ComunicazioneService $comunicazioneService
    ) {}
    

    /**
     * Display the authenticated user's dashboard with a limited set of segnalazioni and comunicazioni.
     *
     * Retrieves the user's associated anagrafica and condomini, fetches the related
     * segnalazioni and comunicazioni using the SegnalazioneService and ComunicazioneService, and limits the results to 3 items
     * for display purposes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authenticated or lacks anagrafica.
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If an error occurs while fetching data.
     */
    public function __invoke(Request $request): Response
    {

        try {

            $user = Auth::user();

            if (!$user || !$user->anagrafica) {
                abort(403, __('auth.not_authenticated'));
            }

            $anagrafica = $user->anagrafica;
            // Fetch the related condominio IDs
            $condominioIds = $anagrafica->condomini->pluck('id');
            
           // Fetch the segnalazioni using the SegnalazioneService
            $segnalazioni = $this->segnalazioneService->getSegnalazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: []
            );

           // Fetch the comunicazioni using the ComunicazioneService
            $comunicazioni = $this->comunicazioneService->getComunicazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: []
            );

            /** @var \Illuminate\Pagination\LengthAwarePaginator $segnalazioni */
            $segnalazioniLimited = $segnalazioni->take(3);
            /** @var \Illuminate\Pagination\LengthAwarePaginator $comunicazioni */
            $comunicazioniLimited = $comunicazioni->take(3);
        
        } catch (\Exception $e) {

            Log::error('Error getting user segnalazioni or comunicazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('dashboard/UserDashboard', [
            'segnalazioni'  => SegnalazioneResource::collection($segnalazioniLimited),
            'comunicazioni' => ComunicazioneResource::collection($comunicazioniLimited),
        ]);
        
    }
}

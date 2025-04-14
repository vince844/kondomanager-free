<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\SegnalazioneService;

class UserDashboardController extends Controller
{
    protected $segnalazioneService;

    public function __construct(SegnalazioneService $segnalazioneService)
    {
        $this->segnalazioneService = $segnalazioneService;
    }
    
    /**
     * Display the user's dashboard.
     *
     * @return \Inertia\Response
     */

    public function __invoke(Request $request)
    {

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
            // Apply limit only for dashboard view
            $segnalazioniLimited = $segnalazioni->take(3); 
        
        } catch (\Exception $e) {

            Log::error('Error getting user segnalazioni: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');

        }

        return Inertia::render('dashboard/UserDashboard', [
            'segnalazioni' => SegnalazioneResource::collection($segnalazioniLimited),
        ]);
        
    }
}

<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Services\SegnalazioneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class UserSegnalazioneController extends Controller
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
    public function index()
    {
        try {

            $user = Auth::user();

            if (!$user || !$user->anagrafica) {
                abort(403, 'User is not properly authenticated or lacks anagrafica.');
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
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Segnalazione $segnalazione)
    {
        //
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

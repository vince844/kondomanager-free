<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\ComunicazioneIndexRequest;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Models\Comunicazione;
use App\Services\ComunicazioneService;
use Illuminate\Http\Request;
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
     * Display a listing of the resource.
     */
    public function index(ComunicazioneIndexRequest $request): Response
    {

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
            'search' => $validated['search'] ?? '', // ✅ Add this
            'filters' => Arr::only($validated, ['subject', 'priority', 'stato'])
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
    public function show(Comunicazione $comunicazione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Comunicazione $comunicazione)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Comunicazione $comunicazione)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Comunicazione $comunicazione)
    {
        //
    }
}

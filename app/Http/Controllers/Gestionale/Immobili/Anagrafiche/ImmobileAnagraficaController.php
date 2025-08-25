<?php

namespace App\Http\Controllers\Gestionale\Immobili\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\CreateImmobileAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class ImmobileAnagraficaController extends Controller
{
    use HandleFlashMessages;
    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio, Immobile $immobile): Response
    {

        // Eager load anagrafiche (and any other relationships you want)
        $immobile->loadMissing(['anagrafiche']);

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheList', [
            'condominio' => $condominio,
            'immobile'   => new ImmobileResource($immobile)
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio, Immobile $immobile): Response
    {

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheNew', [
            'condominio'  => $condominio,
            'immobile'    => $immobile,
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateImmobileAnagraficaRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        $data = $request->validated();

        try {
            // Attach the anagrafica to the immobile with pivot data
            $immobile->anagrafiche()->attach($data['anagrafica_id'], [
                'tipologia'       => $data['tipologia'],
                'quota'           => $data['quota'],
                'tipologie_spese' => $data['tipologie_spese'] ?? null,
                'data_inizio'     => $data['data_inizio'],
                'data_fine'       => $data['data_fine'] ?? null,
                'attivo'          => true,
                'note'            => $data['note'] ?? null,
            ]);

           return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_associate_anagrafica')));


        } catch (\Throwable $e) {

            Log::error('Error attaching anagrafica to immobile', [
                'immobile_id' => $immobile->id,
                'anagrafica_id' => $data['anagrafica_id'],
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_associate_anagrafica')));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

<?php

namespace App\Http\Controllers\Gestionale\Immobili\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\CreateImmobileAnagraficaRequest;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\UpdateImmobileAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing the association of "Anagrafica" records
 * with an "Immobile" in the Gestionale module.
 *
 * Responsibilities:
 * - List all anagrafiche linked to an immobile
 * - Create new associations
 * - Update pivot data (tipologia, quota, dates, note)
 * - Prevent duplicates
 * - Enforce business rules (e.g., quotas per tipologia)
 * - Remove associations
 *
 * @package App\Http\Controllers\Gestionale\Immobili\Anagrafiche
 */
class ImmobileAnagraficaController extends Controller
{
    use HandleFlashMessages;
    
    /**
     * Display a listing of the resource.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return Response
     */
    public function index(Condominio $condominio, Immobile $immobile): Response
    {
        $esercizioAperto = Esercizio::where('stato', 'aperto')->first();
    
        $immobile->loadMissing(['anagrafiche.saldi' => function($query) use ($immobile, $esercizioAperto) {
            $query->where('immobile_id', $immobile->id)
                ->where('esercizio_id', $esercizioAperto->id)
                ->with('esercizio');
        }]);

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheList', [
            'condominio' => $condominio,
            'immobile'   => new ImmobileResource($immobile)
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return Response
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
     * Store a newly created association in storage.
     *
     * @param CreateImmobileAnagraficaRequest $request
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return RedirectResponse
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

             // Recupera l’esercizio aperto
            $esercizio = $condominio->esercizi()->where('stato', 'aperto')->first();

            if ($esercizio) {
                Saldo::updateOrCreate(
                    [
                        'esercizio_id'  => $esercizio->id,
                        'condominio_id' => $condominio->id,
                        'anagrafica_id' => $data['anagrafica_id'],
                        'immobile_id'   => $immobile->id,
                    ],
                    [
                        'saldo_iniziale' => $data['saldo_iniziale'] ?? 0,
                        'saldo_finale'   => 0,
                    ]
                );
            }

           return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_attach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error attaching anagrafica to immobile', [
                'immobile_id'   => $immobile->id,
                'anagrafica_id' => $data['anagrafica_id'],
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_attach_anagrafica')));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // Not implemented. Could return a detailed view.
    }

    /**
     * Show the form for editing the specified association.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica
     * @return Response
     */
    public function edit(Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): Response
    {
        $anagrafica = $immobile->anagrafiche()->where('anagrafica_id', $anagrafica->id)->first();

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheEdit', [
            'condominio'  => $condominio,
            'immobile'    => new ImmobileResource($immobile),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
            'anagrafica'  => $anagrafica,
        ]);
    }

    /**
     * Update the specified association in storage.
     *
     * @param UpdateImmobileAnagraficaRequest $request
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica
     * @return RedirectResponse
     */
    public function update(UpdateImmobileAnagraficaRequest $request, Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): RedirectResponse
    {
        $data = $request->validated();

        try {
            if ((int) $anagrafica->id !== (int) $data['anagrafica_id']) {

                // Different anagrafica selected → detach old and attach new
                $immobile->anagrafiche()->detach($anagrafica->id);

                $immobile->anagrafiche()->attach($data['anagrafica_id'], [
                    'tipologia'       => $data['tipologia'],
                    'quota'           => $data['quota'],
                    'tipologie_spese' => $data['tipologie_spese'] ?? null,
                    'data_inizio'     => $data['data_inizio'],
                    'data_fine'       => $data['data_fine'] ?? null,
                    'note'            => $data['note'] ?? null,
                ]);

            } else {
                // Same anagrafica → just update pivot fields
                $immobile->anagrafiche()->updateExistingPivot($anagrafica->id, [
                    'tipologia'       => $data['tipologia'],
                    'quota'           => $data['quota'],
                    'tipologie_spese' => $data['tipologie_spese'] ?? null,
                    'data_inizio'     => $data['data_inizio'],
                    'data_fine'       => $data['data_fine'] ?? null,
                    'note'            => $data['note'] ?? null,
                ]);
            }

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_update_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error updating anagrafica for immobile', [
                'immobile_id'       => $immobile->id,
                'old_anagrafica_id' => $anagrafica->id,
                'new_anagrafica_id' => $data['anagrafica_id'] ?? null,
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);  

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_update_anagrafica')));
        }
    }

    /**
     * Remove the specified association from storage.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica
     * @return RedirectResponse
     */
    public function destroy(Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): RedirectResponse
    {
        
        try {
            // Detach the anagrafica from the immobile
            $immobile->anagrafiche()->detach($anagrafica->id);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_detach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error detaching anagrafica from immobile', [
                'immobile_id'   => $immobile->id,
                'anagrafica_id' => $anagrafica->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_detach_anagrafica')));
        }
    }   

}

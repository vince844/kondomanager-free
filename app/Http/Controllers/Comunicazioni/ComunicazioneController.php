<?php

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\CreateComunicazioneRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;


class ComunicazioneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
   /*      return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection(
                Comunicazione::with(['createdBy', 'condomini', 'anagrafiche'])
                    ->orderBy('created_at', 'desc')
                    ->get()
            ),
            'condominioOptions' => CondominioOptionsResource::collection(Condominio::all())
        ]);  */
        return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection(
                Comunicazione::with(['createdBy', 'condomini', 'anagrafiche'])
                    ->orderBy('created_at', 'desc')
                    ->get()
            )
        ]); 
    } 

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        
        return Inertia::render('comunicazioni/ComunicazioniNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => []
        ]);  
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateComunicazioneRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {

            DB::beginTransaction();

            $comunicazione = Comunicazione::create($validated);

            $comunicazione->condomini()->attach($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {

                $comunicazione->anagrafiche()->attach($validated['anagrafiche']);
        
            }

            DB::commit();

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova comunicazione è stata creata con successo!"
                ]
            ]);

        } catch (\Exception $e) {
        
            DB::rollback();

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione della comunicazione!"
                ]
            ]);

        }

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
        $comunicazione->load(['createdBy', 'condomini', 'anagrafiche']);

        return Inertia::render('comunicazioni/ComunicazioniEdit', [
         'comunicazione'  => new ComunicazioneResource($comunicazione),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CreateComunicazioneRequest $request, Comunicazione $comunicazione)
    {
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $comunicazione->update($validated);

            $comunicazione->condomini()->sync($validated['condomini_ids']);

            if (!empty($validated['anagrafiche'])) {

                $comunicazione->anagrafiche()->sync($validated['anagrafiche']);
        
            }

            DB::commit();

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La comunicazone è stata aggiornata con successo!"
                ]
            ]);

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating comunicazione: ' . $e->getMessage());

            return to_route('admin.comunicazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della comunicazione!"
                ]
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Comunicazione $comunicazione)
    {
        
        try {

            $comunicazione->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "La comunicazione è stata eliminata con successo"
                ]
            ]);

        } catch (\Exception $e) {
            
            Log::error('Error deleting comunicazione: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare la comunicazione"
                ]
            ]);
        }

    }
}

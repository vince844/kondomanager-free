<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\CreateSegnalazioneRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class SegnalazioneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => SegnalazioneResource::collection(Segnalazione::with(['createdBy', 'assignedTo', 'condominio'])->get())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('segnalazioni/SegnalazioniNew',[
            'condomini'   => CondominioResource::collection(Condominio::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateSegnalazioneRequest $request): RedirectResponse
    {
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create($validated);

            if (!empty($validated['anagrafiche'])) {
                $segnalazione->anagrafiche()->attach($validated['anagrafiche']);
            }

            DB::commit();

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova segnalazione guasto è stata creata con successo!"
                ]
            ]);
        
        } catch (Exception $e) {
        
            DB::rollback();

            Log::error('Error creating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with([
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
    public function show(Segnalazione $segnalazione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Segnalazione $segnalazioni)
    {
        $segnalazioni->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniEdit', [
         'segnalazione'  => new SegnalazioneResource($segnalazioni),
         'condomini'     => CondominioResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CreateSegnalazioneRequest $request, Segnalazione $segnalazioni)
    {
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazioni->update($validated);
            
            $segnalazioni->anagrafiche()->sync($validated['anagrafiche']);

            DB::commit();

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La segnalazione è stata aggiornata con successo!"
                ]
            ]);

        } catch (Exception $e) {

            DB::rollback();

            Log::error('Error updating segnalazione: ' . $e->getMessage());

            return to_route('admin.segnalazioni.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento della segnalazione!"
                ]
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Segnalazione $segnalazioni)
    {
        try {

            $segnalazioni->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "La segnalazione è stata eliminata con successo"
                ]
            ]);

        } catch (Exception $e) {
            
            Log::error('Error deleting segnalazione: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare la segnalazione"
                ]
            ]);
        }

       
    }
}

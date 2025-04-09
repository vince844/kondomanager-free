<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use App\Http\Requests\Anagrafica\UpdateAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Anagrafica\EditAnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class AnagraficaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {   

        return Inertia::render('anagrafiche/AnagraficheList', [
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('anagrafiche/AnagraficheNew', [
            'buildings'   => CondominioResource::collection(Condominio::all())
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateAnagraficaRequest $request): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafica = Anagrafica::create($validated);
            $anagrafica->condomini()->attach($validated['buildings']);

            DB::commit();

            return to_route('admin.anagrafiche.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "La nuova anagrafica è stata creata con successo!"
                ]
            ]);

        } catch (Exception $e) {

            DB::rollback();

            Log::error('Error creating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione dell'anagrafica!"
                ]
            ]);

        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Anagrafica $anagrafica)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Anagrafica $anagrafiche)
    {
        
       $anagrafiche->load(['condomini']);

       return Inertia::render('anagrafiche/AnagraficheEdit', [
        'anagrafica'  => new EditAnagraficaResource($anagrafiche),
        'condomini'   => CondominioResource::collection(Condominio::all())
       ]);
       
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAnagraficaRequest $request, Anagrafica $anagrafiche): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafiche->update($validated);
            
           /*  $condominiIds = collect($validated['condomini'])->pluck('id'); */
            $anagrafiche->condomini()->sync($validated['condomini']);

            DB::commit();

            return to_route('admin.anagrafiche.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "L'anagrafica è stata aggiornata con successo!"
                ]
            ]);

        } catch (Exception $e) {

            DB::rollback();

            Log::error('Error updating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento dell'anagrafica!"
                ]
            ]);
        }

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Anagrafica $anagrafiche)
    {
  
        try {
            $anagrafiche->delete();
        } catch (Exception $e) {
            Log::error('Error deleting anagrafica: ' . $e->getMessage());
        }

        return back()->with(['message' => [ 'type'    => 'success',
                                            'message' => "L'anagrafica è stata eliminata con successo"]]);
    }
}

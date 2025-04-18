<?php

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Segnalazione\CreateSegnalazioneRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Notifications\NewSegnalazioneNotification;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Gate;

class SegnalazioneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', Segnalazione::class);

        return Inertia::render('segnalazioni/SegnalazioniList', [
            'segnalazioni' => SegnalazioneResource::collection(
                Segnalazione::with(['createdBy', 'assignedTo', 'condominio'])
                    ->orderBy('created_at', 'desc')
                    ->get()
            ),
            'condominioOptions' => CondominioOptionsResource::collection(Condominio::all())
        ]); 
    }
  
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('create', Segnalazione::class);

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
        Gate::authorize('create', Segnalazione::class);
        
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione = Segnalazione::create($validated);

            if (!empty($validated['anagrafiche'])) {

                $segnalazione->anagrafiche()->attach($validated['anagrafiche']);
                $recipients = Anagrafica::whereIn('id', $validated['anagrafiche'])->get();

            }else{
            
                $recipients = Anagrafica::whereHas('condomini', function ($query) use ($validated) {
                    $query->where('condominio_id', $validated['condominio_id']);
                })->get();
              
            }

            DB::commit();

            Notification::send($recipients, new NewSegnalazioneNotification($segnalazione));

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
        Gate::authorize('view', Segnalazione::class);

        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniView', [
         'segnalazione'  => new SegnalazioneResource($segnalazione)
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Segnalazione $segnalazione)
    {
        $segnalazione->load(['createdBy', 'assignedTo', 'condominio', 'anagrafiche']);

        return Inertia::render('segnalazioni/SegnalazioniEdit', [
         'segnalazione'  => new SegnalazioneResource($segnalazione),
         'condomini'     => CondominioOptionsResource::collection(Condominio::all()),
         'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CreateSegnalazioneRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $segnalazione->update($validated);
            
            $segnalazione->anagrafiche()->sync($validated['anagrafiche']);

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
     * Lock and unlock the segnalazione.
     */
    public function toggleResolve(Segnalazione $segnalazione){

        Gate::authorize('update', $segnalazione);

        $segnalazione->is_locked = !$segnalazione->is_locked;
        $segnalazione->save();

        return redirect()->back();
        
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('delete', Segnalazione::class);

        try {

            $segnalazione->delete();

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

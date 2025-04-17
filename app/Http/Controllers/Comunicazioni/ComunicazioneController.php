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
use Illuminate\Http\Request;

class ComunicazioneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:10,100'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'priority.*' => ['string', 'in:bassa,media,alta,urgente'],
            // aggiungi altri campi filtro se necessario (es: 'email')
        ]);
    
        $comunicazioni = Comunicazione::with(['createdBy', 'condomini', 'anagrafiche'])
            ->when($validated['subject'] ?? false, function ($query, $subject) {
                $query->where('subject', 'like', "%{$subject}%");
            })
            ->when($validated['priority'] ?? false, fn($query, $priorities) =>
                $query->whereIn('priority', $priorities)
            )
            // altri filtri in questo formato:
            // ->when($validated['email'] ?? false, fn($q, $email) => $q->where('email', 'like', "%{$email}%"))
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString(); // mantiene i parametri GET nella paginazione
    
        return Inertia::render('comunicazioni/ComunicazioniList', [
            'comunicazioni' => ComunicazioneResource::collection($comunicazioni)->response()->getData(true)['data'],
            'meta' => [
                'current_page' => $comunicazioni->currentPage(),
                'last_page' => $comunicazioni->lastPage(),
                'per_page' => $comunicazioni->perPage(),
                'total' => $comunicazioni->total(),
            ],
            'filters' => $request->only(['subject', 'priority']) // utile per preservare stato UI
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

    public function stats(Request $request)
    {

        $counts = Comunicazione::selectRaw("
                SUM(CASE WHEN priority = 'bassa' THEN 1 ELSE 0 END) as bassa,
                SUM(CASE WHEN priority = 'media' THEN 1 ELSE 0 END) as media,
                SUM(CASE WHEN priority = 'alta' THEN 1 ELSE 0 END) as alta,
                SUM(CASE WHEN priority = 'urgente' THEN 1 ELSE 0 END) as urgente
            ")->first();

        return response()->json($counts);
    }
}

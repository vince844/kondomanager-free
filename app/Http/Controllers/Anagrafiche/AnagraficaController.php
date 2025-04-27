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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class AnagraficaController extends Controller
{
    /**
     * Display a paginated list of anagrafiche with optional filtering.
     *
     * This method validates query parameters for pagination and filtering.
     * It retrieves a list of `Anagrafica` records with their associated `condomini`,
     * applies optional filtering by `nome`, and paginates the results.
     * The results are then passed to the Inertia.js frontend.
     *
     * Query Parameters:
     * - page (integer, optional): The page number for pagination.
     * - per_page (integer, optional): The number of results per page (between 10 and 100).
     * - nome (string, optional): Filter anagrafiche by their nome (name).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request): Response
    {   

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:10,100'],
            'nome' => ['sometimes', 'string', 'max:255'], 
        ]);

        $anagrafiche = Anagrafica::with(['condomini:id,nome'])
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();
    
        return Inertia::render('anagrafiche/AnagraficheList', [
            'anagrafiche' => AnagraficaResource::collection($anagrafiche)->resolve(),
            'meta' => [
                'current_page' => $anagrafiche->currentPage(),
                'last_page' => $anagrafiche->lastPage(),
                'per_page' => $anagrafiche->perPage(),
                'total' => $anagrafiche->total(),
            ],
            'filters' => $request->only(['nome']) 
        ]);
    }

    /**
     * Show the form for creating a new Anagrafica.
     *
     * @return \Inertia\Response
     */
    public function create(): Response
    {
        return Inertia::render('anagrafiche/AnagraficheNew', [
            'buildings'   => CondominioResource::collection(Condominio::all())
        ]);
    }

    /**
     * Store a newly created Anagrafica in storage.
     *
     * @param  \App\Http\Requests\CreateAnagraficaRequest  $request
     * @return \Illuminate\Http\RedirectResponse
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
     * Show the form for editing the specified Anagrafica.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Inertia\Response
     */
    public function edit(Anagrafica $anagrafiche): Response
    {
        
       $anagrafiche->loadMissing(['condomini']);

       return Inertia::render('anagrafiche/AnagraficheEdit', [
        'anagrafica'  => new EditAnagraficaResource($anagrafiche),
        'condomini'   => CondominioResource::collection(Condominio::all())
       ]);
       
    }

    /**
     * Update the specified Anagrafica in storage.
     *
     * @param  \App\Http\Requests\UpdateAnagraficaRequest  $request
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateAnagraficaRequest $request, Anagrafica $anagrafiche): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafiche->update($validated);
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
     * Remove the specified Anagrafica from storage.
     *
     * If the anagrafica is associated with any condomini, the deletion is prevented.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Anagrafica $anagrafiche): RedirectResponse
    {
        // Check if the anagrafica has any condomini associated with it
        if ($anagrafiche->condomini()->exists()) {
            return back()->with(['message' => [ 'type'    => 'error',
                                                'message' => "L'anagrafica non può essere eliminata perché è associata a dei condomini"]]);
        }
    
        try {

            $anagrafiche->delete();

            return back()->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "L'anagrafica è stata eliminata con successo"
                ]
            ]);

        } catch (Exception $e) {

            Log::error('Error deleting anagrafica: ' . $e->getMessage());

            return back()->with([
                'message' => [ 
                    'type'    => 'error',
                    'message' => "Si è verificato un errore nel tentativo di eliminare l'anagrafica"
                ]
            ]);

        }

    }
}

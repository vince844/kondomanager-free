<?php

namespace App\Http\Controllers\Gestionale\Gestioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Gestione\CreateGestioneRequest;
use App\Http\Requests\Gestionale\Gestione\GestioneIndexRequest;
use App\Http\Requests\Gestionale\Gestione\UpdateGestioneRequest;
use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class GestioneController extends Controller
{
     use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the resource.
     */
    public function index(GestioneIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Recupero esercizio aperto
        $esercizio = $condominio->esercizi()
            ->where('stato', 'aperto')
            ->firstOrFail();

        $gestioni = $esercizio->gestioni()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
        
        $condomini = $this->getCondomini();
            
        return Inertia::render('gestionale/gestioni/GestioniList', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'gestioni'   => GestioneResource::collection($gestioni)->resolve(),
            'meta'       => [
                'current_page' => $gestioni->currentPage(),
                'last_page'    => $gestioni->lastPage(),
                'per_page'     => $gestioni->perPage(),
                'total'        => $gestioni->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/gestioni/GestioniNew', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateGestioneRequest $request, Condominio $condominio): RedirectResponse
    {
        try {

            DB::beginTransaction();
            
            $data = $request->validated();

            // Recupero esercizio aperto
            $esercizio = $condominio->esercizi()
                ->where('stato', 'aperto')
                ->firstOrFail();

            // Crea la gestione (durata complessiva)
            $gestione = Gestione::create($data);

            // Calcolo intervallo di validità della gestione nell’esercizio corrente
            $pivotInizio = $gestione->data_inizio->greaterThan($esercizio->data_inizio) 
                ? $gestione->data_inizio 
                : $esercizio->data_inizio;

            $pivotFine = $gestione->data_fine->lessThan($esercizio->data_fine) 
                ? $gestione->data_fine 
                : $esercizio->data_fine;

            // Associa la gestione all’esercizio aperto
            $esercizio->gestioni()->attach($gestione->id, [
                'attiva'      => true,
                'data_inizio' => $pivotInizio,
                'data_fine'   => $pivotFine,
            ]);

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error creating gestione', [
                'condominio_id' => $condominio->id,
                'exception'     => $e,
            ]);

            return to_route('admin.gestionale.gestioni.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_gestione')));
        }

        return to_route('admin.gestionale.gestioni.index', $condominio)
            ->with($this->flashSuccess(__('gestionale.success_create_gestione')));
        
    }

    /**
     * Display the specified resource.
     */
    public function show(Gestione $gestione)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condominio, Gestione $gestione): Response
    {
        return Inertia::render('gestionale/gestioni/GestioniEdit', [
            'condominio' => $condominio,
            'gestione'   => new GestioneResource($gestione),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGestioneRequest $request, Condominio $condominio, Gestione $gestione): RedirectResponse
    {
        try {

            DB::beginTransaction();
            
            $data = $request->validated();

            // Recupero esercizio aperto
            $esercizio = $condominio->esercizi()
                ->where('stato', 'aperto')
                ->firstOrFail();

            $gestione->update($data);

            // Calcolo intervallo di validità della gestione nell’esercizio corrente
            $pivotInizio = $gestione->data_inizio->greaterThan($esercizio->data_inizio) 
                ? $gestione->data_inizio 
                : $esercizio->data_inizio;

            $pivotFine = $gestione->data_fine->lessThan($esercizio->data_fine) 
                ? $gestione->data_fine 
                : $esercizio->data_fine;

            // Associa/aggiorna la gestione all’esercizio aperto
            $esercizio->gestioni()->syncWithoutDetaching([
                $gestione->id => [
                    'attiva'      => true,
                    'data_inizio' => $pivotInizio,
                    'data_fine'   => $pivotFine,
                ]
            ]);

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error updating gestione', [
                'condominio_id' => $condominio->id,
                'gestione_id'   => $gestione->id,
                'exception'     => $e,
            ]);

            return to_route('admin.gestionale.gestioni.index', $condominio)
                ->with($this->flashError(__('gestionale.error_update_gestione')));
        }

        return to_route('admin.gestionale.gestioni.index', $condominio)
            ->with($this->flashSuccess(__('gestionale.success_update_gestione')));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Gestione $gestione): RedirectResponse
    {
        try {

            $gestione->delete();

            return to_route('admin.gestionale.gestioni.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_gestione')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting gestione', [
                'gestione_id'    => $gestione->id,
                'condominio_id'  => $condominio->id,
                'exception'      => $e,
            ]);

            return to_route('admin.gestionale.gestioni.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_gestione')));

        }
    }
}

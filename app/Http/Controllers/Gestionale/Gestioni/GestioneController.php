<?php

namespace App\Http\Controllers\Gestionale\Gestioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Gestione\CreateGestioneRequest;
use App\Http\Requests\Gestionale\Gestione\GestioneIndexRequest;
use App\Http\Requests\Gestionale\Gestione\UpdateGestioneRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GestioneController extends Controller
{
     use HandleFlashMessages, HasCondomini;

    /**
     * Display a listing of the resource.
     */
    public function index(GestioneIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = $esercizio->gestioni()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        return Inertia::render('gestionale/gestioni/GestioniList', [
            'condominio' => $condominio,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
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
    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/gestioni/GestioniNew', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizio'  => $esercizio
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateGestioneRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
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

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_create_gestione')));

        }

        return to_route('admin.gestionale.esercizi.gestioni.index', [
            'condominio' => $condominio->id,
            'esercizio'   => $esercizio->id,
        ])->with($this->flashSuccess(__('gestionale.success_create_gestione')));

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
    public function edit(Condominio $condominio, Esercizio $esercizio, Gestione $gestione): Response
    {
        return Inertia::render('gestionale/gestioni/GestioniEdit', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'gestione'   => new GestioneResource($gestione),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGestioneRequest $request, Condominio $condominio, Esercizio $esercizio, Gestione $gestione): RedirectResponse
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

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_update_gestione')));

        }

        return to_route('admin.gestionale.esercizi.gestioni.index', [
            'condominio'  => $condominio->id,
            'esercizio'   => $esercizio->id,
        ])->with($this->flashSuccess(__('gestionale.success_update_gestione')));

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, Gestione $gestione): RedirectResponse
    {
        try {

            $gestione->delete();

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashSuccess(__('gestionale.success_delete_gestione')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting gestione', [
                'gestione_id'    => $gestione->id,
                'condominio_id'  => $condominio->id,
                'exception'      => $e,
            ]);

            return to_route('admin.gestionale.esercizi.gestioni.index', [
                'condominio'  => $condominio->id,
                'esercizio'   => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_delete_gestione')));

        }
    }
}

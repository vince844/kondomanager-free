<?php

namespace App\Http\Controllers\Gestionale\Tabelle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Tabella\CreateTabellaRequest;
use App\Http\Requests\Gestionale\Tabella\TabellaIndexRequest;
use App\Http\Requests\Gestionale\Tabella\UpdateTabellaRequest;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use App\Http\Resources\Gestionale\Tabelle\TabellaResource;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class TabellaController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index(TabellaIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        $tabelle = $condominio
            ->tabelle()
            ->with(['palazzina', 'scala'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate(config('pagination.default_per_page'));
            

        return Inertia::render('gestionale/tabelle/TabelleList', [
            'condominio' => $condominio,
            'tabelle'    => TabellaResource::collection($tabelle)->resolve(),
            'meta'       => [
                'current_page' => $tabelle->currentPage(),
                'last_page'    => $tabelle->lastPage(),
                'per_page'     => $tabelle->perPage(),
                'total'        => $tabelle->total(),
            ],
            'filters' => $request->only(['nome']), 
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        $condominio->load(['palazzine', 'scale']);

        return Inertia::render('gestionale/tabelle/TabelleNew', [
            'condominio' => $condominio,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateTabellaRequest $request, Condominio $condominio): RedirectResponse
    {
        $data = $request->validated();

        // Creazione della tabella
        $tabella = $condominio->tabelle()->create([
            'nome'            => $data['nome'],
            'tipo'            => $data['tipologia'],
            'quota'           => $data['quota'],
            'numero_decimali' => $data['numero_decimali'] ?? 2,
            'palazzina_id'    => $data['palazzina_id'] ?? null,
            'scala_id'        => $data['scala_id'] ?? null,
            'descrizione'     => $data['descrizione'] ?? null,
            'note'            => $data['note'] ?? null,
            'attiva'          => true,
            'data_inizio'     => now(),
            'created_by'      => $data['created_by'],
        ]);

        // Se l’opzione "associa tutti" è selezionata
        if (!empty($data['all_flats'])) {

            $immobili = $condominio->immobili()->get(['id']);

            foreach ($immobili as $immobile) {
                $tabella->quote()->create([
                    'immobile_id'  => $immobile->id,
                    'valore'       => null,
                    'coefficienti' => null,
                    'created_by'   => null,
                ]);
            }
        }

        return to_route('admin.gestionale.tabelle.quote.index', [
            'condominio' => $condominio->id,
            'tabella'    => $tabella->id,
        ])->with('success', __('gestionale.success_create_tabella'));

    }

    /**
     * Display the specified resource.
     */
    public function show(Tabella $tabella)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condominio, Tabella $tabella): Response
    {
        $tabella->loadMissing(['palazzina', 'scala']);

        return Inertia::render('gestionale/tabelle/TabelleEdit', [
            'condominio' => $condominio,
            'tabella'    => new TabellaResource($tabella),
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTabellaRequest $request, Condominio $condominio, Tabella $tabella): RedirectResponse
    {
        try {

            $data = $request->validated();
           
            $tabella->update($data);

            return to_route('admin.gestionale.tabelle.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_update_tabella'))
            );

        } catch (\Throwable $e) {

            Log::error('Error updating tabella', [
                'tabella_id'    => $tabella->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.tabelle.index', $condominio)->with(
                $this->flashError(__('gestionale.error_update_tabella'))
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Tabella $tabella): RedirectResponse
    {
         try {

            $tabella->delete();

            return to_route('admin.gestionale.tabelle.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_tabella')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting tabella', [
                'tabella_id'    => $tabella->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.tabelle.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_tabella')));
                
        }
    }
}

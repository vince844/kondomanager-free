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
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TabellaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

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
        
        // Get a list of all the registered condomini this is important to populate dropdown condomini in the dropdown breadcummb
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/tabelle/TabelleList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
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

        // Get a list of all the registered condomini this is important to populate dropdown condomini in the dropdown breadcummb
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/tabelle/TabelleNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'palazzine'  => PalazzinaResource::collection($condominio->palazzine),
            'scale'      => ScalaResource::collection($condominio->scale),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateTabellaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {

            $data = $request->validated();

            DB::beginTransaction();

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

            DB::commit();

            return to_route('admin.gestionale.tabelle.quote.index', [
                'condominio' => $condominio->id,
                'tabella'    => $tabella->id,
            ])->with(
                $this->flashSuccess(__('gestionale.success_create_tabella'))
            );

        } catch (\Exception $e) {
                
            DB::rollBack();

            Log::error('Error creating tabella: ' . $e->getMessage(), [
                'condominio_id' => $condominio->id,
                'data' => $data,
                'exception' => $e
            ]);

            return to_route('admin.gestionale.tabelle.quote.index', [
                'condominio' => $condominio->id,
                'tabella'    => $tabella->id,
            ])->with(
                $this->flashError(__('gestionale.error_create_tabella'))
            );
        }

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

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/tabelle/TabelleEdit', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
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

            return to_route('admin.gestionale.tabelle.index', $condominio)->with(
                    $this->flashSuccess(__('gestionale.success_delete_tabella'))
                );
                
        } catch (\Throwable $e) {

            Log::error('Error deleting tabella', [
                'tabella_id'    => $tabella->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.tabelle.index', $condominio)->with(
                    $this->flashError(__('gestionale.error_delete_tabella'))
                );
                
        }
    }
}

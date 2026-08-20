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
use App\Traits\OrdinaElenco;
use App\Traits\PaginaElenco;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TabellaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, OrdinaElenco, PaginaElenco;

    /**
     * Display a listing of the resource.
     */
    public function index(TabellaIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        //
        // ⚠️ **Qui `per_page` era validato e poi buttato via.** La richiesta lo accettava, il
        // controller paginava con il valore di configurazione: chi sceglieva 40 righe vedeva il
        // selettore cambiare e l'elenco restare a dieci. È la stessa famiglia dei difetti segnalati
        // sul forum, nella variante più muta — il valore non si perdeva per strada, non veniva
        // proprio letto.
        $validated['per_page'] = $this->righePerPagina($request);

        $tabelle = $condominio
            ->tabelle()
            ->with(['palazzina', 'scala'])
            ->withCount(['quote', 'conti'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, TabellaIndexRequest::colonneOrdinabili(), predefinita: 'nome'))
            ->paginate($validated['per_page']);
        
        // Get a list of all the registered condomini this is important to populate dropdown condomini in the dropdown breadcummb
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/tabelle/TabelleList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'tabelle'    => TabellaResource::collection($tabelle)->resolve(),
            'sort'       => $validated['sort'] ?? null,
            'direction'  => $validated['direction'] ?? null,
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
                        // `valore` nullo significa **«da compilare»**, e dalla beta.61 è uno stato
                        // dichiarato invece che una scappatoia: la pagina delle quote lo sa dire, e
                        // la generazione del piano rate si ferma a chiederlo. Prima di allora
                        // questa riga produceva una tabella **non più salvabile** finché ogni
                        // casella non era piena.
                        'valore'       => null,
                        'coefficienti' => null,
                        // ⚠️ Era `null`, con l'autore disponibile due righe sopra in
                        // `$data['created_by']`: le quote create qui non avevano firma, e
                        // risultavano di nessuno.
                        'created_by'   => $data['created_by'],
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

            return back()->with(
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
        // ⚠️ La tabella dell'indirizzo deve essere di **questo** condominio: il binding implicito
        // risolve i due modelli per id, ciascuno per conto suo. Senza questa riga, cancellare la
        // tabella di un altro condominio riusciva — e portava via in cascata tutte le sue quote
        // (`ON DELETE CASCADE` su `quote_tabella.tabella_id`) — chiudendo con un **messaggio verde
        // di successo** sulla schermata del condominio sbagliato. Vedi la coda ㊷ in
        // `docs/roadmap.md` e la guardia gemella in `TabellaQuotaController`.
        abort_unless($tabella->condominio_id === $condominio->id, 404);

         try {

            if ($tabella->conti()->exists()) {
                return back()->with(
                    $this->flashError("Impossibile eliminare: la tabella è associata ad una o più voci di spesa.")
                );
            }

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

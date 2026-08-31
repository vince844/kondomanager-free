<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Traits\OrdinaElenco;

use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\AnagraficaIndexRequest;
use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use App\Http\Requests\Anagrafica\UpdateAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Anagrafica\EditAnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class AnagraficaController extends Controller
{
    use OrdinaElenco;
    use HandleFlashMessages;
    use PaginaElenco;

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
     * - per_page (integer, optional): The number of results per page; normalized by PaginaElenco
     *   against config('pagination.consentite'), never rejected.
     * - nome (string, optional): Filter anagrafiche by their nome (name).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(AnagraficaIndexRequest $request): Response
    {   
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $anagrafiche = Anagrafica::with(['condomini:id,nome,indirizzo'])
            // Filtro per Nome (Esistente)
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            // NUOVO: Filtro per Condomini (Array di ID)
            ->when(request('condominio_id'), function ($query, $condominiIds) {
                // Assicuriamoci che sia un array
                $condominiIds = (array) $condominiIds;
                
                $query->whereHas('condomini', function ($q) use ($condominiIds) {
                    $q->whereIn('condomini.id', $condominiIds);
                });
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, AnagraficaIndexRequest::colonneOrdinabili(), predefinita: 'nome', versoPredefinito: 'asc'))
            ->paginate($validated['per_page'])
            ->withQueryString();
    
        return Inertia::render('anagrafiche/AnagraficheList', [
            'anagrafiche' => AnagraficaResource::collection($anagrafiche)->resolve(),
            'meta' => [
                'current_page' => $anagrafiche->currentPage(),
                'last_page'    => $anagrafiche->lastPage(),
                'per_page'     => $anagrafiche->perPage(),
                'total'        => $anagrafiche->total(),
            ],
            // Aggiungiamo condominio_id ai filtri passati al frontend
            'filters' => request()->only(['nome', 'condominio_id']), 
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new anagrafica.
     *
     * This method:
     * - Retrieves all buildings (condomini) from the database.
     * - Returns an Inertia view (`anagrafiche/AnagraficheNew`) with the building data.
     *
     * @return \Inertia\Response The rendered view with required data for the creation form.
     */
    public function create(): Response
    {
        return Inertia::render('anagrafiche/AnagraficheNew', [
            'condomini' => CondominioResource::collection(Condominio::all())
        ]);
    }

    /**
     * Store a newly created anagrafica in storage.
     *
     * This method:
     * - Validates the request using `CreateAnagraficaRequest`.
     * - Creates a new `Anagrafica` record and attaches related condomini (buildings).
     * - Wraps the operation in a database transaction to ensure atomicity.
     * - Logs errors and rolls back in case of failure.
     * - Redirects to the anagrafiche index with a success or error flash message.
     *
     * @param  \App\Http\Requests\Anagrafica\CreateAnagraficaRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Throwable
     */
    public function store(CreateAnagraficaRequest $request): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafica = Anagrafica::create($validated);
            $anagrafica->condomini()->attach($validated['condomini']);

            DB::commit();

            return to_route('admin.anagrafiche.index')->with(
                $this->flashSuccess(__('anagrafiche.success_create_anagrafica'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error creating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with(
                $this->flashError(__('anagrafiche.error_create_anagrafica'))
            );

        }

    }

    /**
     * Display the specified resource.
     */
    /**
     * La scheda dell'anagrafica.
     *
     * ## ⚠️ Questo metodo aveva il corpo vuoto, e la pagina era bianca
     *
     * Fino alla 1.11.0-beta.9 qui c'era `//`. `Route::resource` registra comunque la rotta, quindi
     * `admin/anagrafiche/{id}` rispondeva **200 con niente dentro**: pagina bianca, nessun errore,
     * nessuna riga di log. E non era una rotta che nessuno raggiungeva —
     * `resources/js/components/anagrafiche/columns.ts` ci punta col **nome della persona**
     * nell'elenco della rubrica: bastava cliccare un nome.
     *
     * È la stessa famiglia del difetto arrivato dal forum nella beta.62 — `categorie.show`
     * registrata verso un metodo inesistente, che rispondeva 500 — con una differenza che la rende
     * peggiore: **un 500 lo si segnala, una pagina bianca fa pensare che sia colpa della propria
     * connessione.** Trovato il 31/08/2026 seguendo un collegamento nuovo verso una destinazione
     * vecchia, che è il momento in cui i difetti dormienti si svegliano.
     *
     * ## Cosa mostra
     *
     * Le stesse quattro famiglie di dati della scheda del fornitore, che è il modello dichiarato:
     * recapiti, documento d'identità, i condomìni in cui la persona compare e le unità che occupa.
     * I documenti stanno nella scheda accanto (`AnagraficaDocumentoController`), perché sono un
     * elenco paginato e non un riquadro.
     */
    public function show(Anagrafica $anagrafica): Response
    {
        $anagrafica->loadMissing([
            'condomini',
            'user',
            // Le unità con il **ruolo** e la **quota**, che stanno sulla pivot: senza, la scheda
            // direbbe «occupa tre unità» senza dire a che titolo, che è l'unica cosa che conta.
            'immobili' => fn ($q) => $q->with('condominio:id,nome'),
        ]);

        return Inertia::render('anagrafiche/AnagraficheView', [
            'anagrafica' => new AnagraficaResource($anagrafica),

            // ⚠️ Le unità non passano da `AnagraficaResource`: quella è condivisa da mezzo
            // programma e non carica la pivot. Si adattano qui, dove si sa cosa serve a questa
            // schermata, invece di allargare una risorsa che altri usano per altro.
            'immobili' => $anagrafica->immobili->map(fn ($immobile) => [
                'id'          => $immobile->id,
                // `etichettaEstesa` e non `etichetta`: qui lo spazio c'è, e «Int. 3 (Attico)»
                // dice più di «Attico». La regola è scritta sul modello.
                'etichetta'   => $immobile->etichetta_estesa,
                'condominio'  => $immobile->condominio?->nome,
                'tipologia'   => $immobile->pivot->tipologia,
                'quota'       => $immobile->pivot->quota,
                'attivo'      => (bool) $immobile->pivot->attivo,
                'data_inizio' => $immobile->pivot->data_inizio,
                'data_fine'   => $immobile->pivot->data_fine,
            ])->values(),

            // Il conteggio dei documenti serve alla scheda accanto: senza, la linguetta
            // «Documenti» non sa dire se c'è qualcosa dentro prima che uno ci entri.
            'documenti_count' => $anagrafica->documenti()->count(),
        ]);
    }

    /**
     * Show the form for editing the specified anagrafica.
     *
     * This method:
     * - Loads missing relationships (specifically 'condomini') for the given Anagrafica instance.
     * - Returns an Inertia response rendering the edit form with:
     *   - The current anagrafica data wrapped in `EditAnagraficaResource`.
     *   - A collection of all available condomini (buildings), transformed with `CondominioResource`.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Inertia\Response
     */
    public function edit(Anagrafica $anagrafica): Response
    {
        
       $anagrafica->loadMissing(['condomini']);

       return Inertia::render('anagrafiche/AnagraficheEdit', [
            'anagrafica'  => new EditAnagraficaResource($anagrafica),
            'condomini'   => CondominioResource::collection(Condominio::all())
       ]);
       
    }

    /**
     * Update the specified anagrafica in storage.
     *
     * This method:
     * - Validates the incoming request using `UpdateAnagraficaRequest`.
     * - Begins a database transaction to ensure atomicity.
     * - Updates the `Anagrafica` model with validated data.
     * - Syncs the associated `condomini` (buildings).
     * - Commits the transaction on success and returns a redirect with a success message.
     * - Rolls back and logs the error on failure, then redirects with an error message.
     *
     * @param  \App\Http\Requests\UpdateAnagraficaRequest  $request
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateAnagraficaRequest $request, Anagrafica $anagrafica): RedirectResponse
    {

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $anagrafica->update($validated);
            $anagrafica->condomini()->sync($validated['condomini']); 

            DB::commit();

            return to_route('admin.anagrafiche.index')->with(
                $this->flashSuccess(__('anagrafiche.success_update_anagrafica'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating anagrafica: ' . $e->getMessage());

            return to_route('admin.anagrafiche.index')->with(
                $this->flashError(__('anagrafiche.error_update_anagrafica'))
            );
        }

    }

    /**
     * Remove the specified anagrafica from storage.
     *
     * This method:
     * - Checks if the specified `anagrafica` has any associated condomini.
     * - If associated condomini exist, redirects back with an error message.
     * - If no associated condomini are found, attempts to delete the `anagrafica`.
     * - On success, redirects back with a success message.
     * - On failure, logs the error and redirects with an error message.
     *
     * @param  \App\Models\Anagrafica  $anagrafiche
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Anagrafica $anagrafica): RedirectResponse
    {
        // Check if the anagrafica has any condomini associated with it
        if ($anagrafica->condomini()->exists()) {
            return back()->with(
                $this->flashError(__('anagrafiche.anagrafica_has_building'))
            );
        }
    
        try {

            $anagrafica->delete();

            return back()->with(
                $this->flashSuccess(__('anagrafiche.success_delete_anagrafica'))
            );

        } catch (\Exception $e) {

            Log::error('Error deleting anagrafica: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('anagrafiche.error_delete_anagrafica'))
            );

        }

    }
}

<?php

namespace App\Http\Controllers\Condomini;

use App\Traits\OrdinaElenco;

use App\Http\Controllers\Controller;
use App\Http\Requests\Condominio\CreateCondominioRequest;
use App\Http\Requests\Condominio\UpdateCondominioRequest;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Actions\Condominio\CreaCondominioDimostrativoAction;
use App\Services\CondominioService;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

class CondominioController extends Controller
{
    use OrdinaElenco;

    /** ⚠️ Fuori «Anagrafiche», che contiene un elenco di soggetti. */
    public static function colonneOrdinabili(): array
    {
        return [
            'nome'      => 'nome',
            'indirizzo' => 'indirizzo',
        ];
    }

    use HandleFlashMessages;
    use PaginaElenco;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\CondominioService
     */
    public function __construct(
        private CondominioService $condominioService,
    ) {}

    /**
     * Display a list of condomini with optional filtering and pagination.
     *
     * This method retrieves a list of condominios from the database, applying filters
     * (e.g., by name) if provided in the request, and returns a paginated list of results.
     * The method ensures that the user has permission to view the condominio list.
     *
     * @param \Illuminate\Http\Request $request The incoming HTTP request containing filter parameters.
     * @param \App\Models\Condominio $condominio The condominio model instance, used for authorization.
     *
     * @return \Inertia\Response A response that renders the building list view with the paginated data and filters.
     */
    public function index(Request $request, Condominio $condominio): Response
    {
        Gate::authorize('view', $condominio);

        // ⚠️ `sort` e `direction` vanno **validati**, non solo letti. Questo elenco non ha una
        // FormRequest: finché le due chiavi non comparivano qui, `$request->validate()` non le
        // restituiva, `$validated['sort']` non esisteva e l'ordinamento non veniva mai applicato —
        // le frecce nelle intestazioni erano cliccabili e non facevano niente, senza dare errore.
        // Il nome della colonna finisce dentro `orderBy()`, quindi la lista è anche il confine.
        $validated = $request->validate(array_merge([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer'],
            'nome' => ['sometimes', 'string', 'max:255'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili()))));

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $condomini = Condominio::query()
            ->with('anagrafiche') 
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, self::colonneOrdinabili(), predefinita: 'nome', versoPredefinito: 'asc'))
            ->paginate($validated['per_page']);
    
        return Inertia::render('buildings/BuildingsList', [
            'buildings' => CondominioResource::collection($condomini)->response()->getData(true)['data'],
            'meta' => [
                'current_page' => $condomini->currentPage(),
                'last_page'    => $condomini->lastPage(),
                'per_page'     => $condomini->perPage(),
                'total'        => $condomini->total(),
            ],
            'filters' => $request->only(['nome']),
            // Il condominio dimostrativo, se c'è: la barra degli strumenti offre di crearlo oppure,
            // quando esiste già, di aprirlo o rimuoverlo.
            'condominioDemo' => ($demo = app(CreaCondominioDimostrativoAction::class)->esisteGia())
                ? ['id' => $demo->id, 'nome' => $demo->nome]
                : null, 
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new condominio.
     *
     * This method ensures that the user is authorized to create a new condominio and
     * renders the page with the form to create a new condominio. It uses Inertia to 
     * render the `BuildingsNew` component.
     *
     * @param \App\Models\Condominio $condominio The condominio model instance, used for authorization.
     *
     * @return \Inertia\Response A response that renders the new condominio creation form.
     */
    public function create(Condominio $condominio): Response
    {
        Gate::authorize('create', $condominio);

        return Inertia::render('buildings/BuildingsNew');
    }

    /**
     * Store a newly created condominio in the database.
     *
     * This method handles the process of storing a new condominio, including
     * validating the incoming request, handling the transaction, and providing
     * feedback to the user on success or failure. In case of failure, the exception
     * is logged for further investigation.
     *
     * @param \App\Http\Requests\CreateCondominioRequest $request The validated request data for creating a condominio.
     * 
     * @return \Illuminate\Http\RedirectResponse A redirect response to the index page, with a success or error message.
     */
    public function store(CreateCondominioRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('create', $condominio);

        try {

            $this->condominioService->createCondominioWithEsercizio($request->validated());

            return to_route('condomini.index')->with(
                $this->flashSuccess(__('condomini.success_create_building'))
            );

        } catch (\Exception $e) {

            Log::error('Error during condominio creation: ' . $e->getMessage());

            return to_route('condomini.index')->with(
                $this->flashError(__('condomini.error_create_building'))
            );
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Condominio $condominio)
    {
        //
    }

    /**
     * Show the form for editing the specified condominio.
     *
     * This method ensures that the user is authorized to update the specified condominio,
     * and then it renders the page with the form to edit the condominio details. It uses 
     * Inertia to render the `BuildingsEdit` component, passing the condominio data as a 
     * resource to the component.
     *
     * @param \App\Models\Condominio $condominio The condominio instance to be edited.
     *
     * @return \Inertia\Response A response that renders the condominio editing form with the existing data.
     */
    public function edit(Condominio $condominio): Response
    {
        Gate::authorize('update', $condominio);

        return Inertia::render('buildings/BuildingsEdit', [
            'building' => new CondominioResource($condominio),
        ]);
    }

    /**
     * Update the specified condominio in storage.
     *
     * This method is responsible for updating the condominio's data. It starts a database transaction, 
     * performs the update, and commits the transaction. If an error occurs during the update, 
     * the transaction is rolled back and the error is logged.
     *
     * @param \App\Http\Requests\UpdateCondominioRequest $request The request object containing validated input data.
     * @param \App\Models\Condominio $condominio The Condominio model instance to be updated.
     * @return \Illuminate\Http\RedirectResponse Redirects to the condominio index page with a success or error message.
     */
    public function update(UpdateCondominioRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('update', $condominio);

        try {

            DB::beginTransaction();

            $condominio->update($request->validated());

            DB::commit();

            return to_route('condomini.index')->with(
                $this->flashSuccess(__('condomini.success_edit_building'))
            );

        } catch (\Exception $e) {

            DB::rollback();

            Log::error('Error during condominio update: ' . $e->getMessage());

            return to_route('condomini.index')->with(
                $this->flashError(__('condomini.error_edit_building'))
            );
        }

    }

    public function options()
    {
        return CondominioOptionsResource::collection(Condominio::all());
    }

    /**
     * Remove the specified condominio from storage.
     *
     * This method is responsible for deleting the specified condominio. It checks if the user
     * has permission to delete the condominio and then performs the delete operation. If an error occurs, 
     * it logs the exception and returns an error message.
     *
     * @param \App\Models\Condominio $condominio The Condominio model instance to be deleted.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page with a success or error message.
     */
    public function destroy(Condominio $condominio): RedirectResponse
    {
        Gate::authorize('delete', Condominio::class);

        /*
         * ⚠️ **Il condominio dimostrativo si elimina da qui come da qualunque altra parte.**
         *
         * Aggiunto nella beta.71 su domanda di Vincenzo — *«si può anche rimuovere dal pulsante
         * elimina dentro le azioni? funziona anche da lì?»*. La risposta era **no**, e nel modo
         * peggiore: la cancellazione sarebbe fallita sui vincoli e l'amministratore avrebbe letto
         * «ha movimenti contabili registrati e non può essere eliminato» — una frase vera per i
         * condomini veri e **falsa proprio per quello che il programma dice di poter rimuovere**.
         *
         * Due strade per la stessa cosa devono dare lo stesso esito: chi preme «elimina» sui tre
         * puntini non deve sapere che quel condominio ha una porta sua.
         */
        if ($condominio->is_demo) {
            return $this->eliminaDimostrativo($condominio, app(CreaCondominioDimostrativoAction::class));
        }

        try {
           
            $condominio->delete();

            return back()->with(
                $this->flashSuccess(__('condomini.success_delete_building'))
            );

        } catch (\Illuminate\Database\QueryException $e) {

            /*
             * ⚠️ **Non è un errore imprevisto: è una regola, e va detta come tale.**
             *
             * I vincoli del database impediscono di eliminare un condominio che ha movimenti
             * contabili — `pagamenti_fornitori`, `deleghe_f24` e `casse` sono in `ON DELETE
             * RESTRICT`. È una protezione voluta: la contabilità di un condominio non deve poter
             * sparire premendo un pulsante.
             *
             * Ma fino alla beta.71 l'amministratore leggeva soltanto «si è verificato un errore
             * durante l'eliminazione». Non sapeva **perché**, quindi non poteva fare niente: né
             * capire che era una regola invece di un guasto, né sapere che riprovare è inutile.
             * È la famiglia di difetti che questa release ha passato due mesi a chiudere — un
             * messaggio che sposta il vicolo cieco di un passo invece di toglierlo.
             *
             * ⚠️ **La regola non è cambiata, e non deve.** Quello che manca davvero è un altro
             * verbo — *archiviare* un condominio che non si amministra più, senza cancellarne la
             * contabilità — ed è una funzione, non una correzione: vedi la voce in
             * `docs/roadmap.md`.
             */
            Log::error('Error deleting condominio: '.$e->getMessage());

            $bloccante = str_contains($e->getMessage(), '1451');

            return back()->with($this->flashError(
                $bloccante
                    ? "«{$condominio->nome}» ha movimenti contabili registrati — pagamenti, deleghe F24 o casse — e per questo non può essere eliminato: cancellarlo butterebbe via la sua contabilità. Se non lo amministri più, per ora puoi rinominarlo per distinguerlo dagli altri."
                    : __('condomini.error_delete_building')
            ));

        } catch (\Exception $e) {

            Log::error('Error deleting condominio: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('condomini.error_delete_building'))
            );
        }
      
    }

    /**
     * Costruisce il condominio dimostrativo.
     *
     * ⚠️ **Uno alla volta.** Due demo non servono — chi vuole capire il programma ne guarda una — e
     * costano: codice, email e numeri di documento sono unici a database. Il pulsante che porta qui
     * è già spento quando ce n'è una, e questa guardia è la seconda difesa, per la chiamata diretta.
     */
    public function creaDimostrativo(CreaCondominioDimostrativoAction $azione): RedirectResponse
    {
        Gate::authorize('create', Condominio::class);

        if ($esistente = $azione->esisteGia()) {
            return back()->with($this->flashError(
                "Esiste già un condominio dimostrativo («{$esistente->nome}»). Rimuovilo prima di crearne un altro."
            ));
        }

        try {
            $esito = $azione->esegui();

            // ⚠️ Gli avvisi si dicono, non si nascondono: se un passo non è andato a buon fine il
            // condominio è comunque utile, ma chi guarda deve sapere che manca qualcosa — altrimenti
            // crede che il programma non sappia fare la cosa che non vede.
            $messaggio = $esito['avvisi'] === []
                ? 'Condominio dimostrativo creato: struttura, millesimi, preventivo, piano rate, incassi, fatture e fondi.'
                : 'Condominio dimostrativo creato, ma alcuni passi non sono riusciti: '.implode(' · ', $esito['avvisi']);

            return to_route('admin.gestionale.index', ['condominio' => $esito['condominio']->id])
                ->with($esito['avvisi'] === [] ? $this->flashSuccess($messaggio) : $this->flashError($messaggio));

        } catch (\Throwable $e) {
            Log::error('Errore creando il condominio dimostrativo: '.$e->getMessage());

            return back()->with($this->flashError(
                'Non è stato possibile creare il condominio dimostrativo. Il dettaglio è nel registro degli errori.'
            ));
        }
    }

    /**
     * Rimuove il condominio dimostrativo, movimenti contabili compresi.
     *
     * ⚠️ **È l'unico caso in cui si cancella una contabilità, e ha una ragione precisa:** quei
     * movimenti li ha scritti il programma, non un amministratore. Su un condominio vero la
     * cancellazione resta vietata dai vincoli del database, ed è giusto così.
     */
    public function eliminaDimostrativo(Condominio $condominio, CreaCondominioDimostrativoAction $azione): RedirectResponse
    {
        Gate::authorize('delete', Condominio::class);

        try {
            $azione->rimuovi($condominio);

            return to_route('condomini.index')->with(
                $this->flashSuccess('Condominio dimostrativo rimosso, con tutti i dati che conteneva.')
            );

        } catch (\RuntimeException $e) {
            return back()->with($this->flashError($e->getMessage()));

        } catch (\Throwable $e) {
            Log::error('Errore rimuovendo il condominio dimostrativo: '.$e->getMessage());

            return back()->with($this->flashError(
                'Non è stato possibile rimuovere il condominio dimostrativo.'
            ));
        }
    }
}

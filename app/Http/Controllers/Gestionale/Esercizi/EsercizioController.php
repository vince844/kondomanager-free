<?php

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Esercizio\CreateEsercizioRequest;
use App\Http\Requests\Gestionale\Esercizio\EsercizioIndexRequest;
use App\Http\Requests\Gestionale\Esercizio\UpdateEsercizioRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\Esercizi\EsercizioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\OrdinaElenco;
use App\Traits\PaginaElenco;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EsercizioController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, OrdinaElenco, PaginaElenco;

    /**
     * Display a paginated list of exercises for a specific condominium.
     * 
     * This method retrieves and filters exercises based on the request parameters,
     * and returns the data needed to render the exercises list view. It also provides
     * additional context for navigation and breadcrumb components.
     *
     * @param EsercizioIndexRequest $request The validated request containing filters and pagination parameters
     * @param Condominio $condominio The condominium for which to retrieve exercises
     * 
     * @return Response Inertia response rendering the exercises list view with paginated data
     * 
     * @uses Condominio::esercizi() Eloquent relationship to access condominium's exercises
     * @uses EsercizioResource::collection() Transforms exercise data for the frontend
     * 
     * @requestParameters
     * - nome (optional): Filter exercises by name (partial match)
     * - per_page (optional): Number of items per page (default from config)
     * 
     * @responseIncludes
     * - condominio: Current condominium data
     * - esercizio: Currently active/open exercise for navigation
     * - condomini: List of all condominiums for breadcrumb dropdown
     * - esercizi: Paginated list of exercises with metadata
     * - filters: Current filter values for UI state persistence
     */
    public function index(EsercizioIndexRequest $request, Condominio $condominio): Response
    {
        /** @var \Illuminate\Http\Request $request */
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        // Get a list of all the esercizi create to show in the datatable
        $esercizi = $condominio->esercizi()
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, EsercizioIndexRequest::colonneOrdinabili(), predefinita: 'nome'))
            ->paginate($validated['per_page']);

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/esercizi/EserciziList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'esercizi'   => EsercizioResource::collection($esercizi)->resolve(),
            'meta'       => [
                'current_page' => $esercizi->currentPage(),
                'last_page'    => $esercizi->lastPage(),
                'per_page'     => $esercizi->perPage(),
                'total'        => $esercizi->total(),
            ],
            'filters' => $request->only(['nome']), 
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Display the form for creating a new exercise for a condominium.
     * 
     * This method prepares and returns the necessary data to render the exercise creation form.
     * It provides the current condominium context, active exercise for navigation,
     * and the list of all condominiums for breadcrumb navigation.
     *
     * @param Condominio $condominio The condominium for which the new exercise will be created
     * 
     * @return Response Inertia response rendering the exercise creation form with context data
     * 
     * @uses getCondomini() Retrieves all condominiums for navigation dropdown
     * @uses getEsercizioCorrente() Gets the current active exercise for menu navigation
     * 
     * @responseIncludes
     * - condominio: Current condominium context
     * - esercizio: Currently active exercise for navigation menu
     * - condomini: List of all condominiums for breadcrumb dropdown
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/esercizi/EserciziNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
        ]);
    }

    /**
     * Store a newly created exercise in storage.
     * 
     * This method handles the creation of a new exercise for the specified condominium.
     * It validates the incoming request data, creates the exercise record, and returns
     * appropriate feedback to the user. Any exceptions during the process are logged
     * for debugging purposes.
     *
     * @param CreateEsercizioRequest $request The validated request containing exercise data
     * @param Condominio $condominio The condominium to which the exercise belongs
     * 
     * @return RedirectResponse Redirects to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the creation process
     * 
     * @uses CreateEsercizioRequest::validated() Gets validated exercise data
     * @uses Esercizio::create() Persists the new exercise record
     * 
     * @flashMessages
     * - Success: 'gestionale.success_create_esercizio'
     * - Error: 'gestionale.error_create_esercizio'
     * 
     * @logContext
     * - condominio_id: ID of the related condominium
     * - message: Exception message
     * - trace: Full exception stack trace
     */
    public function store(CreateEsercizioRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            
            $data = $request->validated();
            Esercizio::create($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_create_esercizio')));

        } catch (\Throwable $e) {
            Log::error('Error creating esercizio', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_create_esercizio')));
        }
    }


    /**
     * Display the form for editing an existing exercise.
     * 
     * This method retrieves the specified exercise and prepares the data needed
     * to render the exercise editing form. The exercise data is transformed
     * using EsercizioResource for consistent API response formatting.
     *
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise instance to be edited
     * 
     * @return Response Inertia response rendering the exercise edit form with exercise data
     * 
     * @uses EsercizioResource Transforms exercise data for frontend consumption
     * 
     * @responseIncludes
     * - condominio: Current condominium context
     * - esercizio: Exercise data formatted via EsercizioResource for editing
     */
    public function edit(Condominio $condominio, Esercizio $esercizio): Response
    {
        return Inertia::render('gestionale/esercizi/EserciziEdit', [
            'condominio' => $condominio,
            'esercizio'  => new EsercizioResource($esercizio),
        ]);
    }

    /**
     * Update the specified exercise in storage.
     * 
     * This method handles updating an existing exercise with validated request data.
     * It processes the update operation and provides appropriate feedback to the user.
     * Any exceptions during the update process are logged with detailed context for debugging.
     *
     * @param UpdateEsercizioRequest $request The validated request containing updated exercise data
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise instance to be updated
     * 
     * @return RedirectResponse Redirects to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the update process
     * 
     * @uses UpdateEsercizioRequest::validated() Gets validated update data
     * @uses Esercizio::update() Persists the changes to the exercise record
     * 
     * @flashMessages
     * - Success: 'gestionale.success_update_esercizio'
     * - Error: 'gestionale.error_update_esercizio'
     * 
     * @logContext
     * - esercizio_id: ID of the exercise being updated
     * - condominio_id: ID of the related condominium
     * - message: Exception message
     * - trace: Full exception stack trace
     */
    public function update(UpdateEsercizioRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {

            $data = $request->validated();
            $esercizio->update($data);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_update_esercizio')));

        } catch (\Throwable $e) {

            Log::error('Error updating esercizio', [
                'esercizio_id'  => $esercizio->id,
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_update_esercizio')));
            
        }
    }

    /**
     * Delete a specific exercise for a condominium.
     * 
     * This method handles the safe deletion of an exercise by enforcing business rules:
     * - Prevents deletion if it's the last exercise for the condominium
     * - Prevents deletion if the exercise is currently in 'open' state
     * - Only allows deletion of closed exercises when multiple exercises exist
     * - Prevents deletion if the exercise holds scritture contabili (also those deleted with the old
     *   soft-delete method) or other rows the database would delete or detach with it: saldi, crediti
     *   residui, piani rate, gestioni attached only to this exercise, già versato, fatture passive,
     *   deleghe F24
     * 
     * @param Condominio $condominio The condominium to which the exercise belongs
     * @param Esercizio $esercizio The exercise to be deleted
     * 
     * @return RedirectResponse Redirects back to the exercises index page with flash message
     * 
     * @throws \Throwable Logs any exceptions that occur during the deletion process
     * 
     * @uses Condominio::esercizi() Relationship to count total exercises
     * @uses Esercizio::delete() To permanently remove the exercise
     * 
     * @flashMessages
     * - Success: 'gestionale.success_delete_esercizio'
     * - Error: 'gestionale.error_delete_last_esercizio' (if last exercise)
     * - Error: 'gestionale.error_delete_opened_esercizio' (if exercise is open)
     * - Error: 'gestionale.error_delete_esercizio' (for general errors)
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {

            // Le rotte annidate non usano scopeBindings: senza questa verifica le
            // guardie sotto valuterebbero condizioni miste — "è l'ultimo esercizio"
            // contato sul condominio di rotta, stato e scritture letti su un esercizio
            // che può appartenere a un altro condominio.
            if ($esercizio->condominio_id !== $condominio->id) {
                abort(403, 'L\'esercizio non appartiene a questo condominio.');
            }

            $numeroEsercizi = $condominio->esercizi()->count();

            // Impedisci sempre eliminazione se:
            // 1. È l'ultimo esercizio
            // 2. È l'esercizio aperto
            if ($numeroEsercizi <= 1) {
                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(__('gestionale.error_delete_last_esercizio')));
            }

            if ($esercizio->stato === 'aperto') {
                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(__('gestionale.error_delete_opened_esercizio')));
            }

            // MURO CONTABILE: scritture_contabili.esercizio_id è cascadeOnDelete
            // (migration 2025_12_17_212946:18). Senza questa guardia, eliminare un
            // esercizio chiuso distrugge in cascata l'intero libro giornale — righe,
            // pivot quota_scrittura e fattura_scrittura — e azzera in silenzio
            // rate_quote.scrittura_contabile_id (nullOnDelete), lasciando quote con
            // importo_pagato materializzato e nessun movimento a giustificarlo.
            // Un esercizio chiuso che contiene scritture è il dato più sigillato che
            // esista: non si cancella, si conserva.
            //
            // `ScritturaContabile` usa SoftDeletes: la query di un modello non vede le righe con
            // `deleted_at` (eliminate con il metodo di prima della 1.9.1), ma la cascata del vincolo le
            // porta via lo stesso. Si contano tutte, e il messaggio lo dice.
            $numeroScritture = ScritturaContabile::withTrashed()->where('esercizio_id', $esercizio->id)->count();

            if ($numeroScritture > 0) {
                $haEliminate = ScritturaContabile::onlyTrashed()->where('esercizio_id', $esercizio->id)->exists();
                $quante = $numeroScritture === 1 ? '1 scrittura contabile' : "{$numeroScritture} scritture contabili";
                $comprese = match (true) {
                    ! $haEliminate => '',
                    $numeroScritture === 1 => ' (compresa quella eliminata in passato, che l\'elenco non mostra più ma il database conserva)',
                    default => ' (comprese quelle eliminate in passato, che l\'elenco non mostra più ma il database conserva)',
                };

                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(
                        "Operazione negata: l'esercizio contiene {$quante}{$comprese}. "
                        .'Eliminarlo distruggerebbe il libro giornale di quel periodo, che va conservato.'
                    ));
            }

            // Coda 275: il vincolo del database porta via in cascata i saldi, i crediti residui e i legami
            // con le gestioni dell'esercizio, e azzera l'esercizio dei piani rate. Un solo rifiuto, che
            // dice quanti sono e dove si vedono.
            $datiCheTrattengono = $this->datiCheTrattengonoLEsercizio($esercizio);

            if ($datiCheTrattengono !== []) {
                return to_route('admin.gestionale.esercizi.index', $condominio)
                    ->with($this->flashError(
                        "Impossibile eliminare l'esercizio «{$esercizio->nome}»: l'eliminazione cancellerebbe dati che non sono "
                        ."scritture contabili, li staccherebbe dall'esercizio o il database la rifiuterebbe. "
                        .'Contiene: '.implode('; ', $datiCheTrattengono).'. '
                        ."L'esercizio si elimina solo quando questi dati non ci sono più; finché ci sono, si conserva."
                    ));
            }

            // Elimina solo esercizi chiusi, non ultimi e senza alcun movimento contabile
            $esercizio->delete();

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashSuccess(__('gestionale.success_delete_esercizio')));

        } catch (\Throwable $e) {

            Log::error('Error deleting esercizio', [
                'esercizio_id'   => $esercizio->id,
                'condominio_id'  => $condominio->id,
                'message'        => $e->getMessage(),
            ]);

            return to_route('admin.gestionale.esercizi.index', $condominio)
                ->with($this->flashError(__('gestionale.error_delete_esercizio')));
        }
    }

    /**
     * Che cosa, dentro l'esercizio, l'eliminazione cancellerebbe, staccherebbe dall'esercizio o il database
     * rifiuterebbe (Coda 275), una frase per ogni genere di dato che c'è; vuoto se non c'è niente.
     *
     * Si conta con le tabelle e non con i modelli: interessa ciò che il vincolo del database vede, non
     * ciò che un modello mostra. Le righe del solo legame `esercizio_gestione` non trattengono (un
     * esercizio importato ha sempre la gestione agganciata a ogni esercizio); trattiene la gestione che
     * resterebbe senza nessun esercizio, perché le sue pagine si aprono dall'esercizio.
     *
     * @return list<string>
     */
    private function datiCheTrattengonoLEsercizio(Esercizio $esercizio): array
    {
        $frasi = [];
        $nomi = fn (iterable $elenco): string => collect($elenco)->map(fn ($nome) => "«{$nome}»")->implode(', ');

        $saldiCondomini = DB::table('saldi')->where('esercizio_id', $esercizio->id)->whereNull('fornitore_id')->count();
        if ($saldiCondomini > 0) {
            $frasi[] = ($saldiCondomini === 1 ? '1 saldo iniziale di un condòmino' : "{$saldiCondomini} saldi iniziali dei condòmini")
                // La sezione «Saldi Iniziali» mostra i saldi del solo esercizio aperto (`SaldoInizialeController::index`), e qui
                // l'esercizio non è aperto: lì questi saldi non compaiono, e la frase non deve mandarci a cercarli.
                .' (la sezione «Saldi Iniziali» mostra solo quelli dell\'esercizio aperto)';
        }

        $debitiFornitori = DB::table('saldi')->where('esercizio_id', $esercizio->id)->whereNotNull('fornitore_id')->count();
        if ($debitiFornitori > 0) {
            $frasi[] = ($debitiFornitori === 1 ? '1 debito iniziale verso un fornitore' : "{$debitiFornitori} debiti iniziali verso fornitori")
                .' (nella situazione debitoria del fornitore, non nella sezione «Saldi Iniziali»)';
        }

        $creditiResidui = DB::table('crediti_residui')->where('esercizio_id', $esercizio->id)->count();
        if ($creditiResidui > 0) {
            $frasi[] = $creditiResidui === 1 ? '1 credito residuo' : "{$creditiResidui} crediti residui";
        }

        $piani = DB::table('piani_rate')->where('esercizio_id', $esercizio->id)->orderBy('nome')->pluck('nome');
        if ($piani->isNotEmpty()) {
            $frasi[] = ($piani->count() === 1 ? '1 piano rate' : "{$piani->count()} piani rate").' ('.$nomi($piani).')';
        }

        $gestioniSoloSue = DB::table('esercizio_gestione as questo')
            ->join('gestioni', 'gestioni.id', '=', 'questo.gestione_id')
            ->where('questo.esercizio_id', $esercizio->id)
            ->whereNotExists(fn ($altro) => $altro->select(DB::raw(1))
                ->from('esercizio_gestione as altro')
                ->whereColumn('altro.gestione_id', 'questo.gestione_id')
                ->where('altro.esercizio_id', '!=', $esercizio->id))
            ->orderBy('gestioni.nome')
            ->pluck('gestioni.nome');
        if ($gestioniSoloSue->isNotEmpty()) {
            $frasi[] = ($gestioniSoloSue->count() === 1 ? '1 gestione che resterebbe senza esercizio' : "{$gestioniSoloSue->count()} gestioni che resterebbero senza esercizio")
                .' ('.$nomi($gestioniSoloSue).')';
        }

        // Il già versato con un esercizio suo: la chiave azzera l'esercizio (`nullOnDelete`) e la riga passerebbe a valere in tutti
        // i piani della voce, cioè la cifra che i piani degli altri esercizi tolgono cambierebbe senza che nessuno l'abbia deciso.
        $giaVersato = DB::table('contributi_versati')->where('esercizio_id', $esercizio->id)->count();
        if ($giaVersato > 0) {
            $frasi[] = ($giaVersato === 1 ? '1 importo già versato per questo esercizio' : "{$giaVersato} importi già versati per questo esercizio")
                .' (pagina «Già versato»)';
        }

        $fatture = DB::table('fatture_passive')->where('esercizio_id', $esercizio->id)->count();
        if ($fatture > 0) {
            $frasi[] = $fatture === 1 ? '1 fattura passiva' : "{$fatture} fatture passive";
        }

        $deleghe = DB::table('deleghe_f24')->where('esercizio_id', $esercizio->id)->count();
        if ($deleghe > 0) {
            $frasi[] = $deleghe === 1 ? '1 delega F24' : "{$deleghe} deleghe F24";
        }

        return $frasi;
    }
}

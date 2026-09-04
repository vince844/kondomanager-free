<?php

namespace App\Http\Controllers\Fornitori;

use App\Traits\OrdinaElenco;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\TipoRitenuta;
use App\Enums\RuoloRappresentanteFornitore;
use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fornitore\CreateFornitoreRequest;
use App\Http\Requests\Fornitore\FornitoreIndexRequest;
use App\Http\Requests\Fornitore\UpdateFornitoreRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Fornitore\Categorie\CategoriaFornitoreResource;
use App\Http\Resources\Fornitore\EditFornitoreResource;
use App\Http\Resources\Fornitore\FornitoreResource;
use App\Models\Anagrafica;
use App\Models\CategoriaFornitore;
use App\Models\Fornitore;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FornitoreController extends Controller
{
    use OrdinaElenco;
    use HandleFlashMessages;
    use PaginaElenco;

    /**
     * Display paginated list of fornitori with filtering options.
     * 
     * Returns fornitori data for the index page with optional ragione_sociale filter.
     * Includes pagination metadata and current filters for the frontend.
     *
     * @param FornitoreIndexRequest $request Validated request with filters
     * @return Response Inertia response with fornitori data and pagination info
     * @since v1.8.0
     */
    public function index(FornitoreIndexRequest $request): Response
    {
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $fornitori = Fornitore::with(['referenti:id,nome,indirizzo', 'categoria'])
            ->when($validated['ragione_sociale'] ?? false, function ($query, $ragioneSociale) {
                $query->where('ragione_sociale', 'like', "%{$ragioneSociale}%");
            })
            // ⚠️ **Il filtro per categoria, dalla 1.11.0-beta.9.**
            //
            // La categoria arrivava al browser in ogni riga — `with(['categoria'])` qui sopra,
            // `FornitoreResource` la serializza — e **nessuno la mostrava né la usava**: niente
            // colonna, niente filtro. Cioè si poteva classificare un fornitore e poi non c'era
            // modo di chiedere «mostrami tutti gli idraulici». È molto probabilmente il motivo per
            // cui, misurato il 30/08/2026, **sei fornitori su otto non avevano categoria**:
            // compilarla non serviva a niente.
            ->when($validated['categoria_id'] ?? false, function ($query, array $categorie) {
                $query->whereIn('categoria_id', $categorie);
            })
            ->tap(fn ($q) => $this->ordina($q, $validated, FornitoreIndexRequest::colonneOrdinabili(), predefinita: 'ragione_sociale', versoPredefinito: 'asc'))
            ->paginate($validated['per_page'])
            ->withQueryString();
    
        return Inertia::render('fornitori/FornitoriList', [
            'fornitori' => FornitoreResource::collection($fornitori)->resolve(),
            'meta' => [
                'current_page' => $fornitori->currentPage(),
                'last_page'    => $fornitori->lastPage(),
                'per_page'     => $fornitori->perPage(),
                'total'        => $fornitori->total(),
            ],
            // Le categorie viaggiano **sempre**, non a richiesta: sono poche righe, e caricarle
            // pigramente all'apertura del menù — come fa l'elenco documenti — costringe poi a
            // ricaricarle a mano quando si arriva con il filtro già applicato, altrimenti la
            // pillola sa di essere accesa e non sa scrivere su cosa. Quel difetto è costato una
            // segnalazione di Vincenzo sull'elenco documenti: qui non può darsi.
            'categorie' => CategoriaFornitore::orderBy('name')->get(['id', 'name']),

            'filters' => $request->only(['ragione_sociale', 'categoria_id']), 
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new fornitore.
     * Returns an Inertia response with data needed for the create form.
     *
     * @return Response
     * @since v1.8.0
     */
    public function create(): Response
    {
        return Inertia::render('fornitori/FornitoriNew', [
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all()),
            // I sei ruoli vengono dall'enum, non da una lista scritta a mano nella pagina: erano
            // già duplicati fra `CreateFornitoreAnagraficaRequest` e `AnagraficheNew.vue`.
            'ruoliRappresentante' => RuoloRappresentanteFornitore::opzioni(),
            ...$this->regimeFiscaleOptions(),
        ]);
    }

    /**
     * Store a newly created fornitore in storage.
     * Creates a new fornitore record, attaches related referenti,
     * and creates the default bank account if an IBAN is provided.
     *
     * @param CreateFornitoreRequest $request Validated request data
     * @return RedirectResponse|JsonResponse Redirects to index with success/error message
     * @since v1.9.0
     *
     * ⚠️ **Stesso endpoint, due esiti — negoziato sull'Accept, non duplicato.**
     * Aggiunto aprendo la riprogettazione della UI di importazione XML (02/09/2026):
     * il fornitore letto da un XML e non ancora in anagrafica va creato **senza
     * lasciare la pagina** di registrazione della fattura, e `store()` fin qui
     * rispondeva sempre con un redirect verso `admin.fornitori.index` — corretto per
     * il form a pagina intera, ma per una chiamata da un modale significherebbe
     * seguire il redirect e ricevere HTML al posto del fornitore appena creato.
     *
     * `CreateFornitoreRequest`, la creazione, gli effetti collaterali (referente,
     * conto corrente) restano **identici in entrambi i casi**: è la stessa strada,
     * non una seconda che rischia di divergere da questa — il commento su
     * `anagrafica_id`/`ruolo` qui sotto ricorda cosa costa avere due strade per la
     * stessa cosa.
     */
    public function store(CreateFornitoreRequest $request): RedirectResponse|JsonResponse
    {

        // Otteniamo i dati validati.
        // Assicurati che CreateFornitoreRequest accetti e validi anche iban_principale
        $data = $request->validated();

        try {
            DB::beginTransaction();

            // 1. Creazione del Fornitore (i campi fiscali verranno salvati in automatico se presenti in $data)
            //
            // ⚠️ `ritenuta_decisa_il` si scrive qui e non arriva dal modulo: non è un campo che
            // l'amministratore compila, è la registrazione del fatto che **ha risposto**. Alla
            // creazione ha sempre risposto, anche quando la risposta è «non soggetto a ritenuta»
            // — ed è proprio quel «no» che senza questa riga resterebbe indistinguibile da un
            // silenzio (Coda 116).
            $fornitore = Fornitore::create($data + ['ritenuta_decisa_il' => now()]);

            // 2. Associazione dell'Anagrafica come referente (se presente)
            if (!empty($data['anagrafica_id'])) {
                // Con il ruolo, che la validazione rende obbligatorio appena si sceglie
                // un'anagrafica: senza, nasceva una riga che la scheda «Rappresentanti» avrebbe
                // rifiutato, e che lì compariva con la colonna Ruolo vuota.
                $fornitore->referenti()->attach($data['anagrafica_id'], [
                    'ruolo' => $data['ruolo'],
                ]);
            }

            // 3. Creazione del Conto Corrente predefinito (se è stato inserito un IBAN)
            if (!empty($data['iban_principale'])) {
                $fornitore->contiCorrenti()->create([
                    'iban'         => str_replace(' ', '', strtoupper($data['iban_principale'])),
                    'intestatario' => $fornitore->ragione_sociale,
                    'predefinito'  => true,
                    'tipo'         => 'ordinario'
                ]);
            }

            DB::commit();

            if ($request->expectsJson()) {
                return response()->json([
                    'id' => $fornitore->id,
                    'ragione_sociale' => $fornitore->ragione_sociale,
                ], 201);
            }

            return to_route('admin.fornitori.index')->with(
                $this->flashSuccess(__('fornitori.success_create_fornitore'))
            );

        } catch (\Throwable $e) {
            DB::rollback();

            Log::error('Error creating fornitore', [
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'errore' => __('fornitori.error_create_fornitore'),
                ], 500);
            }

            return to_route('admin.fornitori.index')->with(
                $this->flashError(__('fornitori.error_create_fornitore'))
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Fornitore $fornitore): Response
    {

        $fornitore->loadMissing('referenti', 'categoria');

        return Inertia::render('fornitori/FornitoriView', [
            'fornitore' => new FornitoreResource($fornitore),
        ]);
     
    }

    /**
     * Show the form for editing the specified fornitore.
     * Returns data needed for the edit form including related categories.
     *
     * @param Fornitore $fornitore Fornitore to edit
     * @return Response
     * @since v1.8.0
     */
    public function edit(Fornitore $fornitore): Response
    {
        RedirectHelper::rememberUrl();

        return Inertia::render('fornitori/FornitoriEdit', [
            'fornitore'   => new EditFornitoreResource($fornitore),
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all()),
            ...$this->regimeFiscaleOptions(),
       ]);
    }

    /**
     * Opzioni per le select del regime fiscale ritenuta (v1.10, Fase 1).
     *
     * @return array{tipiRitenuta: array<int, array{value:string,label:string}>, natureRecipiente: array<int, array{value:string,label:string}>}
     */
    private function regimeFiscaleOptions(): array
    {
        return [
            'tipiRitenuta' => collect(TipoRitenuta::cases())
                ->map(fn (TipoRitenuta $c) => ['value' => $c->value, 'label' => $c->label()])
                ->values(),
            'natureRecipiente' => collect(NaturaPercipiente::cases())
                ->map(fn (NaturaPercipiente $c) => ['value' => $c->value, 'label' => $c->label()])
                ->values(),
        ];
    }

    /**
     * Update the specified fornitore in storage.
     * Updates fornitore data, manages referenti relations, and updates/creates the default IBAN.
     *
     * @param UpdateFornitoreRequest $request Validated request data
     * @param Fornitore $fornitore Fornitore to update
     * @return RedirectResponse Redirects to index with message
     * @since v1.9.0
     */
    public function update(UpdateFornitoreRequest $request, Fornitore $fornitore): RedirectResponse
    {
        $validated = $request->validated(); 

        try {
            DB::beginTransaction();

            // 1. Aggiorna i dati del fornitore (inclusi i campi fiscali e lo stato)
            //
            // ⚠️ La data della decisione si aggiorna **solo se il riquadro fiscale è stato
            // toccato**: chi sta cambiando l'IBAN non si è pronunciato sulla ritenuta, e
            // segnare quella scheda come decisa registrerebbe una risposta che nessuno ha
            // dato. È lo stesso criterio della presa d'atto di ieri, e viene dallo stesso
            // trait proprio perché i due non possano divergere.
            if ($request->costituisceUnaPresaDiPosizione($fornitore)) {
                $validated['ritenuta_decisa_il'] = now();
            }

            $fornitore->update($validated);

            // 2. I rappresentanti non si toccano da qui, ed è una rimozione voluta della beta.7.
            // Prima questo punto faceva `detach()` senza argomenti quando il modulo non mandava
            // `anagrafica_id` — cioè portava via **tutti** i rappresentanti a ogni salvataggio, con
            // il messaggio verde di successo. La correzione non è stata rimettere la casella (che
            // non sa esprimere il `ruolo`, obbligatorio dall'altra parte) ma togliere del tutto la
            // scrittura: la relazione si governa dalla scheda «Rappresentanti».

            // 3. Gestione del Conto Corrente (Tesoreria)
            // Se c'è un IBAN, lo aggiorniamo (o lo creiamo se non esisteva).
            if (!empty($validated['iban_principale'])) {
                $ibanPulito = str_replace(' ', '', strtoupper($validated['iban_principale']));
                
                // updateOrCreate cerca il primo conto 'ordinario' di questo fornitore.
                // Se lo trova lo aggiorna, se non c'è lo crea.
                $fornitore->contiCorrenti()->updateOrCreate(
                    ['tipo' => 'ordinario'], // Condizione di ricerca
                    [
                        'iban'         => $ibanPulito,
                        'intestatario' => $fornitore->ragione_sociale,
                        'predefinito'  => true
                    ] // Valori da aggiornare/inserire
                );
            } else {
                // Se l'utente cancella l'IBAN dal form, potresti volerlo eliminare
                // o lasciarlo nello storico. Per sicurezza e coerenza col form, 
                // cancelliamo il conto ordinario associato (se c'era).
                $fornitore->contiCorrenti()->where('tipo', 'ordinario')->delete();
            }

            DB::commit();

            return RedirectHelper::backOr(
                route('admin.fornitori.index'),
                $this->flashSuccess(__('fornitori.success_update_fornitore'))
            );

        // `\Throwable` e non `\Exception`, come già fa `store()`: un `TypeError` sfuggirebbe alla
        // cattura e lascerebbe aperta la transazione aperta poco sopra.
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error updating fornitore: ' . $e->getMessage());

            return RedirectHelper::backOr(
                route('admin.fornitori.index'),
                $this->flashError(__('fornitori.error_update_fornitore'))
            );
        }
    }

   /**
     * Remove the specified fornitore from storage.
     * Ensures polymorphic relations are cleaned and prevents deletion if invoices exist.
     *
     * @param Fornitore $fornitore Fornitore to delete
     * @return RedirectResponse Redirects back with success/error message
     * @since v1.8.0
     */
    public function destroy(Fornitore $fornitore): RedirectResponse
    {
        try {
            // 1. CONTROLLO CONTABILE (Fondamentale!)
            if ($fornitore->fatture()->exists()) {
                return back()->with(
                   $this->flashError(__('fornitori.error_delete_has_invoices'))
                );
            }

            // Iniziamo la transazione per essere sicuri che la pulizia sia completa
            DB::beginTransaction();

            // 2. PULIZIA DATI POLIMORFICI
            // Eliminiamo tutti i conti correnti associati a questo fornitore
            $fornitore->contiCorrenti()->delete();

            // 3. ELIMINAZIONE FORNITORE
            // I referenti nella tabella pivot verranno eliminati automaticamente 
            // dal DB grazie all'onDelete('cascade') della migrazione.
            $fornitore->delete();

            DB::commit();

            return back()->with(
                $this->flashSuccess(__('fornitori.success_delete_fornitore'))
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error deleting fornitore: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('fornitori.error_delete_fornitore'))
            );
        }
    }
}

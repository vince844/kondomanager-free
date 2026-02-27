<?php

namespace App\Http\Controllers\Fornitori;

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
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FornitoreController extends Controller
{
    use HandleFlashMessages;
    
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

        $fornitori = Fornitore::with(['referenti:id,nome,indirizzo', 'categoria'])
            ->when($validated['ragione_sociale'] ?? false, function ($query, $ragioneSociale) {
                $query->where('ragione_sociale', 'like', "%{$ragioneSociale}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'))
            ->withQueryString();
    
        return Inertia::render('fornitori/FornitoriList', [
            'fornitori' => FornitoreResource::collection($fornitori)->resolve(),
            'meta' => [
                'current_page' => $fornitori->currentPage(),
                'last_page'    => $fornitori->lastPage(),
                'per_page'     => $fornitori->perPage(),
                'total'        => $fornitori->total(),
            ],
            'filters' => $request->only(['ragione_sociale']) 
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
        ]);
    }

    /**
     * Store a newly created fornitore in storage.
     * Creates a new fornitore record, attaches related referenti,
     * and creates the default bank account if an IBAN is provided.
     *
     * @param CreateFornitoreRequest $request Validated request data
     * @return RedirectResponse Redirects to index with success/error message
     * @since v1.9.0
     */
    public function store(CreateFornitoreRequest $request): RedirectResponse
    {

        // Otteniamo i dati validati.
        // Assicurati che CreateFornitoreRequest accetti e validi anche iban_principale
        $data = $request->validated();

        try {
            DB::beginTransaction();

            // 1. Creazione del Fornitore (i campi fiscali verranno salvati in automatico se presenti in $data)
            $fornitore = Fornitore::create($data);

            // 2. Associazione dell'Anagrafica come referente (se presente)
            if (!empty($data['anagrafica_id'])) {
                $fornitore->referenti()->attach($data['anagrafica_id']);
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

            return to_route('admin.fornitori.index')->with(
                $this->flashSuccess(__('fornitori.success_create_fornitore'))
            );

        } catch (\Throwable $e) {
            DB::rollback();
            
            Log::error('Error creating fornitore', [
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

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
            'categorie'   => CategoriaFornitoreResource::collection(CategoriaFornitore::all())
       ]);
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
            $fornitore->update($validated);

            // 2. Sincronizza l'Anagrafica (Referente Principale)
            if (!empty($validated['anagrafica_id'])) {
                $fornitore->referenti()->sync([$validated['anagrafica_id']]);
            } else {
                // Se viene svuotato il campo nel form, rimuoviamo l'associazione
                $fornitore->referenti()->detach();
            }

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

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting fornitore: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('fornitori.error_delete_fornitore'))
            );
        }
    }
}

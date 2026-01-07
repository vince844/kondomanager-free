<?php

namespace App\Http\Controllers\Gestionale\Casse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Casse\CassaIndexRequest;
use App\Http\Requests\Gestionale\Casse\CreateCassaRequest;
use App\Http\Resources\Gestionale\Casse\CassaResource;
use App\Actions\Cassa\CreateCassaAction;
use App\Actions\Cassa\UpdateCassaAction;
use App\Helpers\MoneyHelper;
use App\Http\Requests\Gestionale\Casse\UpdateCassaRequest;
use App\Http\Resources\Gestionale\Casse\UpdateCassaResource;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CassaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function __construct(
        private CreateCassaAction $createCassaAction,
        private UpdateCassaAction $updateCassaAction
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(CassaIndexRequest $request, Condominio $condominio): Response
    {
        $validated = $request->validated();

        $query = $condominio
            ->casse()
            ->with(['contoCorrente'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            // --- CALCOLO SALDO DINAMICO ---
            // Sommiamo le entrate (Dare) e le uscite (Avere) direttamente via SQL
            ->withSum(['movimenti as totale_entrate' => function ($q) {
                $q->where('tipo_riga', 'dare');
            }], 'importo')
            ->withSum(['movimenti as totale_uscite' => function ($q) {
                $q->where('tipo_riga', 'avere');
            }], 'importo');

        // Eseguiamo la paginazione
        $casse = $query->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $condomini = $this->getCondomini();
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/casse/CasseList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            
            // 🔥 USIAMO LA RESOURCE COLLECTION
            // Laravel passerà automaticamente i campi calcolati (totale_entrate, totale_uscite) alla resource
            'casse' => CassaResource::collection($casse)->resolve(),
            
            'meta' => [
                'current_page' => $casse->currentPage(),
                'last_page'    => $casse->lastPage(),
                'per_page'     => $casse->perPage(),
                'total'        => $casse->total(),
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

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/casse/CasseNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateCassaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
 
            $this->createCassaAction->execute($condominio, $request->validated());

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_cassa'))
            );

        } catch (\Throwable $e) {

            Log::error('Errore creazione cassa', [
                'condominio_id' => $condominio->id,
                'msg' => $e->getMessage()
            ]);
            
            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError($e->getMessage())
            );

        }

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Condominio $condominio, Cassa $cassa): Response
    {
        $condomini = $this->getCondomini();

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/casse/CasseEdit', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizio'  => $esercizio,
            'cassa'      => new UpdateCassaResource($cassa),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCassaRequest $request, Condominio $condominio, Cassa $cassa): RedirectResponse
    {
        // 1. Sicurezza dominio (Questo è compito del Controller o di un Policy/Middleware)
        if ($cassa->condominio_id !== $condominio->id) {
             return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError('Risorsa non appartenente al condominio corrente.')
            );
        }

        try {
            // 2. Chiamata alla Action
            // Iniettiamo l'action nel costruttore come fatto prima ($this->updateCassaAction)
            $this->updateCassaAction->execute($cassa, $request->validated());

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess('Risorsa aggiornata correttamente.')
            );

        } catch (\Throwable $e) {
            Log::error('Errore aggiornamento cassa', [
                'condominio_id' => $condominio->id,
                'cassa_id' => $cassa->id,
                'msg' => $e->getMessage()
            ]);
            
            return back()->with(
                $this->flashError('Errore durante il salvataggio: ' . $e->getMessage())
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Cassa $cassa): RedirectResponse
    {
        // 1. Sicurezza: Verifica che la cassa appartenga al condominio corrente
        if ($cassa->condominio_id !== $condominio->id) {
            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError('Operazione non consentita: la risorsa non appartiene a questo condominio.')
            );
        }

        try {
            DB::transaction(function () use ($cassa, $condominio) {
                
                // Carichiamo il conto contabile associato per poterlo eliminare dopo
                $contoContabile = $cassa->contoContabile;

                // --- CONTROLLO INTEGRITÀ (DA IMPLEMENTARE QUANDO AVREMO I MOVIMENTI) ---
                // Se il conto contabile ha dei movimenti (es. incassi rate, pagamenti fatture),
                // NON possiamo eliminare la cassa, altrimenti sballiamo il bilancio.
                /*
                if ($contoContabile && $contoContabile->movimenti()->exists()) {
                    throw new \Exception("Impossibile eliminare: questa risorsa ha dei movimenti contabili registrati. Disattivala invece di eliminarla.");
                }
                */

                // 2. Elimina i dati bancari (ContoCorrente) se presenti
                // Utilizziamo la relazione per eliminare il record polimorfico
                $cassa->contoCorrente()->delete();

                // 3. Elimina la Cassa
                $cassa->delete();

                // 🔥 FIX 2: CONGELAMENTO CONTABILE (Mai delete sui conti di sistema!)
                if ($contoContabile) {
                    $contoContabile->update([
                        'attivo' => false,
                        // Opzionale: Rinominiamo per chiarezza storica ed evitare conflitti futuri
                        'nome'   => $contoContabile->nome . ' (Archiviato ' . date('d/m/Y') . ')',
                        'note'   => 'Cassa eliminata in data ' . date('d/m/Y H:i'),
                    ]);
                    
                    // Se usi SoftDeletes nel modello ContoContabile, puoi anche fare:
                    // $contoContabile->delete(); 
                    // (Che fa un soft delete, mantenendo il record nel DB. È equivalente a disattivare).
                    // Ma seguire il feedback letterale ('attivo' => false) è più esplicito.
                }
            });

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess('Risorsa eliminata correttamente.')
            );

        } catch (\Throwable $e) {
            Log::error('Errore eliminazione cassa', [
                'condominio_id' => $condominio->id,
                'cassa_id'      => $cassa->id,
                'error'         => $e->getMessage()
            ]);

            return back()->with(
                $this->flashError('Impossibile eliminare la risorsa: ' . $e->getMessage())
            );
        }
    }
}

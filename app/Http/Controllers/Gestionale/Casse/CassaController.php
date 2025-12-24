<?php

namespace App\Http\Controllers\Gestionale\Casse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Casse\CassaIndexRequest;
use App\Http\Requests\Gestionale\Casse\CreateCassaRequest;
use App\Http\Resources\Gestionale\Casse\CassaResource;
use App\Models\Condominio;
use App\Models\ContoCorrente;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CassaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Display a listing of the resource.
     */
    public function index(CassaIndexRequest $request, Condominio $condominio): Response
    {
        $validated = $request->validated();

        // Get a list of all the esercizi create to show in the datatable
        $casse = $condominio
            ->casse()
            ->with('contoCorrente')
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        // Get a list of all the registered condomini 
        $condomini = $this->getCondomini();
        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
            
        return Inertia::render('gestionale/casse/CasseList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
            'casse'      => CassaResource::collection($casse)->resolve(),
            'meta'       => [
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
        $validated = $request->validated();
        
        // DEBUG: Forziamo il cast a booleano per essere sicuri al 100%
        // filter_var gestisce correttamente stringhe "true", "1", "on" etc.
        $isPredefinito = filter_var($validated['predefinito'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            DB::transaction(function () use ($validated, $condominio, $isPredefinito) {
                
                // --- FASE 1: CONTABILITÀ (Invariata) ---
                $mastro = ContoContabile::firstOrCreate(
                    ['condominio_id' => $condominio->id, 'codice' => '10'],
                    ['nome' => 'Disponibilità Liquide', 'tipo' => 'attivo', 'categoria' => 'liquidita', 'livello' => 0, 'di_sistema' => true]
                );

                $countFigli = ContoContabile::where('condominio_id', $condominio->id)->where('parent_id', $mastro->id)->count();
                $prossimoCodice = '10.' . str_pad($countFigli + 1, 2, '0', STR_PAD_LEFT);

                $contoContabile = ContoContabile::create([
                    'condominio_id' => $condominio->id,
                    'parent_id'     => $mastro->id,
                    'codice'        => $prossimoCodice,
                    'nome'          => $validated['nome'],
                    'tipo'          => 'attivo',
                    'categoria'     => 'liquidita',
                    'livello'       => 1,
                    'attivo'        => true,
                ]);

                // --- FASE 2: CASSA (Invariata) ---
                $cassa = Cassa::create([
                    'condominio_id'      => $condominio->id,
                    'nome'               => $validated['nome'],
                    'tipo'               => $validated['tipo'],
                    'conto_contabile_id' => $contoContabile->id,
                    'attiva'             => true,
                    'note'               => $validated['note'],
                ]);

                // --- FASE 3: CONTO CORRENTE (Logica Semplificata) ---
                if ($validated['tipo'] === 'banca') {
                    
                    if ($isPredefinito) {
                        // 1. Trova gli ID di tutte le casse 'banca' di QUESTO condominio
                        $casseIds = Cassa::where('condominio_id', $condominio->id)
                            ->where('tipo', 'banca')
                            ->pluck('id');

                        // 2. Aggiorna i conti collegati a queste casse mettendo predefinito a 0
                        // Usiamo '0' esplicito invece di false per compatibilità SQL massima
                        ContoCorrente::where('contable_type', Cassa::class)
                            ->whereIn('contable_id', $casseIds)
                            ->update(['predefinito' => 0]);
                    }

                    // Creazione del nuovo conto
                    $cassa->contoCorrente()->create([
                        'iban'         => $validated['iban'],
                        'istituto'     => $validated['istituto'],
                        'swift'        => $validated['bic'] ?? null,
                        'intestatario' => $validated['intestatario'] ?? $condominio->nome,
                        'tipo'         => $validated['tipo_conto'] ?? 'ordinario',
                        'indirizzo'    => $validated['indirizzo'] ?? null,
                        'comune'       => $validated['comune'] ?? null,
                        'cap'          => $validated['cap'] ?? null,
                        'provincia'    => $validated['provincia'] ?? null,
                        'nazione'      => 'Italia',
                        
                        // Passiamo il valore booleano pulito
                        'predefinito'  => $isPredefinito, 
                    ]);
                }
            });

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_cassa'))
            );

        } catch (\Throwable $e) {
            Log::error('Errore creazione cassa', ['msg' => $e->getMessage()]);
            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError($e->getMessage()) // Mostriamo l'errore reale per debug
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
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

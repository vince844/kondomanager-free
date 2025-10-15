<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Spese;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\Spesa\CreateSpesaRequest;
use App\Http\Requests\Gestionale\StoreContoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpesaController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio, Esercizio $esercizio, PianoConto $conto): Response
    {

        $contiDisponibili = Conto::where('piano_conto_id', $conto->id) // ← Usa Conto
            ->whereNull('parent_id')
            ->select(['id', 'nome'])
            ->orderBy('nome')
            ->get();
        
         // Albero dei conti con relazioni
        $conti = Conto::with(['sottoconti' => function($query) { // ← Usa Conto
                $query->orderBy('nome');
            }])
            ->where('piano_conto_id', $conto->id)
            ->whereNull('parent_id')
            ->orderBy('nome')
            ->get();
        
        $tabelle = $condominio->tabelle()->select(['id', 'nome'])->orderBy('nome')->get();

        return Inertia::render('gestionale/pianiDeiConti/Spese/SpeseNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'tabelle'    => $tabelle,
            'conto'      => $conto,
            'conti'      => $conti,
            'contiDisponibili'  => $contiDisponibili,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateSpesaRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $conto): RedirectResponse
    {
        try {
            DB::beginTransaction();

            $data = $request->validated();
            $isCapitolo = $data['isCapitolo'];
            $isSottoConto = $data['isSottoConto'];
                
            // Prepara i dati per la creazione
            $contoData = [
                'piano_conto_id' => $conto->id,
                'parent_id' => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                'nome' => $data['nome'],
                'descrizione' => $data['descrizione'] ?? null,
                'tipo' => $data['tipo'],
                'importo' => $isCapitolo ? 0 : $data['importo'] * 100, // Converti in centesimi
                'note' => $data['note'] ?? null,
                'attivo' => true,
            ];

            // Crea il conto
            $nuovoConto = Conto::create($contoData);

            // Se non è un capitolo, gestisci le ripartizioni millesimali
            if (!$isCapitolo) {
                
                // Se è stata selezionata una tabella specifica, usiamo quella
                if (!empty($data['tabella_millesimale_id'])) {
                    $tabella = Tabella::where('id', $data['tabella_millesimale_id'])
                        ->where('condominio_id', $condominio->id)
                        ->first();
                    
                    if (!$tabella) {
                        throw new \Exception('Tabella millesimale selezionata non trovata');
                    }
                } else {
                    // Altrimenti usa la tabella principale del condominio
                    $tabella = Tabella::where('condominio_id', $condominio->id)
                        ->where('principale', true)
                        ->first();

                    if (!$tabella) {
                        throw new \Exception('Nessuna tabella millesimale principale trovata per questo condominio');
                    }
                }

                // CORREZIONE: usa $nuovoConto->id invece di $conto->id
                $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                    'conto_id' => $nuovoConto->id, // ← CORRETTO
                    'tabella_id' => $tabella->id,
                    'coefficiente' => 100.00, // Coefficiente di default
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Prepara le ripartizioni per soggetto
                $ripartizioni = [
                    [
                        'soggetto' => 'proprietario',
                        'percentuale' => $data['percentuale_proprietario']
                    ],
                    [
                        'soggetto' => 'inquilino', 
                        'percentuale' => $data['percentuale_inquilino']
                    ],
                    [
                        'soggetto' => 'usufruttuario',
                        'percentuale' => $data['percentuale_usufruttuario']
                    ]
                ];

                // Verifica che la somma delle percentuali sia 100
                $sommaPercentuali = array_sum(array_column($ripartizioni, 'percentuale'));
                if ($sommaPercentuali != 100) {
                    throw new \Exception("La somma delle percentuali deve essere 100%. Attuale: {$sommaPercentuali}%");
                }

                // Crea le ripartizioni per ogni soggetto
                foreach ($ripartizioni as $ripartizione) {
                    if ($ripartizione['percentuale'] > 0) {
                        DB::table('conto_tabella_ripartizioni')->insert([
                            'conto_tabella_millesimale_id' => $contoTabellaId,
                            'soggetto' => $ripartizione['soggetto'],
                            'percentuale' => $ripartizione['percentuale'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return redirect()
                ->route('admin.gestionale.esercizi.conti.spese.index', [
                    'condominio' => $condominio->id,
                    'esercizio' => $esercizio->id,
                    'conto' => $conto->id
                ])
                ->with($this->flashSuccess('Voce di spesa creata con successo!'));

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Errore durante la creazione della voce di spesa:', [
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'conto_id' => $conto->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->back()
                ->with($this->flashError('Errore durante la creazione della voce di spesa: ' . $e->getMessage()))
                ->withInput();
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

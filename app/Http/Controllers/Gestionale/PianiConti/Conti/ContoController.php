<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\Conto\CreateContoRequest;
use App\Http\Requests\Gestionale\PianoConto\Conto\UpdateContoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContoController extends Controller
{
     use HandleFlashMessages;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(CreateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {

            DB::beginTransaction();

            $data = $request->validated();
            $isCapitolo = $data['isCapitolo'];
            $isSottoConto = $data['isSottoConto'];
                
            // Prepara i dati per la creazione
            $contoData = [
                'piano_conto_id' => $pianoConto->id,
                'parent_id'      => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                'nome'           => $data['nome'],
                'descrizione'    => $data['descrizione'] ?? null,
                'tipo'           => $data['tipo'],
                'importo'        => $isCapitolo ? 0 : MoneyHelper::toCents($data['importo']), 
                'note'           => $data['note'] ?? null,
                'attivo'         => true,
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
                } 

                $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                    'conto_id'     => $nuovoConto->id, 
                    'tabella_id'   => $tabella->id,
                    'coefficiente' => 100.00, 
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

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
                            'soggetto'                     => $ripartizione['soggetto'],
                            'percentuale'                  => $ripartizione['percentuale'],
                            'created_at'                   => now(),
                            'updated_at'                   => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashSuccess(__('gestionale.success_create_conto')));

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Errore durante la creazione della voce di spesa:', [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'conto_id'      => $pianoConto->id,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString()
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashError(__('gestionale.error_create_conto')));

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
    public function update(UpdateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        try {
            DB::beginTransaction();

            $data = $request->validated();
            $isCapitolo = $data['isCapitolo'];
            $isSottoConto = $data['isSottoConto'];
                
            // Prepara i dati per l'aggiornamento
            $contoData = [
                'parent_id'      => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                'nome'           => $data['nome'],
                'descrizione'    => $data['descrizione'] ?? null,
                'tipo'           => $data['tipo'],
                'importo'        => $isCapitolo ? 0 : MoneyHelper::toCents($data['importo']), 
                'note'           => $data['note'] ?? null,
            ];

            // Aggiorna il conto
            $conto->update($contoData);

            // Gestione tabelle millesimali e ripartizioni
            if (!$isCapitolo && !empty($data['tabella_millesimale_id'])) {
                $this->aggiornaAssociazioneTabella($conto, $condominio, $data);
            } else {
                // Se è un capitolo o non ha tabella, rimuovi le associazioni esistenti
                $this->rimuoviAssociazioniTabella($conto);
            }

            DB::commit();

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashSuccess(__('gestionale.success_update_conto')));

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Errore durante l\'aggiornamento della voce di spesa:', [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'conto_id'      => $conto->id,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString()
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id
                ])
                ->with($this->flashError(__('gestionale.error_update_conto')));
        }
    }

    /**
     * Delete a specific expense from the chart of expenses.
     *
     * This method handles the deletion of an expense from the chart of epenses.
     * It performs the following operations:
     * - Checks if the expense has sub-expenses (sottoconti) and prevents deletion if any exist
     * - Deletes related records from the conto_tabella_millesimale pivot table
     * - Deletes the account record
     * - Handles success and error responses with appropriate flash messages
     * - Implements database transaction for data consistency
     *
     * @param \App\Models\Condominio $condominio The condominium entity
     * @param \App\Models\Esercizio $esercizio The fiscal year/exercise entity
     * @param \App\Models\PianoConto $pianoConto The chart of expenses entity
     * @param \App\Models\Conto $conto The expense to be deleted
     * 
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Exception If an error occurs during the deletion process
     * 
     * @warning The account cannot be deleted if it has sub-accounts
     * 
     * @uses \Illuminate\Support\Facades\DB
     * @uses \Illuminate\Support\Facades\Log
     * @uses \App\Traits\HandleFlashMessages
     * 
     * @transaction
     * The method uses database transactions to ensure data consistency
     * when deleting related records from conto_tabella_millesimale table
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {

        if ($conto->sottoconti()->exists()) {

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio' => $esercizio->id,
                    'pianoConto' => $pianoConto->id,
                ])
                ->with($this->flashError(__('gestionale.error_conto_has_sottoconti')));
        }

        try {

            DB::beginTransaction();

            DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->delete();

            $conto->delete();

            DB::commit();

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id,
                ])
                ->with($this->flashSuccess(__('gestionale.success_delete_conto')));

        } catch (\Exception $e) {

            DB::rollBack();
            
            Log::error("Errore durante l'eliminazione della voce di spesa:", [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'pianoConto'    => $pianoConto->id,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString()
            ]);
            
            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoConto' => $pianoConto->id,
                ])
                ->with($this->flashError(__('gestionale.error_delete_conto')));
        }
    }

    /**
     * Aggiorna l'associazione con la tabella millesimale e le ripartizioni
     */
    private function aggiornaAssociazioneTabella(Conto $conto, Condominio $condominio, array $data): void
    {
        // Prima rimuovi le associazioni esistenti
        $this->rimuoviAssociazioniTabella($conto);

        // Trova la tabella selezionata
        $tabella = Tabella::where('id', $data['tabella_millesimale_id'])
            ->where('condominio_id', $condominio->id)
            ->first();
        
        if (!$tabella) {
            throw new \Exception('Tabella millesimale selezionata non trovata');
        }

        // Crea nuova associazione con la tabella
        $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id'     => $conto->id, 
            'tabella_id'   => $tabella->id,
            'coefficiente' => 100.00, 
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Prepara e inserisci le ripartizioni
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

        $ripartizioniData = [];
        foreach ($ripartizioni as $ripartizione) {
            if ($ripartizione['percentuale'] > 0) {
                $ripartizioniData[] = [
                    'conto_tabella_millesimale_id' => $contoTabellaId,
                    'soggetto'                     => $ripartizione['soggetto'],
                    'percentuale'                  => $ripartizione['percentuale'],
                    'created_at'                   => now(),
                    'updated_at'                   => now(),
                ];
            }
        }

        // Inserisci tutte le ripartizioni in una sola query
        if (!empty($ripartizioniData)) {
            DB::table('conto_tabella_ripartizioni')->insert($ripartizioniData);
        }
    }

    /**
     * Rimuove tutte le associazioni con le tabelle millesimali per un conto
     */
    private function rimuoviAssociazioniTabella(Conto $conto): void
    {
        // Trova tutti i record in conto_tabella_millesimale per questo conto
        $contoTabellaIds = DB::table('conto_tabella_millesimale')
            ->where('conto_id', $conto->id)
            ->pluck('id');

        // Se ci sono associazioni, rimuovi prima le ripartizioni e poi le associazioni
        if ($contoTabellaIds->isNotEmpty()) {
            // Rimuovi tutte le ripartizioni in una sola query
            DB::table('conto_tabella_ripartizioni')
                ->whereIn('conto_tabella_millesimale_id', $contoTabellaIds)
                ->delete();
            
            // Rimuovi tutte le associazioni in una sola query
            DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->delete();
        }
    }

}

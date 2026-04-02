<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Evento;      
use App\Models\Anagrafica;   
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetCoverageService;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function __invoke(Condominio $condominio, BudgetCoverageService $coverageService): Response
    {
        $esercizio = $this->getEsercizioCorrente($condominio);
        $copertura = null;
        $pianiDisallineati = [];

        if ($esercizio) {
            $esercizio->load('gestioni');
            $totPrev = 0; 
            $totPian = 0; 
            $totVirtualeGlobale = 0;
            $totBudgetPuro = 0;
            $vociScoperte = [];

            // --- 1. Recupero Fatturato Reale dell'esercizio ---
            $rawFatturato = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->select(
                    'righe_fattura.conto_id', 
                    DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale')
                )
                ->groupBy('righe_fattura.conto_id')
                ->get();

            $fatturatoMap = [];
            foreach ($rawFatturato as $row) {
                $fatturatoMap[(int)$row->conto_id] = (int)$row->totale;
            }

            // --- 2. Recupero di TUTTE le strategie di sforo ---
            $fattureSforo = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                ->select(
                    'righe_fattura.conto_id', 
                    'fatture_passive.dati_extra', 
                    'righe_fattura.importo_imponibile', 
                    'righe_fattura.importo_iva'
                )
                ->get();

            $coperturaVirtualeMap = [];
            $strategieMap = [];

            foreach ($fattureSforo as $row) {
                $datiExtra = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
                $strat = $datiExtra['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
                $importoRiga = abs((int)$row->importo_imponibile + (int)$row->importo_iva);

                // Solo A (Conguaglio) e C (Fondo Riserva) coprono virtualmente
                if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                    $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + $importoRiga;
                }
                
                // Mappiamo la strategia per l'etichetta in Modale
                $strategieMap[(int)$row->conto_id] = $strat;
            }

            foreach ($esercizio->gestioni as $gestione) {
                $report = $coverageService->analyze($gestione, $fatturatoMap, $coperturaVirtualeMap);

                if (($report['status'] ?? '') === 'empty') {
                    continue;
                }
                
                $fabbisognoRealeGestione = 0;
                $budgetPuroGestione = 0;
                $virtualeGestione = 0; 

                foreach ($report['items'] as $item) {
                    if (!($item['is_leaf'] ?? false)) continue; 
                    
                    $budgetTeorico = $item['budget'];
                    $spesoReale = $fatturatoMap[$item['id']] ?? 0;
                    
                    $fabbisognoRealeGestione += max($budgetTeorico, $spesoReale);
                    $budgetPuroGestione += $budgetTeorico;
                    $virtualeGestione += $item['copertura_virtuale'] ?? 0;
                }

                $totPrev += $fabbisognoRealeGestione;
                $totBudgetPuro += $budgetPuroGestione;
                $totPian += $report['totali']['pianificato'];
                $totVirtualeGlobale += $virtualeGestione; 

                // --- 3. Analisi Deficit Pura (Senza Push-Down / Travasi) ---
                foreach ($report['items'] as $item) {
                    if (!($item['is_leaf'] ?? false)) continue;

                    $fabbisognoReale = max($item['budget'], ($fatturatoMap[$item['id']] ?? 0));
                    $pianificato = (int) ($item['pianificato'] ?? 0);
                    
                    // Deficit netto e reale su questa singola voce rispetto a quanto richiesto a rate
                    $deficitRispettoRate = $fabbisognoReale - $pianificato;
                    
                    if ($deficitRispettoRate > 100) { 

                        $stratScelta = $strategieMap[$item['id']] ?? null;

                        $tipoStrategia = 'nessuna';
                        if ($stratScelta === 'rata_integrativa') {
                            $tipoStrategia = 'rata_integrativa'; // Caso B (Emetti rate)
                        } elseif ($stratScelta === 'fondo_riserva') {
                            $tipoStrategia = 'fondo_riserva';    // Caso C (Coperto da Fondo)
                        } elseif ($stratScelta === 'conguaglio_fine_anno') {
                            $tipoStrategia = 'conguaglio';       // Caso A (A consuntivo)
                        }

                        // Aggiungiamo la voce agli orfani
                        $vociScoperte[] = [
                            'id'         => $item['id'],
                            'nome'       => $item['nome'],
                            'importo'    => $deficitRispettoRate, 
                            'gestione'   => $gestione->nome,
                            'is_sforo'   => isset($fatturatoMap[$item['id']]) && $fatturatoMap[$item['id']] > $item['budget'],
                            'strategia'  => $tipoStrategia 
                        ];

                    }
                }

                // ... [Logica Piani Disallineati Invariata] ...
                $pianiRate = PianoRate::where('gestione_id', $gestione->id)
                    ->with(['capitoli' => function($q) {
                        $q->select('conti.id', 'conti.importo');
                    }, 'rate.rateQuote']) 
                    ->get();
                
                foreach ($pianiRate as $piano) {
                    if ($piano->rate->count() > 0) {
                        $totalePuroGenerato = 0;
                        foreach ($piano->rate as $rata) {
                            foreach ($rata->rateQuote as $quota) {
                                $regole = $quota->regole_calcolo;
                                if (is_array($regole) && isset($regole['importi']['quota_pura_gestione'])) {
                                    $totalePuroGenerato += $regole['importi']['quota_pura_gestione'];
                                } else {
                                    if ($rata->numero_rata !== 0 && $quota->tipo !== 'saldo_iniziale') {
                                        $totalePuroGenerato += $quota->importo;
                                    }
                                }
                            }
                        }

                        $totaleAtteso = 0;
                        foreach ($piano->capitoli as $capitolo) {
                            $totaleAtteso += $capitolo->pivot->importo ?? $capitolo->importo;
                        }

                        if ($totaleAtteso !== $totalePuroGenerato) {
                            $pianiDisallineati[] = [
                                'id' => $piano->id,
                                'nome' => $piano->nome,
                                'gestione' => $gestione->nome,
                                'delta' => $totaleAtteso - $totalePuroGenerato
                            ];
                        }
                    }
                }
            }

            // Calcolo coperture globali
            $delta = $totPrev - ($totPian + $totVirtualeGlobale);
            $isBilanciato = abs($delta) <= 500; 

            $copertura = [
                'preventivo'     => $totPrev, 
                'pianificato'    => $totPian, 
                'virtuale'       => $totVirtualeGlobale, 
                'delta'          => $delta,
                'scoperto'       => ($delta > 0 ? $delta : 0),
                'percentuale'    => $totPrev > 0 ? round((($totPian + $totVirtualeGlobale) / $totPrev) * 100, 1) : 0,
                'is_completo'    => $isBilanciato,
                'orfani'         => $vociScoperte, 
                'scoperto_count' => collect($vociScoperte)
                    ->whereNotIn('strategia', ['conguaglio', 'fondo_riserva']) 
                    ->count(),
                'has_sforo'      => $totPrev > $totBudgetPuro
            ];
        }

        $inboxTasks = Evento::query()
            ->with(['anagrafiche:id,nome'])
            ->whereJsonContains('meta->requires_action', true)
            ->where('is_completed', false)
            ->whereHas('condomini', function ($query) use ($condominio) {
                $query->where('condomini.id', $condominio->id);
            })
            ->orderBy('start_time', 'asc')
            ->paginate(10)
            ->through(function ($task) {
                $nomeAnagrafica = $task->anagrafiche->first()?->nome;

                if (!$nomeAnagrafica && !empty($task->meta['context']['anagrafica_id'])) {
                    $anagraficaModel = Anagrafica::find($task->meta['context']['anagrafica_id']);
                    $nomeAnagrafica = $anagraficaModel?->nome;
                }

                return [
                    'id'           => $task->id,
                    'title'        => $task->title,
                    'description'  => $task->description, 
                    'date'         => $task->start_time->toISOString(),
                    'type'         => $task->meta['type'] ?? 'generic',
                    'action_url'   => $task->meta['action_url'] ?? null,
                    'status'       => $task->start_time->isPast() ? 'expired' : 'scheduled',
                    'context'      => [
                        'anagrafica_nome' => $nomeAnagrafica, 
                    ],
                ];
            });

        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio' => $condominio, 
            'condomini'  => $this->getCondomini(),
            'esercizio'  => $esercizio, 
            'esercizi'   => $condominio->esercizi, 
            'copertura'  => $copertura,
            'pianiDisallineati' => $pianiDisallineati,
            'inboxTasks' => Inertia::scroll($inboxTasks)
        ]);
    }
}
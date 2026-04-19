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
        $fattureScoperte = [];

        if ($esercizio) {
            $esercizio->load('gestioni');
            $totPrev = 0; 
            $totPian = 0; 
            $totVirtualeGlobale = 0;
            $totBudgetPuro = 0;
            $vociScoperte = [];

            // --- 1. Recupero Fatturato Reale dell'esercizio (SOLO SPESE COMUNI) ---
            $rawFatturato = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->whereNull('righe_fattura.immobile_id') // Esclude le spese ad personam
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

            // --- NUOVO: Recupero spese ad personam totali ---
            // Nelle righe_fattura il false in MySQL spesso è salvato come 0.
            $addebitiPersonali = (int) DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->whereNotNull('righe_fattura.immobile_id') // Solo le spese private
                ->where(function($q) {
                    $q->where('righe_fattura.is_rateizzata', false)
                      ->orWhere('righe_fattura.is_rateizzata', 0); // Copertura totale MySQL/Sqlite
                })
                ->sum(DB::raw('righe_fattura.importo_imponibile + righe_fattura.importo_iva'));

            // --- STEP 7: LISTA FATTURE SCOPERTE (Sopravvenienze + Ad Personam) ---
            // Ottimizziamo precaricando SOLO le righe che ci interessano, facendo fare tutto a SQL.
            $fattureScoperteRaw = \App\Models\Gestionale\FatturaPassiva::with(['fornitore', 'righe' => function ($q) {
                    $q->with(['immobile', 'conto'])
                      ->where(function($sq) {
                          $sq->where('is_rateizzata', false)
                             ->orWhere('is_rateizzata', 0);
                      })
                      ->where(function ($inner) {
                          $inner->where('is_sopravvenienza', true)
                                ->orWhereNotNull('immobile_id');
                      });
                }])
                ->where('esercizio_id', $esercizio->id)
                ->where('stato_approvazione', '!=', 'contestata')
                ->whereHas('righe', function ($q) {
                    $q->where(function($sq) {
                          $sq->where('is_rateizzata', false)
                             ->orWhere('is_rateizzata', 0);
                      })
                      ->where(function ($inner) {
                          $inner->where('is_sopravvenienza', true)
                                ->orWhereNotNull('immobile_id');
                      });
                })
                ->get();

            // Ricaviamo l'ID della gestione attiva per passarlo al deep-link
            $gestionePrincipaleId = $esercizio->gestioni->where('attiva', true)->first()?->id;

            foreach ($fattureScoperteRaw as $fattura) {
                // Siccome abbiamo filtrato la eager loading ('righe' => function(...)), 
                // ora l'array $fattura->righe contiene GIA' SOLO E SOLTANTO le righe giuste. Non serve ri-filtrarle in PHP.
                
                $totaleFatturaCents = 0;
                $righeMappate = [];

                foreach ($fattura->righe as $riga) {
                    $importoRigaCents = (int) round(($riga->importo_imponibile + $riga->importo_iva));
                    $totaleFatturaCents += $importoRigaCents;

                    $righeMappate[] = [
                        'id' => $riga->id,
                        'tipo' => $riga->immobile_id ? 'ad_personam' : 'comune',
                        'importo' => $importoRigaCents, // Centesimi esatti
                        'descrizione' => $riga->immobile_id 
                            ? "Addebito personale (Art. 63)" 
                            : "Parte comune (millesimale)",
                        'dettaglio' => $riga->immobile_id
                            ? "Int. {$riga->immobile->interno}"
                            : ($riga->conto ? "Voce: {$riga->conto->nome}" : "Imprevisto")
                    ];
                }

                if ($totaleFatturaCents > 0) {
                    $fattureScoperte[] = [
                        'id' => $fattura->id,
                        'numero' => $fattura->numero_documento,
                        'fornitore' => $fattura->fornitore->ragione_sociale ?? 'Sconosciuto',
                        'totale_scoperto' => $totaleFatturaCents,
                        'gestione_id' => $gestionePrincipaleId,
                        'righe' => $righeMappate,
                    ];
                }
            }
            // ----------------------------------------------------------------------

            // --- 2. Recupero di TUTTE le strategie di sforo (SOLO SPESE COMUNI) ---
            $fattureSforo = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                ->whereNull('righe_fattura.immobile_id') // Esclude le spese ad personam
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

                if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                    $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + $importoRiga;
                }
                
                if (!isset($strategieMap[(int)$row->conto_id]) || $strategieMap[(int)$row->conto_id] !== 'rata_integrativa') {
                    $strategieMap[(int)$row->conto_id] = $strat;
                }
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

                // --- 3. Analisi Deficit Pura ---
                foreach ($report['items'] as $item) {
                    if (!($item['is_leaf'] ?? false)) continue;

                    $fabbisognoReale = max($item['budget'], ($fatturatoMap[$item['id']] ?? 0));
                    $pianificato = (int) ($item['pianificato'] ?? 0);
                    $deficitRispettoRate = $fabbisognoReale - $pianificato;
                    
                    if ($deficitRispettoRate > 100) { 

                        $stratScelta = $strategieMap[$item['id']] ?? null;

                        $tipoStrategia = 'nessuna';
                        if ($stratScelta === 'rata_integrativa') {
                            $tipoStrategia = 'rata_integrativa'; 
                        } elseif ($stratScelta === 'fondo_riserva') {
                            $tipoStrategia = 'fondo_riserva';    
                        } elseif ($stratScelta === 'conguaglio_fine_anno') {
                            $tipoStrategia = 'conguaglio';       
                        }

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

                $pianiRate = PianoRate::where('gestione_id', $gestione->id)
                    ->with([
                        'capitoli' => function($q) {
                            $q->select('conti.id', 'conti.importo');
                        }, 
                        'fattureStraordinarie', 
                        'rate.rateQuote'
                    ]) 
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
                        if ($piano->tipo === 'straordinario') {
                            foreach ($piano->fattureStraordinarie as $fattura) {
                                $totaleAtteso += $fattura->pivot->importo_collegato;
                            }
                        } else {
                            foreach ($piano->capitoli as $capitolo) {
                                $totaleAtteso += $capitolo->pivot->importo ?? $capitolo->importo;
                            }
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

            // 1. Calcolo Fatturato Reale Condiviso
            $totFatturatoCondiviso = array_sum($fatturatoMap);

            // 2. Il delta è purissimo (Totale Fabbisogno - Rate Pianificate)
            $deltaReale = $totPrev - ($totPian + $totVirtualeGlobale);

            $copertura = [
                'preventivo'         => $totPrev, 
                'pianificato'        => $totPian, 
                'virtuale'           => $totVirtualeGlobale, 
                'addebiti_personali' => $addebitiPersonali,
                
                // Uscite totali verso i fornitori (vero debito di oggi!)
                'uscite_totali'      => $totFatturatoCondiviso + $addebitiPersonali, 
                
                'delta'              => $deltaReale,
                'scoperto'           => max(0, $deltaReale),
                'percentuale'        => $totPrev > 0 
                                            ? round((($totPian + $totVirtualeGlobale) / $totPrev) * 100, 1) 
                                            : 0,
                'is_completo'        => abs($deltaReale) <= 500,
                'has_sforo'          => $totPrev > $totBudgetPuro,
                'orfani'             => $vociScoperte,
                'scoperto_count'     => collect($vociScoperte)
                                            ->whereNotIn('strategia', ['conguaglio', 'fondo_riserva'])
                                            ->count(),
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
            'condominio'        => $condominio, 
            'condomini'         => $this->getCondomini(),
            'esercizio'         => $esercizio, 
            'esercizi'          => $condominio->esercizi, 
            'copertura'         => $copertura,
            'fattureScoperte'   => $fattureScoperte,
            'pianiDisallineati' => $pianiDisallineati,
            'inboxTasks'        => Inertia::scroll($inboxTasks)
        ]);
    }
}
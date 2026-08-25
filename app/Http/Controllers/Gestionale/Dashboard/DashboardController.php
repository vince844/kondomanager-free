<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Evento;      
use App\Models\Anagrafica;   
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\PianoRateQuoteService;
use App\Services\Dashboard\WidgetManager;
use App\Services\Dashboard\Widgets\TreasuryGuardianWidget;
use App\Services\Dashboard\Widgets\ControlliPostImportWidget;
use App\Services\Dashboard\Widgets\CreditiDaCompensareWidget;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function __invoke(Condominio $condominio, BudgetCoverageService $coverageService, SpesaPerVoceService $spesaPerVoce, WidgetManager $widgetManager, TreasuryGuardianWidget $treasuryWidget, CreditiDaCompensareWidget $creditiWidget, ControlliPostImportWidget $controlliWidget, PianoRateQuoteService $pianoRateQuoteService): Response
    {
        $widgetManager->registerMany([$treasuryWidget, $creditiWidget, $controlliWidget]);

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

            // --- 1. Speso reale dell'esercizio (SOLO SPESE COMUNI) ---
            // Letto dal libro giornale: include anche regolazioni immediate e
            // fatture pregresse, invisibili alla vecchia query su righe_fattura.
            $fatturatoMap = $spesaPerVoce->perEsercizio($esercizio);

            // --- NUOVO: Recupero spese ad personam totali ---
            // Nelle righe_fattura il false in MySQL spesso è salvato come 0.
            $addebitiPersonali = (int) DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->whereNotNull('righe_fattura.immobile_id') // Solo le spese private
                ->where(function($q) {
                    $q->where('righe_fattura.is_rateizzata', false)
                      ->orWhere('righe_fattura.is_rateizzata', 0)
                      ->orWhereNull('righe_fattura.is_rateizzata'); 
                })
                ->sum(DB::raw('righe_fattura.importo_imponibile + righe_fattura.importo_iva'));

            // --- STEP 7: LISTA FATTURE SCOPERTE (Sopravvenienze + Ad Personam) ---
            $fattureScoperteRaw = FatturaPassiva::with([
                    'fornitore', 
                    'coperture',
                    'righe' => function ($q) {
                        $q->with(['immobile', 'conto'])
                        ->where(function($sq) {
                            $sq->where('is_rateizzata', false)
                                ->orWhere('is_rateizzata', 0)
                                ->orWhereNull('is_rateizzata'); // FIX NULL
                        })
                        ->where(function ($inner) {
                            $inner->where('is_sopravvenienza', true)
                                    ->orWhereNotNull('immobile_id');
                        });
                    }
                ])
                ->where('esercizio_id', $esercizio->id)
                ->where('stato_approvazione', '!=', 'contestata')
                ->where('stato_pagamento', '!=', 'stornata')
                ->where(function ($query) {
                    $query->whereHas('righe', function ($q) {
                        $q->where(function($sq) {
                            $sq->where('is_rateizzata', false)
                                ->orWhere('is_rateizzata', 0)
                                ->orWhereNull('is_rateizzata'); // FIX NULL
                        })
                        ->where(function ($inner) {
                            $inner->where('is_sopravvenienza', true)
                                    ->orWhereNotNull('immobile_id');
                        });
                    })
                    ->orWhere(function ($qPregresso) {
                        $qPregresso->where('is_pregresso', 1)
                                ->whereHas('coperture', function ($qCop) {
                                    $qCop->where('tipo_copertura', 'sopravvenienza');
                                });
                    });
                })
                ->get();

            $gestionePrincipaleId = $esercizio->gestioni->where('attiva', true)->first()?->id;

            foreach ($fattureScoperteRaw as $fattura) {
                $datiExtra = is_string($fattura->dati_extra) 
                    ? json_decode($fattura->dati_extra, true) 
                    : (array) $fattura->dati_extra;
                
                $strategia = $datiExtra['override_budget']['strategia_rientro'] ?? 
                            $datiExtra['log_legale_sopravvenienza']['strategia_rientro'] ?? 
                            'nessuna';

                $totaleFatturaCents = 0;
                $righeMappate = [];

                foreach ($fattura->righe as $riga) {
                    $importoRigaCents = (int) round(($riga->importo_imponibile + $riga->importo_iva));
                    $totaleFatturaCents += $importoRigaCents;

                    $righeMappate[] = [
                        'id'          => 'r_' . $riga->id,
                        'tipo'        => $riga->immobile_id ? 'ad_personam' : 'comune',
                        'importo'     => $importoRigaCents, 
                        'descrizione' => $riga->immobile_id 
                            ? "Addebito personale (Art. 63)" 
                            : "Parte comune (millesimale)",
                        'dettaglio'   => $riga->immobile_id
                            // `etichetta`: ripiega sul nome quando l'interno non c'è (beta.58).
                    ? $riga->immobile->etichetta
                            : ($riga->conto ? "Voce: {$riga->conto->nome}" : "Imprevisto")
                    ];
                }

                // Righe virtuali per sopravvenienze pregresse
                if ($fattura->is_pregresso && $fattura->coperture->isNotEmpty()) {
                    foreach ($fattura->coperture as $copertura) {
                        if ($copertura->tipo_copertura === 'sopravvenienza') {
                            $importoCopCents = (int) $copertura->importo;
                            $totaleFatturaCents += $importoCopCents;

                            $righeMappate[] = [
                                'id'          => 'c_' . $copertura->id,
                                'tipo'        => 'comune',
                                'importo'     => $importoCopCents,
                                'descrizione' => "Integrazione Debito Storico",
                                'dettaglio'   => "Eccedenza da fattura anno precedente"
                            ];
                        }
                    }
                }

                if ($totaleFatturaCents > 0) {
                    $fattureScoperte[] = [
                        'id'             => $fattura->id,
                        'numero'         => $fattura->numero_documento,
                        'fornitore'      => $fattura->fornitore->ragione_sociale ?? 'Sconosciuto',
                        'totale_scoperto' => $totaleFatturaCents,
                        'gestione_id'    => $gestionePrincipaleId,
                        'strategia'      => $strategia,
                        'righe'          => $righeMappate,
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

                    $budgetTeorico = $item['budget_teorico'];
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
                            'id'          => $item['id'],
                            'nome'        => $item['nome'],
                            'importo'     => $deficitRispettoRate, 
                            'gestione'    => $gestione->nome,
                            'gestione_id' => $gestione->id,
                            'is_sforo'    => isset($fatturatoMap[$item['id']]) && $fatturatoMap[$item['id']] > $item['budget_teorico'],
                            'strategia'   => $tipoStrategia 
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
                
                // Il verdetto vive in `PianoRateQuoteService` dall'11/08/2026, e non più qui:
                // la stessa domanda la fa anche la pagina del piano, e finché ognuna se la
                // calcolava per conto proprio le due rispondevano in modo diverso — metodi
                // diversi e tolleranze diverse (zero qui, € 2,00 di là). Vedi `eDisallineato()`.
                foreach ($pianiRate as $piano) {
                    if ($pianoRateQuoteService->eDisallineato($piano)) {
                        $pianiDisallineati[] = [
                            'id' => $piano->id,
                            'nome' => $piano->nome,
                            'gestione' => $gestione->nome,
                            'delta' => $pianoRateQuoteService->totaleAttesoCents($piano)
                                     - $pianoRateQuoteService->totalePuroGeneratoCents($piano),
                        ];
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
                // True se nell'esercizio esiste almeno un piano rate con quota orfana
                // documentata dall'admin (nota_scoperti non nulla). Usato dal widget
                // per mostrare lo stato "QUOTA APERTA" invece del generico allarme.
                'has_scoperti_documentati' => $esercizio
                    ? PianoRate::whereIn('gestione_id', $esercizio->gestioni->pluck('id'))
                        ->whereNotNull('nota_scoperti')
                        ->exists()
                    : false,
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
                    'type'         => $task->tipo?->value ?? $task->meta['type'] ?? 'generic',
                    'action_url'   => $task->meta['action_url'] ?? null,
                    'status'       => $task->start_time->isPast() ? 'expired' : 'scheduled',
                    'days_pending' => $task->start_time->isPast() ? (int) floor(now()->diffInDays($task->start_time)) : 0,
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
            'widgets'           => $widgetManager->getPayloads($condominio->id),
            'inboxTasks'        => Inertia::scroll($inboxTasks)
        ]);
    }
}
<?php

namespace App\Http\Controllers\Gestionale\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BudgetCoverageService;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function __invoke(Condominio $condominio, BudgetCoverageService $coverageService): Response
    {
        $esercizio = $this->getEsercizioCorrente($condominio);
        $copertura = null;

        if ($esercizio) {
            $esercizio->load('gestioni');
            $totPrev = 0; 
            $totPian = 0; 
            $vociScoperte = [];

            foreach ($esercizio->gestioni as $gestione) {
                $report = $coverageService->analyze($gestione);
                $totPrev += $report['totali']['budget'];
                $totPian += $report['totali']['pianificato'];

                $idsCoinvolti = array_column($report['items'], 'id');
                
                // Mappa sicura delle parentela da DB
                $mappaGenitori = Conto::whereIn('id', $idsCoinvolti)
                    ->whereNotNull('parent_id')
                    ->pluck('parent_id', 'id')
                    ->toArray();

                // 1. RIEMPIMENTO PORTAFOGLI (SURPLUS)
                $walletPadri = [];
                foreach ($report['items'] as $item) {
                    // Surplus = Soldi Pianificati - Costo Preventivato
                    $surplus = $item['pianificato'] - $item['budget'];
                    if ($surplus > 0) {
                        $walletPadri[$item['id']] = $surplus;
                    }
                }

                // 2. ANALISI DEFICIT E COPERTURA (PUSH-DOWN)
                foreach ($report['items'] as $item) {
                    if (!($item['is_leaf'] ?? false)) continue;

                    $deficit = $item['budget'] - $item['pianificato'];
                    
                    if ($deficit > 100) { 
                        // Cerchiamo il padre
                        $parentId = $mappaGenitori[$item['id']] ?? null;
                        
                        // Se il padre ha soldi nel portafoglio
                        if ($parentId && isset($walletPadri[$parentId]) && $walletPadri[$parentId] > 0) {
                            $disponibile = $walletPadri[$parentId];
                            $coperto = min($deficit, $disponibile);
                            
                            $deficit -= $coperto;
                            $walletPadri[$parentId] -= $coperto;
                        }

                        // Se è ancora scoperto, allora è un vero orfano
                        if ($deficit > 100) {
                            $vociScoperte[] = [
                                'id'       => $item['id'],
                                'nome'     => $item['nome'],
                                'importo'  => $deficit, 
                                'gestione' => $gestione->nome
                            ];
                        }
                    }
                }
            }

            $delta = $totPrev - $totPian;
            $isBilanciato = abs($delta) <= 500; 

            $copertura = [
                'preventivo'     => $totPrev, 
                'pianificato'    => $totPian, 
                'delta'          => $delta,
                'scoperto'       => ($delta > 0 ? $delta : 0),
                'percentuale'    => $totPrev > 0 ? round(($totPian / $totPrev) * 100) : 0,
                'is_completo'    => $isBilanciato,
                'orfani'         => $vociScoperte, 
                'scoperto_count' => count($vociScoperte)
            ];
        }

        return Inertia::render('gestionale/dashboard/Dashboard', [
            'condominio' => $condominio, 
            'condomini' => $this->getCondomini(),
            'esercizio' => $esercizio, 
            'copertura' => $copertura
        ]);
    }
}
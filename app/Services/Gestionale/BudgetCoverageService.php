<?php

namespace App\Services\Gestionale;

use App\Models\Gestione;
use Illuminate\Support\Collection;

class BudgetCoverageService
{
    public function analyze(Gestione $gestione, array $fatturatoMap = [], array $coperturaVirtualeMap = []): array
    {
        $gestione->load(['pianoConto.conti.sottoconti', 'pianiRate.capitoli']);
        
        if (!$gestione->pianoConto) {
            return [
                'status' => 'empty', 
                'items'  => [],
                'totali' => ['budget' => 0, 'pianificato' => 0]
            ];
        }

        $contiRadice     = $gestione->pianoConto->conti->whereNull('parent_id');
        $pianiRateAttivi = $gestione->pianiRate->where('attivo', true);
        
        // Calcolo della copertura Reale con Push Down bilanciato
        $coperturaRealeMap = $this->calcolaCoperturaReale($contiRadice, $pianiRateAttivi);

        $report = [];
        
        foreach ($this->appiattisciConti($contiRadice) as $conto) {
            $isLeaf = $conto->sottoconti->isEmpty();
            
            $budgetTeorico   = (int) $conto->importo;
            $spesoReale      = $fatturatoMap[$conto->id] ?? 0;
            $fabbisognoReale = max($budgetTeorico, $spesoReale);

            $pianificato = (int) ($coperturaRealeMap[$conto->id] ?? 0);
            
            // Limitiamo il virtuale a quanto effettivamente manca
            $virtuale = min((int) ($coperturaVirtualeMap[$conto->id] ?? 0), max(0, $fabbisognoReale - $pianificato));
            
            $delta = ($pianificato + $virtuale) - $fabbisognoReale;

            $status   = 'ok';
            $severity = 'success';
            $message  = 'Copertura perfetta.';

            if ($delta < -100) {
                $status   = 'deficit';
                $severity = 'danger';
                $percent  = $fabbisognoReale > 0 ? round(($pianificato / $fabbisognoReale) * 100) : 0;
                $message  = "Deficit: coperto al {$percent}%.";
            } elseif ($delta > 100) {
                $status   = 'surplus';
                $severity = 'warning';
                $extra    = number_format($delta / 100, 2, ',', '.');
                $message  = "Surplus di € {$extra}.";
            }

            $report[] = [
                'id'                 => $conto->id,
                'parent_id'          => $conto->parent_id,
                'nome'               => $conto->nome,
                'padre'              => $conto->parent?->nome,
                'is_leaf'            => $isLeaf,
                'budget'             => $fabbisognoReale,
                'pianificato'        => $pianificato,
                'copertura_virtuale' => $virtuale, 
                'delta'              => $delta,
                'status'             => $status,
                'severity'           => $severity,
                'message'            => $message,
                'gestione'           => $gestione->nome,
                'piani_coinvolti'    => $this->trovaPianiCoinvolti($conto->id, $pianiRateAttivi),
            ];
        }

        $totBudget      = 0;
        $totPianificato = 0;
        
        foreach ($report as $r) {
            if ($r['is_leaf']) {
                $totBudget      += $r['budget'];
                $totPianificato += $r['pianificato'];
            }
        }

        return [
            'status' => 'analyzed',
            'items'  => $report,
            'totali' => ['budget' => $totBudget, 'pianificato' => $totPianificato],
        ];
    }

    private function calcolaCoperturaReale($contiRadice, $pianiRate): array
    {
        $map            = [];
        $tuttiContiFlat = $this->appiattisciConti($contiRadice);
        $contiById      = $tuttiContiFlat->keyBy('id');

        // STEP 1: ASSEGNAZIONE DIRETTA
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;

                if ($contoModel && $contoModel->sottoconti->isEmpty()) {
                    $impegnato = !is_null($capitolo->pivot->importo)
                        ? (int) $capitolo->pivot->importo
                        : (int) $capitolo->importo;

                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $impegnato;
                }
            }
        }

        // STEP 2: PUSH-DOWN DAI PADRI CON LIMITE BUDGET (Nessun furto)
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;

                if ($contoModel && $contoModel->sottoconti->isNotEmpty()) {

                    $residuoPiano = is_null($capitolo->pivot->importo)
                        ? (int) $capitolo->importo
                        : (int) $capitolo->pivot->importo;

                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $residuoPiano;

                    // Primo passaggio: cerchiamo di soddisfare il budget teorico di tutti i figli (senza sfori)
                    $figliDaSoddisfare = [];
                    foreach ($contoModel->sottoconti as $figlio) {
                        $budgetTeorico = (int) $figlio->importo;
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        
                        // Il deficit è calcolato SOLO sul budget teorico, ignora i fatturati per evitare furti
                        $deficit = $budgetTeorico - $copertoAttuale;

                        if ($deficit > 0) {
                            $figliDaSoddisfare[] = [
                                'id'      => $figlio->id,
                                'deficit' => $deficit,
                            ];
                        }
                    }

                    // Pushdown logico
                    if (!empty($figliDaSoddisfare) && $residuoPiano > 0) {
                        $figliFonte = $figliDaSoddisfare;

                        while ($residuoPiano > 0 && !empty($figliFonte)) {
                            $n = count($figliFonte);
                            $quotaBase = (int) floor($residuoPiano / $n);

                            if ($quotaBase === 0) {
                                foreach ($figliFonte as $f) {
                                    if ($residuoPiano <= 0) break;
                                    $map[$f['id']] = ($map[$f['id']] ?? 0) + 1;
                                    $residuoPiano -= 1;
                                }
                                break; 
                            }

                            $nuoviFigli = [];
                            foreach ($figliFonte as $f) {
                                $daAssegnare = min($f['deficit'], $quotaBase);

                                $map[$f['id']]  = ($map[$f['id']] ?? 0) + $daAssegnare;
                                $residuoPiano  -= $daAssegnare;
                                $f['deficit']  -= $daAssegnare;

                                if ($f['deficit'] > 0) {
                                    $nuoviFigli[] = $f;
                                }
                            }
                            $figliFonte = $nuoviFigli;
                        }
                    }
                }
            }
        }

        return $map;
    }

    private function appiattisciConti($conti)
    {
        $flat = collect();
        foreach ($conti as $c) {
            $flat->push($c);
            if ($c->sottoconti->isNotEmpty()) {
                $flat = $flat->merge($this->appiattisciConti($c->sottoconti));
            }
        }
        return $flat;
    }

    private function trovaPianiCoinvolti($contoId, $piani)
    {
        $names = [];
        foreach ($piani as $p) {
            $coinvolto = $p->capitoli->contains(function ($c) use ($contoId) {
                return $c->id == $contoId || ($c->sottoconti && $c->sottoconti->contains('id', $contoId));
            });
            if ($coinvolto) {
                $names[] = $p->nome;
            }
        }
        return $names;
    }
}
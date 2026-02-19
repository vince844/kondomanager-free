<?php

namespace App\Services\Gestionale;

use App\Models\Gestione;
use Illuminate\Support\Collection;

class BudgetCoverageService
{
    public function analyze(Gestione $gestione): array
    {
        $gestione->load(['pianoConto.conti.sottoconti', 'pianiRate.capitoli']);
        
        if (!$gestione->pianoConto) {
            return ['status' => 'empty', 'items' => []];
        }

        $contiRadice     = $gestione->pianoConto->conti->whereNull('parent_id');
        $pianiRateAttivi = $gestione->pianiRate->where('attivo', true);
        
        $coperturaRealeMap = $this->calcolaCoperturaReale($contiRadice, $pianiRateAttivi);

        $report = [];
        
        foreach ($this->appiattisciConti($contiRadice) as $conto) {
            $isLeaf = $conto->sottoconti->isEmpty();
            
            $budget      = (int) $conto->importo;
            $pianificato = (int) ($coperturaRealeMap[$conto->id] ?? 0);
            $delta       = $pianificato - $budget;

            $status   = 'ok';
            $severity = 'success';
            $message  = 'Copertura perfetta.';

            if ($delta < -100) {
                $status   = 'deficit';
                $severity = 'danger';
                $percent  = $budget > 0 ? round(($pianificato / $budget) * 100) : 0;
                $message  = "Deficit: coperto al {$percent}%.";
            } elseif ($delta > 100) {
                $status   = 'surplus';
                $severity = 'warning';
                $extra    = number_format($delta / 100, 2, ',', '.');
                $message  = "Surplus di € {$extra}.";
            }

            $report[] = [
                'id'              => $conto->id,
                'parent_id'       => $conto->parent_id,
                'nome'            => $conto->nome,
                'padre'           => $conto->parent?->nome,
                'is_leaf'         => $isLeaf,
                'budget'          => $budget,
                'pianificato'     => $pianificato,
                'delta'           => $delta,
                'status'          => $status,
                'severity'        => $severity,
                'message'         => $message,
                'gestione'        => $gestione->nome,
                'piani_coinvolti' => $this->trovaPianiCoinvolti($conto->id, $pianiRateAttivi),
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

        // ----------------------------------------------------------------
        // STEP 1: ASSEGNAZIONE DIRETTA (foglie con importo esplicito)
        // Ogni riga pivot che punta a una FOGLIA viene sommata direttamente.
        // NULL su una foglia = copre tutto il preventivo della foglia.
        // ----------------------------------------------------------------
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

        // ----------------------------------------------------------------
        // STEP 2: PUSH-DOWN DAI PADRI (distribuzione equa tra figli con deficit)
        //
        // LOGICA:
        // Il fondo del padre viene diviso IN PARTI UGUALI tra i figli che
        // hanno ancora un deficit dopo lo STEP 1.
        // Ogni figlio prende: min(suo_deficit, quota_uguale)
        // Se un figlio aveva deficit minore della quota, il suo surplus
        // viene redistribuito agli altri figli ancora in deficit.
        //
        // Esempio con i tuoi dati reali:
        //   Piano B → Capitolo padre (conto 97): importo pivot = 20.000¢
        //   Compenso: deficit 20.000¢ | Pulizia: deficit 10.000¢
        //   Quota uguale = 20.000 / 2 = 10.000¢ ciascuno
        //   Compenso prende: min(20.000, 10.000) = 10.000¢ → totale 20.000¢ (200€) ✅
        //   Pulizia prende:  min(10.000, 10.000) = 10.000¢ → totale 52.300¢ (523€) ✅
        // ----------------------------------------------------------------
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;

                if ($contoModel && $contoModel->sottoconti->isNotEmpty()) {

                    // Importo disponibile dal padre per il push-down
                    if (is_null($capitolo->pivot->importo)) {
                        $residuoPiano = PHP_INT_MAX; // copre tutto il fabbisogno dei figli
                    } else {
                        $residuoPiano = (int) $capitolo->pivot->importo;
                    }

                    // Salva il valore reale del padre nella mappa (non PHP_INT_MAX)
                    $importoRealeDelPadre = is_null($capitolo->pivot->importo)
                        ? (int) $capitolo->importo
                        : (int) $capitolo->pivot->importo;

                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $importoRealeDelPadre;

                    // Calcola i deficit attuali di tutti i figli
                    $figliConDeficit = [];
                    foreach ($contoModel->sottoconti as $figlio) {
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        $deficit        = (int) $figlio->importo - $copertoAttuale;

                        if ($deficit > 0) {
                            $figliConDeficit[] = [
                                'id'      => $figlio->id,
                                'deficit' => $deficit,
                            ];
                        }
                    }

                    if (empty($figliConDeficit) || $residuoPiano <= 0) {
                        continue;
                    }

                    // Distribuzione equa iterativa:
                    // Ripetiamo finché ci sono fondi e figli con deficit.
                    // Ad ogni round, dividiamo il residuo equamente tra i figli rimasti.
                    $figliFonte = $figliConDeficit; // copia modificabile

                    while ($residuoPiano > 0 && !empty($figliFonte)) {
                        $n         = count($figliFonte);
                        $quotaBase = (int) floor($residuoPiano / $n);

                        if ($quotaBase === 0) {
                            // Residuo troppo piccolo per dividere equamente:
                            // diamo 1 centesimo al primo figlio ancora in deficit
                            $map[$figliFonte[0]['id']] += 1;
                            $residuoPiano              -= 1;
                            $figliFonte[0]['deficit']  -= 1;
                            if ($figliFonte[0]['deficit'] <= 0) {
                                array_shift($figliFonte);
                            }
                            continue;
                        }

                        $nuoviFigli = [];
                        foreach ($figliFonte as $f) {
                            $daAssegnare = min($f['deficit'], $quotaBase);

                            $map[$f['id']]  = ($map[$f['id']] ?? 0) + $daAssegnare;
                            $residuoPiano  -= $daAssegnare;
                            $f['deficit']  -= $daAssegnare;

                            // Se ha ancora deficit, rimane nel prossimo round
                            if ($f['deficit'] > 0) {
                                $nuoviFigli[] = $f;
                            }
                        }

                        // Se nessun figlio ha consumato meno della quota (tutti saturi),
                        // usciamo per evitare loop infinito
                    /*     if (count($nuoviFigli) === count($figliFonte)) {
                            break;
                        } */

                        $figliFonte = $nuoviFigli;
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
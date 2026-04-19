<?php

namespace App\Services\Gestionale;

use App\Models\Gestione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetCoverageService
{
    public function analyze(Gestione $gestione, array $fatturatoMap = [], array $coperturaVirtualeMap = []): array
    {
        $gestione->load(['pianoConto.conti.sottoconti', 'pianiRate.capitoli', 'pianiRate.fattureStraordinarie']);
        
        if (!$gestione->pianoConto) {
            return [
                'status' => 'empty', 
                'items'  => [],
                'totali' => ['budget' => 0, 'pianificato' => 0]
            ];
        }

        $contiRadice = $gestione->pianoConto->conti->whereNull('parent_id');
        
        $pianiRateAttivi = $gestione->pianiRate->filter(function ($piano) {
            $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;
            return in_array($stato, ['bozza', 'approvato']);
        });
        
        $coperturaRealeMap = $this->calcolaCoperturaReale($contiRadice, $pianiRateAttivi);

        $report = [];
        
        foreach ($this->appiattisciConti($contiRadice) as $conto) {
            $isLeaf = $conto->sottoconti->isEmpty();
            
            $budgetTeorico   = (int) $conto->importo;
            $spesoReale      = $fatturatoMap[$conto->id] ?? 0;
            $fabbisognoReale = max($budgetTeorico, $spesoReale);

            $pianificato = (int) ($coperturaRealeMap[$conto->id] ?? 0);
            
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

        // STEP 1: PUSH-DOWN DAI PADRI CON LIMITE BUDGET
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;

                if ($contoModel && $contoModel->sottoconti->isNotEmpty()) {

                    $residuoPiano = is_null($capitolo->pivot->importo)
                        ? (int) $capitolo->importo
                        : (int) $capitolo->pivot->importo;

                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $residuoPiano;

                    $figliDaSoddisfare = [];
                    foreach ($contoModel->sottoconti as $figlio) {
                        $budgetTeorico  = (int) $figlio->importo;
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        $deficit        = $budgetTeorico - $copertoAttuale;

                        if ($deficit > 0) {
                            $figliDaSoddisfare[] = [
                                'id'      => $figlio->id,
                                'deficit' => $deficit,
                            ];
                        }
                    }

                    if (!empty($figliDaSoddisfare) && $residuoPiano > 0) {
                        $figliFonte = $figliDaSoddisfare;

                        while ($residuoPiano > 0 && !empty($figliFonte)) {
                            $n         = count($figliFonte);
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
                                $daAssegnare   = min($f['deficit'], $quotaBase);
                                $map[$f['id']] = ($map[$f['id']] ?? 0) + $daAssegnare;
                                $residuoPiano -= $daAssegnare;
                                $f['deficit'] -= $daAssegnare;

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

        // STEP 2: ASSEGNAZIONE DIRETTA SULLE FOGLIE
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

        // STEP 3: COPERTURA DA FATTURE STRAORDINARIE
        // FIX: Le righe con conto_id = NULL (spese private ad personam) vengono escluse
        // sia dal totale della fattura che dalla distribuzione della copertura.
        $straordinari = $pianiRate->filter(fn($p) => $p->tipo === 'straordinario');
        
        if ($straordinari->isNotEmpty()) {
            $fattureIds = $straordinari->flatMap->fattureStraordinarie->pluck('id')->unique()->toArray();
            
            if (!empty($fattureIds)) {
                $righe = DB::table('righe_fattura')
                    ->whereIn('fattura_passiva_id', $fattureIds)
                    ->get()
                    ->groupBy('fattura_passiva_id');

                foreach ($straordinari as $piano) {
                    foreach ($piano->fattureStraordinarie as $fattura) {
                        $importoFinanziato = (int) ($fattura->pivot->importo_collegato ?? 0);
                        if ($importoFinanziato <= 0) continue;

                        $righeFattura = $righe->get($fattura->id, collect());
                        if ($righeFattura->isEmpty()) continue;

                        // FIX: Escludiamo le righe private (conto_id NULL) dal totale
                        // per evitare che la copertura venga distribuita su un denominatore
                        // gonfiato, generando un deficit silenzioso sulle righe comuni.
                        $righeComuniFattura = $righeFattura->filter(fn($r) => !is_null($r->conto_id));
                        $totaleFattura      = $righeComuniFattura->sum(fn($r) => $r->importo_imponibile + $r->importo_iva);

                        foreach ($righeFattura as $riga) {
                            // FIX: Salta esplicitamente le righe private
                            if (is_null($riga->conto_id)) continue;

                            $importoRiga = $riga->importo_imponibile + $riga->importo_iva;
                            if ($totaleFattura > 0) {
                                $quota = (int) round(($importoRiga / $totaleFattura) * $importoFinanziato);
                                if (isset($contiById[$riga->conto_id])) {
                                    $map[$riga->conto_id] = ($map[$riga->conto_id] ?? 0) + $quota;
                                }
                            }
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

    /**
     * Estrae i capitoli che necessitano di finanziamento tramite Piano Rate Ordinario/Integrativo.
     * Esclude i capitoli scudati da Fondi o Conguaglio.
     */
    public function getCapitoliFinanziabili(array $analysisReport): array
    {
        $capitoliFinanziabili = [];

        foreach ($analysisReport['items'] as $item) {
            if (!($item['is_leaf'] ?? false)) {
                continue;
            }

            $daFinanziare = 0;
            
            if (isset($item['delta']) && $item['delta'] < 0) {
                $daFinanziare = abs($item['delta']);
            }

            if ($daFinanziare > 0) {
                $capitoliFinanziabili[] = [
                    'id'                => $item['id'],
                    'nome'              => $item['nome'],
                    'padre'             => $item['padre'],
                    'importo_suggerito' => $daFinanziare,
                    'dettagli_calcolo'  => [
                        'fabbisogno_reale'   => $item['budget'],
                        'gia_pianificato'    => $item['pianificato'],
                        'copertura_virtuale' => $item['copertura_virtuale']
                    ]
                ];
            }
        }

        usort($capitoliFinanziabili, function ($a, $b) {
            $padreCmp = strcmp($a['padre'] ?? '', $b['padre'] ?? '');
            if ($padreCmp === 0) {
                return strcmp($a['nome'], $b['nome']);
            }
            return $padreCmp;
        });

        return $capitoliFinanziabili;
    }
}
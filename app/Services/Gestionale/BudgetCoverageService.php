<?php

namespace App\Services\Gestionale;

use App\Models\Gestione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetCoverageService
{
    public function analyze(Gestione $gestione, array $fatturatoMap = [], array $coperturaVirtualeMap = []): array
    {
        // --- AGGIUNTO: 'pianiRate.fattureStraordinarie' ---
        $gestione->load(['pianoConto.conti.sottoconti', 'pianiRate.capitoli', 'pianiRate.fattureStraordinarie']);
        
        if (!$gestione->pianoConto) {
            return [
                'status' => 'empty', 
                'items'  => [],
                'totali' => ['budget' => 0, 'pianificato' => 0]
            ];
        }

        $contiRadice = $gestione->pianoConto->conti->whereNull('parent_id');
        
        // --- INIZIO FIX: Usiamo lo 'stato' (bozza/approvato) e non il vecchio 'attivo' ---
        $pianiRateAttivi = $gestione->pianiRate->filter(function ($piano) {
            // Estraiamo il valore sia se è un Enum di PHP 8.1, sia se è una stringa
            $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;
            return in_array($stato, ['bozza', 'approvato']);
        });
        // --- FINE FIX ---
        
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

        // NUOVO ORDINE - STEP 1: PUSH-DOWN DAI PADRI CON LIMITE BUDGET
        // (I Piani Ordinari Globali distribuiscono il budget teorico ai figli)
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
                        $budgetTeorico = (int) $figlio->importo;
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        
                        $deficit = $budgetTeorico - $copertoAttuale;

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

        // NUOVO ORDINE - STEP 2: ASSEGNAZIONE DIRETTA SULLE FOGLIE 
        // (I Piani Integrativi si applicano ORA, sommandosi sopra al budget già distribuito)
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

        // NUOVO ORDINE - STEP 3: COPERTURA DA FATTURE STRAORDINARIE
        // Andiamo a leggere le righe delle fatture finanziate e spingiamo
        // la copertura sui conti originali (es. conto "Imprevisto").
        $straordinari = $pianiRate->filter(fn($p) => $p->tipo === 'straordinario');
        
        if ($straordinari->isNotEmpty()) {
            $fattureIds = $straordinari->flatMap->fattureStraordinarie->pluck('id')->unique()->toArray();
            
            if (!empty($fattureIds)) {
                // Recuperiamo tutte le righe contabili di queste fatture
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

                        $totaleFattura = $righeFattura->sum(fn($r) => $r->importo_imponibile + $r->importo_iva);

                        // Distribuiamo l'importo finanziato proporzionalmente sui conti della fattura
                        foreach ($righeFattura as $riga) {
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
        // ... (existing appiattisciConti method logic remains unchanged)
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
        // ... (existing trovaPianiCoinvolti method logic remains unchanged)
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
            // Lavoriamo solo sulle foglie (sottoconti o conti senza figli)
            if (!($item['is_leaf'] ?? false)) {
                continue;
            }

            $daFinanziare = 0;
            
            // Se il delta è negativo, significa che Fabbisogno > (Pianificato + Virtuale)
            if (isset($item['delta']) && $item['delta'] < 0) {
                $daFinanziare = abs($item['delta']);
            }

            // Includiamo solo se c'è un deficit reale da coprire con le rate
            if ($daFinanziare > 0) {
                $capitoliFinanziabili[] = [
                    'id'                => $item['id'],
                    'nome'              => $item['nome'],
                    'padre'             => $item['padre'],
                    'importo_suggerito' => $daFinanziare, // Centesimi esatti
                    'dettagli_calcolo'  => [
                        'fabbisogno_reale'   => $item['budget'],
                        'gia_pianificato'    => $item['pianificato'],
                        'copertura_virtuale' => $item['copertura_virtuale']
                    ]
                ];
            }
        }

        // Ordiniamo alfabeticamente raggruppando per padre
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
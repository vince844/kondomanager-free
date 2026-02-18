<?php

namespace App\Services\Gestionale;

use App\Models\Gestione;
use Illuminate\Support\Collection;

/**
 * Class BudgetCoverageService
 * * Servizio responsabile dell'analisi di copertura del bilancio preventivo.
 * Confronta l'importo preventivato (Budget) con l'importo effettivamente coperto 
 * dai Piani Rate (Pianificato).
 * * Implementa la logica "Smart Push-Down" (Copertura a Cascata):
 * I fondi assegnati a un Capitolo Padre (es. Spese Generali) vengono distribuiti
 * automaticamente ai sottoconti figli che presentano un deficit, 
 * garantendo che il bilancio risulti coperto anche senza assegnazioni dirette.
 */
class BudgetCoverageService
{
    /**
     * Analizza lo stato di copertura di una gestione.
     * Restituisce un report dettagliato per ogni voce di spesa e i totali globali.
     *
     * @param Gestione $gestione La gestione da analizzare (es. Ordinaria 2026)
     * @return array Struttura dati pronta per il frontend (status, items, totali)
     */
    public function analyze(Gestione $gestione): array
    {
        // 1. Caricamento ottimizzato delle relazioni necessarie (Eager Loading)
        $gestione->load(['pianoConto.conti.sottoconti', 'pianiRate.capitoli']);
        
        if (!$gestione->pianoConto) {
            return ['status' => 'empty', 'items' => []];
        }

        // 2. Selezione dei nodi radice (Padri assoluti) e dei piani rate attivi
        $contiRadice = $gestione->pianoConto->conti->whereNull('parent_id'); 
        $pianiRateAttivi = $gestione->pianiRate->where('attivo', true);
        
        // 3. Esecuzione del calcolo Core (Logica a Cascata)
        // Restituisce una mappa [id_conto => importo_coperto_calcolato]
        $coperturaRealeMap = $this->calcolaCoperturaReale($contiRadice, $pianiRateAttivi);

        $report = [];
        
        // 4. Costruzione del Report (Appiattiamo l'albero per una lista lineare)
        foreach ($this->appiattisciConti($contiRadice) as $conto) {
            $isLeaf = $conto->sottoconti->isEmpty();
            
            // NOTA: Abbiamo rimosso il filtro che nascondeva i Padri con importo 0.
            // Ora includiamo TUTTI i conti nel report. Questo è fondamentale perché
            // il Controller deve poter leggere il "surplus" (portafoglio) dei padri
            // per applicare la logica di soppressione degli orfani nella Dashboard.
            // if ($conto->importo == 0 && !$isLeaf) continue; <--- RIMOSSO
            
            $budget = (int) $conto->importo;
            
            // Recuperiamo il pianificato dalla mappa calcolata, o 0 se non presente
            $pianificato = (int) ($coperturaRealeMap[$conto->id] ?? 0);
            
            $delta = $pianificato - $budget;

            // Determinazione stato e messaggi per la UI
            $status = 'ok'; 
            $severity = 'success'; 
            $message = 'Copertura perfetta.';

            if ($delta < -100) { // Tolleranza 1€ per arrotondamenti
                $status = 'deficit'; 
                $severity = 'danger';
                $percent = $budget > 0 ? round(($pianificato / $budget) * 100) : 0;
                $message = "Deficit: coperto al {$percent}%.";
            } elseif ($delta > 100) {
                $status = 'surplus'; 
                $severity = 'warning';
                $extra = number_format($delta / 100, 2, ',', '.');
                $message = "Surplus di € {$extra}.";
            }

            $report[] = [
                'id' => $conto->id, 
                'parent_id' => $conto->parent_id, // Fondamentale per il mapping nel Controller
                'nome' => $conto->nome, 
                'padre' => $conto->parent?->nome,
                'is_leaf' => $isLeaf, 
                'budget' => $budget, 
                'pianificato' => $pianificato, // Include i fondi ereditati/distribuiti
                'delta' => $delta, 
                'status' => $status, 
                'severity' => $severity, 
                'message' => $message,
                'gestione' => $gestione->nome,
                'piani_coinvolti' => $this->trovaPianiCoinvolti($conto->id, $pianiRateAttivi)
            ];
        }

        // 5. Calcolo Totali Globali
        // Sommiamo solo le "Foglie" (is_leaf) per evitare di duplicare gli importi
        // sommando sia il padre che i figli.
        $totBudget = 0; 
        $totPianificato = 0;
        
        foreach ($report as $r) {
            if ($r['is_leaf']) {
                $totBudget += $r['budget'];
                $totPianificato += $r['pianificato'];
            }
        }

        return [
            'status' => 'analyzed',
            'items' => $report,
            'totali' => ['budget' => $totBudget, 'pianificato' => $totPianificato]
        ];
    }

    /**
     * Motore di calcolo "Smart Push-Down".
     * Distribuisce gli importi dei piani rate sui conti corretti.
     *
     * @param mixed $contiRadice Collezione dei conti di primo livello
     * @param mixed $pianiRate Collezione dei piani rate attivi
     * @return array Mappa [id_conto => importo_totale_pianificato]
     */
    private function calcolaCoperturaReale($contiRadice, $pianiRate): array
    {
        $map = [];
        $tuttiContiFlat = $this->appiattisciConti($contiRadice);
        $contiById = $tuttiContiFlat->keyBy('id');

        // STEP 1: ASSEGNAZIONE DIRETTA (Priorità alle Foglie)
        // Se un piano rate punta direttamente a una sottovoce (es. "Compenso Amministratore"),
        // quei soldi sono "blindati" su quella voce.
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;
                
                // Se è una foglia (non ha sottoconti), assegna direttamente
                if ($contoModel && $contoModel->sottoconti->isEmpty()) {
                    $impegnato = !is_null($capitolo->pivot->importo) ? $capitolo->pivot->importo : $capitolo->importo;
                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $impegnato;
                }
            }
        }

        // STEP 2: DISTRIBUZIONE SMART DAI PADRI (Push-Down Logic)
        // Se un piano rate punta a un Capitolo Padre (es. "Spese Generali"),
        // i fondi vengono usati per coprire i "buchi" (deficit) dei figli.
        foreach ($pianiRate as $piano) {
            foreach ($piano->capitoli as $capitolo) {
                $contoModel = $contiById[$capitolo->id] ?? null;
                
                // Se è un Padre (ha sottoconti) e ha fondi propri definiti nel piano
                if ($contoModel && $contoModel->sottoconti->isNotEmpty()) {
                    $residuoPiano = !is_null($capitolo->pivot->importo) ? $capitolo->pivot->importo : $capitolo->importo;

                    // IMPORTANTE: Salviamo l'importo ANCHE sul padre stesso! 
                    // Questo permette al DashboardController di vedere il "Surplus" nel wallet del padre
                    // e capire che può coprire eventuali orfani.
                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $residuoPiano;

                    // A. TROVIAMO CHI HA BISOGNO
                    // Identifichiamo i figli che hanno un preventivo > copertura attuale (da Step 1)
                    $figliConDeficit = [];
                    foreach ($contoModel->sottoconti as $figlio) {
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        if ($figlio->importo > $copertoAttuale) {
                            $figliConDeficit[] = [
                                'id' => $figlio->id,
                                'manca' => $figlio->importo - $copertoAttuale
                            ];
                        }
                    }

                    // B. DISTRIBUZIONE A COPERTURA (Saturazione)
                    // Se c'è budget nel padre, copriamo i figli finché ci sono soldi.
                    if (count($figliConDeficit) > 0 && $residuoPiano > 0) {
                        foreach ($figliConDeficit as $datiFiglio) {
                            if ($residuoPiano <= 0) break; // Fondi finiti

                            // Assegno il minore tra (ciò che serve) e (ciò che ho)
                            $daAssegnare = min($datiFiglio['manca'], $residuoPiano);
                            
                            $map[$datiFiglio['id']] = ($map[$datiFiglio['id']] ?? 0) + $daAssegnare;
                            $residuoPiano -= $daAssegnare;
                        }
                    }
                    
                    // Nota: Non distribuiamo più l'eventuale residuo finale a caso sull'ultimo figlio.
                    // Il residuo rimane "in pancia" al padre (vedi $map[$contoModel->id] sopra).
                }
            }
        }
        return $map;
    }

    /**
     * Helper ricorsivo per trasformare l'albero dei conti in una Collection piatta.
     */
    private function appiattisciConti($conti) {
        $flat = collect();
        foreach ($conti as $c) {
            $flat->push($c);
            if ($c->sottoconti->isNotEmpty()) {
                $flat = $flat->merge($this->appiattisciConti($c->sottoconti));
            }
        }
        return $flat;
    }

    /**
     * Helper per trovare i nomi dei piani rate che finanziano un determinato conto.
     */
    private function trovaPianiCoinvolti($contoId, $piani)
    {
        $names = [];
        foreach ($piani as $p) {
            // Controlla se il piano include il conto ID o uno dei suoi figli/padri nella pivot
            // (Logica semplificata: controlla presenza diretta o tramite relazione)
            $coinvolto = $p->capitoli->contains(function ($c) use ($contoId) {
                return $c->id == $contoId || ($c->sottoconti && $c->sottoconti->contains('id', $contoId));
            });
            if ($coinvolto) $names[] = $p->nome;
        }
        return $names;
    }
}
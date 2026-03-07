<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchCapitoliPerGestioneController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            $request->validate(['gestione_id' => 'required|integer|exists:gestioni,id']);
            $gestioneId = $request->input('gestione_id');
            $currentPlanId = $request->input('piano_rate_id');

            $gestione = Gestione::with('pianoConto')->findOrFail($gestioneId);
            if ($gestione->condominio_id !== $condominio->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$gestione->pianoConto) return response()->json([]);

            // 1. Recuperiamo tutti i conti con i figli
            $conti = $gestione->pianoConto->conti()
                ->with(['parent', 'sottoconti'])
                ->get();
            
            $contiById = $conti->keyBy('id');

            // 2. Mappiamo TUTTI gli impegni esistenti tramite query diretta (Sicura e veloce)
            $rawImpegni = DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piani_rate.gestione_id', $gestioneId)
                ->where('piani_rate.attivo', true)
                ->when($currentPlanId, fn($q) => $q->where('piani_rate.id', '!=', $currentPlanId))
                ->select('piano_rate_capitoli.conto_id', 'piano_rate_capitoli.importo')
                ->get();

            // 3. MOTORE "PUSH-DOWN" (Lo stesso della Dashboard)
            $map = [];

            // STEP 3.A: Assegnazione diretta alle Foglie
            foreach ($rawImpegni as $row) {
                $contoModel = $contiById[$row->conto_id] ?? null;
                if ($contoModel && $contoModel->sottoconti->isEmpty()) {
                    $impegnato = is_null($row->importo) ? (int) $contoModel->importo : (int) $row->importo;
                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $impegnato;
                }
            }

            // STEP 3.B: Push-down dai Padri ai Figli
            foreach ($rawImpegni as $row) {
                $contoModel = $contiById[$row->conto_id] ?? null;
                if ($contoModel && $contoModel->sottoconti->isNotEmpty()) {
                    
                    $residuoPiano = is_null($row->importo) ? PHP_INT_MAX : (int) $row->importo;
                    $importoRealeDelPadre = is_null($row->importo) ? (int) $contoModel->importo : (int) $row->importo;
                    $map[$contoModel->id] = ($map[$contoModel->id] ?? 0) + $importoRealeDelPadre;

                    $figliConDeficit = [];
                    foreach ($contoModel->sottoconti as $figlio) {
                        $copertoAttuale = $map[$figlio->id] ?? 0;
                        $deficit = (int) $figlio->importo - $copertoAttuale;
                        if ($deficit > 0) {
                            $figliConDeficit[] = ['id' => $figlio->id, 'deficit' => $deficit];
                        }
                    }

                    if (empty($figliConDeficit) || $residuoPiano <= 0) continue;

                    $figliFonte = $figliConDeficit;
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
                            $map[$f['id']] = ($map[$f['id']] ?? 0) + $daAssegnare;
                            $residuoPiano -= $daAssegnare;
                            $f['deficit'] -= $daAssegnare;
                            if ($f['deficit'] > 0) $nuoviFigli[] = $f;
                        }
                        $figliFonte = $nuoviFigli;
                    }
                }
            }

            // 4. ELABORAZIONE OUTPUT PER IL DROPDOWN
            $capitoli = $conti->map(function($c) use ($map) {
                
                $importoTotale = (int) $c->importo;
                // Se è un padre vuoto, sommiamo i figli
                if ($importoTotale == 0 && $c->sottoconti->isNotEmpty()) {
                    $importoTotale = (int) $c->sottoconti->sum('importo');
                }

                $impegnato = (int) ($map[$c->id] ?? 0);
                
                // Sicurezza: non possiamo mostrare residui negativi nel dropdown
                $impegnato = min($impegnato, $importoTotale); 
                $residuo = max(0, $importoTotale - $impegnato);
                
                // Disabilitato se non c'è budget o se è completamente coperto
                $isDisabled = ($importoTotale == 0) || ($residuo <= 0);

                $nome = $c->parent_id ? "{$c->parent->nome} > {$c->nome}" : "[CAPITOLO] {$c->nome}";

                return [
                    'id' => $c->id,
                    'nome' => $nome,
                    'importo_totale' => MoneyHelper::fromCents($importoTotale),
                    'impegnato' => MoneyHelper::fromCents($impegnato),
                    'residuo' => MoneyHelper::fromCents($residuo),
                    'disabled' => $isDisabled,
                    'note' => $isDisabled ? "Budget esaurito/coperto" : "Disp: € " . MoneyHelper::format($residuo)
                ];
            });

            return response()->json($capitoli->values());

        } catch (\Exception $e) {
            Log::error('Errore fetch capitoli: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Errore interno'], 500);
        }
    }
}
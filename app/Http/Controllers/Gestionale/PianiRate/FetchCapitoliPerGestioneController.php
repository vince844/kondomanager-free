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
            // Aggiunta la validazione per l'esercizio_id
            $request->validate([
                'gestione_id' => 'required|integer|exists:gestioni,id',
                'esercizio_id' => 'required|integer|exists:esercizi,id'
            ]);
            
            $gestioneId = $request->input('gestione_id');
            $esercizioId = $request->input('esercizio_id'); // Raccogliamo l'esercizio dal frontend
            $currentPlanId = $request->input('piano_rate_id');

            $gestione = Gestione::with('pianoConto')->findOrFail($gestioneId);
            if ($gestione->condominio_id !== $condominio->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$gestione->pianoConto) return response()->json([]);

            // 1. Recuperiamo tutti i conti con gerarchia
            $conti = $gestione->pianoConto->conti()
                ->with(['parent', 'sottoconti'])
                ->get();
            
            $contiById = $conti->keyBy('id');
            $allContiIds = $conti->pluck('id')->toArray();

            // 2. RECUPERO FATTURATO REALE
            $rawFatturato = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizioId) // <--- IL FIX: Usiamo la variabile esplicita!
                ->whereIn('righe_fattura.conto_id', $allContiIds)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->select(
                    'righe_fattura.conto_id', 
                    DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale')
                )
                ->groupBy('righe_fattura.conto_id')
                ->get();

            // Mappa sicura del fatturato
            $fatturatoMap = [];
            foreach ($rawFatturato as $row) {
                $fatturatoMap[(int)$row->conto_id] = (int)$row->totale;
            }

            // 3. RECUPERO IMPEGNI ESISTENTI (Rate già emesse)
            $rawImpegni = DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piani_rate.gestione_id', $gestioneId)
                ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
                ->when($currentPlanId, fn($q) => $q->where('piani_rate.id', '!=', $currentPlanId))
                ->select('piano_rate_capitoli.conto_id', 'piano_rate_capitoli.importo')
                ->get();

            // 4. MAPPA IMPEGNI CON PROPAGAZIONE
            $mapImpegnato = [];
            foreach ($rawImpegni as $row) {
                $c = $contiById[$row->conto_id] ?? null;
                if (!$c) continue;

                $valoreImpegno = is_null($row->importo) ? (int)$c->importo : (int)$row->importo;
                $this->propagaImpegno($c, $valoreImpegno, $mapImpegnato);
            }

            // 5. ELABORAZIONE FINALE
            $capitoli = $conti->map(function($c) use ($mapImpegnato, $fatturatoMap) {
                
                $budgetTeorico = (int) $c->importo;
                if ($budgetTeorico === 0 && $c->sottoconti->isNotEmpty()) {
                    $budgetTeorico = (int) $c->sottoconti->sum('importo');
                }

                // Calcolo della spesa reale sommando il fatturato (che ora non sarà più 0!)
                $spesoReale = $this->getSpesoRealeRicorsivo($c, $fatturatoMap);
                $giaChiesto = (int) ($mapImpegnato[$c->id] ?? 0);
                
                $targetFinanziario = max($budgetTeorico, $spesoReale);
                $residuo = max(0, $targetFinanziario - $giaChiesto);
                
                $isSforo = ($spesoReale > $budgetTeorico) && ($residuo > 0);
                $isDisabled = ($residuo <= 0) || ($targetFinanziario <= 0);

                $nome = $c->parent_id ? "{$c->parent->nome} > {$c->nome}" : "[CAPITOLO] {$c->nome}";

                $nota = "Disp: € " . MoneyHelper::format($residuo);
                if ($isSforo) {
                    $nota = "Sforo da recuperare: € " . MoneyHelper::format($residuo);
                } elseif ($isDisabled && $targetFinanziario > 0) {
                    $nota = "Interamente finanziato/Esaurito";
                } elseif ($targetFinanziario === 0) {
                    $nota = "Nessun budget o spesa";
                }

                return [
                    'id'             => $c->id,
                    'nome'           => $nome,
                    'importo_totale' => MoneyHelper::fromCents($budgetTeorico),
                    'speso_reale'    => MoneyHelper::fromCents($spesoReale),
                    'residuo'        => MoneyHelper::fromCents($residuo),
                    'disabled'       => $isDisabled,
                    'is_sforo'       => $isSforo,
                    'note'           => $nota
                ];
            });

            return response()->json($capitoli->values());

        } catch (\Exception $e) {
            Log::error('Errore residui finanziari: ' . $e->getMessage());
            return response()->json(['error' => 'Errore interno'], 500);
        }
    }

    private function propagaImpegno($conto, $valore, &$map)
    {
        $map[$conto->id] = ($map[$conto->id] ?? 0) + $valore;
        if ($conto->sottoconti->isNotEmpty()) {
            foreach ($conto->sottoconti as $figlio) {
                $this->propagaImpegno($figlio, (int)$figlio->importo, $map);
            }
        }
    }

    private function getSpesoRealeRicorsivo($conto, $fatturatoMap)
    {
        $totale = $fatturatoMap[$conto->id] ?? 0;
        foreach ($conto->sottoconti as $figlio) {
            $totale += $this->getSpesoRealeRicorsivo($figlio, $fatturatoMap);
        }
        return $totale;
    }
}
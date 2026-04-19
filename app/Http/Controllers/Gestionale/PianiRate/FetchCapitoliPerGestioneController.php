<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Services\Gestionale\BudgetCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchCapitoliPerGestioneController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'gestione_id' => 'required|integer|exists:gestioni,id',
                'esercizio_id' => 'required|integer|exists:esercizi,id'
            ]);
            
            $gestioneId = $request->input('gestione_id');
            $esercizioId = $request->input('esercizio_id');
            $currentPlanId = $request->input('piano_rate_id');

            $gestione = Gestione::with('pianoConto')->findOrFail($gestioneId);
            if ($gestione->condominio_id !== $condominio->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if (!$gestione->pianoConto) return response()->json([]);

            $conti = $gestione->pianoConto->conti()
                ->visibili() // <-- Filtro che nasconde sopravvenienze e Ad Personam
                ->with(['parent', 'sottoconti' => function($query) {
                    $query->visibili(); // <-- Filtriamo anche i figli per estrema sicurezza
                }])
                ->get();
            
            $contiById = $conti->keyBy('id');
            $allContiIds = $conti->pluck('id')->toArray();

            // --- AGGIUNTO IL FILTRO SALVA-VITA SULLE SPESE PERSONALI ---
            $rawFatturato = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizioId) 
                ->whereIn('righe_fattura.conto_id', $allContiIds)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->whereNull('righe_fattura.immobile_id') // Esclude le righe ad personam
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

            $rawImpegni = DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piani_rate.gestione_id', $gestioneId)
                ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
                ->when($currentPlanId, fn($q) => $q->where('piani_rate.id', '!=', $currentPlanId))
                ->select('piano_rate_capitoli.conto_id', 'piano_rate_capitoli.importo')
                ->get();

            $mapImpegnato = [];
            foreach ($rawImpegni as $row) {
                $c = $contiById[$row->conto_id] ?? null;
                if (!$c) continue;

                $valoreImpegno = is_null($row->importo) ? (int)$c->importo : (int)$row->importo;
                $this->propagaImpegno($c, $valoreImpegno, $mapImpegnato);
            }

            // Utilizziamo il nuovo BudgetCoverageService per la logica condivisa
            $coverageService = app(BudgetCoverageService::class);
            
            // Dobbiamo estrarre la mappa virtuale per la gestione
            $fattureSforo = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizioId)
                ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                ->whereIn('righe_fattura.conto_id', $allContiIds)
                ->whereNull('righe_fattura.immobile_id')
                ->select(
                    'righe_fattura.conto_id', 
                    'fatture_passive.dati_extra', 
                    'righe_fattura.importo_imponibile', 
                    'righe_fattura.importo_iva'
                )
                ->get();

            $coperturaVirtualeMap = [];
            foreach ($fattureSforo as $row) {
                $datiExtra = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
                $strat = $datiExtra['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
                $importoRiga = abs((int)$row->importo_imponibile + (int)$row->importo_iva);

                if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                    $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + $importoRiga;
                }
            }

            // Chiamata al servizio
            $analisiBilancio = $coverageService->analyze($gestione, $fatturatoMap, $coperturaVirtualeMap);
            $capitoliFinanziabiliRaw = collect($coverageService->getCapitoliFinanziabili($analisiBilancio))->keyBy('id');

            $capitoli = $conti->map(function($c) use ($capitoliFinanziabiliRaw, $fatturatoMap) {
                // Troviamo il budget per l'interfaccia
                $budgetTeorico = (int) $c->importo;
                if ($budgetTeorico === 0 && $c->sottoconti->isNotEmpty()) {
                    $budgetTeorico = (int) $c->sottoconti->sum('importo');
                }

                $spesoReale = $this->getSpesoRealeRicorsivo($c, $fatturatoMap);

                // Se il capitolo è tra quelli restituito dal servizio come 'finanziabile'
                $finanziabileData = $capitoliFinanziabiliRaw->get($c->id);

                if ($finanziabileData) {
                    $residuo = $finanziabileData['importo_suggerito'];
                    $isDisabled = false;
                    $isSforo = $spesoReale > $budgetTeorico;
                } else {
                    // Se non è restituito, non c'è debito aperto
                    $residuo = 0;
                    $isDisabled = true;
                    $isSforo = false; // Se è scudato, non è uno sforo "attivo"
                }

                $nome = $c->parent_id ? "{$c->parent->nome} > {$c->nome}" : "[CAPITOLO] {$c->nome}";

                $nota = "Disp: € " . MoneyHelper::format($residuo);
                if ($isSforo) {
                    $nota = "Sforo da recuperare: € " . MoneyHelper::format($residuo);
                } elseif ($isDisabled && max($budgetTeorico, $spesoReale) > 0) {
                    $nota = "Interamente finanziato/Esaurito";
                } elseif (max($budgetTeorico, $spesoReale) === 0) {
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
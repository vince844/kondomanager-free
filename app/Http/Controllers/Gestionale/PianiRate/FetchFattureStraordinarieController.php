<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Helpers\MoneyHelper; 
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchFattureStraordinarieController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'gestione_id'  => 'required|integer|exists:gestioni,id',
                'esercizio_id' => 'required|integer|exists:esercizi,id'
            ]);

            $esercizioId   = $request->input('esercizio_id');
            $currentPlanId = $request->input('piano_rate_id');

            // 1. Estrazione Fatture (Sommiamo i centesimi dal DB)
            $rawFatture = DB::table('fatture_passive')
                ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
                ->join('righe_fattura', 'fatture_passive.id', '=', 'righe_fattura.fattura_passiva_id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizioId)
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                ->where(function($q) {
                    $q->where('righe_fattura.is_sopravvenienza', true)
                      ->orWhereNotNull('righe_fattura.immobile_id');
                })
                ->select(
                    'fatture_passive.id',
                    'fatture_passive.numero_documento',
                    'fatture_passive.data_documento',
                    'fornitori.ragione_sociale as fornitore',
                    DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_straordinario')
                )
                ->groupBy('fatture_passive.id', 'fatture_passive.numero_documento', 'fatture_passive.data_documento', 'fornitori.ragione_sociale')
                ->get();

            $fattureIds = $rawFatture->pluck('id')->toArray();

            $finanziamenti = [];
            if (!empty($fattureIds)) {
                $finanziamenti = DB::table('piano_rate_fatture')
                    ->join('piani_rate', 'piano_rate_fatture.piano_rate_id', '=', 'piani_rate.id')
                    ->whereIn('fattura_passiva_id', $fattureIds)
                    ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
                    ->when($currentPlanId, fn($q) => $q->where('piani_rate.id', '!=', $currentPlanId))
                    ->select('fattura_passiva_id', DB::raw('SUM(importo_collegato) as gia_finanziato'))
                    ->groupBy('fattura_passiva_id')
                    ->pluck('gia_finanziato', 'fattura_passiva_id')
                    ->toArray();
            }

            $carrello = [];
            foreach ($rawFatture as $f) {
                // Calcolo in centesimi (Logica DB)
                $totaleCents         = (int) $f->totale_straordinario;
                $giaFinanziatoCents  = (int) ($finanziamenti[$f->id] ?? 0);
                $residuoCents        = max(0, $totaleCents - $giaFinanziatoCents);

                if ($residuoCents > 0) {
                    // TRASFORMAZIONE IN EURO PER IL FRONTEND (Logica Anti-SAP: uniformità)
                    $carrello[] = [
                        'id'                    => $f->id,
                        'fornitore'             => $f->fornitore,
                        'numero_documento'      => $f->numero_documento ?? 'S/N',
                        'data_documento'        => Carbon::parse($f->data_documento)->format('d/m/Y'),
                        // Usiamo MoneyHelper::fromCents per mandare 183.00 invece di 18300
                        'totale_straordinario'  => MoneyHelper::fromCents($totaleCents),
                        'gia_finanziato'        => MoneyHelper::fromCents($giaFinanziatoCents),
                        'residuo_da_finanziare' => MoneyHelper::fromCents($residuoCents),
                        'importo_suggerito'     => MoneyHelper::fromCents($residuoCents), 
                        'selezionata'           => false
                    ];
                }
            }

            return response()->json($carrello);

        } catch (\Exception $e) {
            Log::error('Errore fetch fatture straordinarie: ' . $e->getMessage());
            return response()->json(['error' => 'Errore interno.'], 500);
        }
    }
}
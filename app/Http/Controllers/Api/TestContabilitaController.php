<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Http\JsonResponse;

class TestContabilitaController extends Controller
{
    /**
     * Esegue un controllo profondo (Deep Scan) sul Libro Giornale.
     * Verifica quadratura matematica, anomalie di integrità e genera il Bilancio di Verifica per Mastri.
     */
    public function checkQuadratura(Condominio $condominio): JsonResponse
    {
        // Eager Loading spinto per evitare N+1 query: carichiamo anche i mastri associati alle righe
        $scritture = ScritturaContabile::where('condominio_id', $condominio->id)
            ->with(['righe.contoContabile']) 
            ->get();
        
        $anomalieQuadratura = [];
        $anomalieIntegrita  = [];
        $mastriTotali       = []; // Qui immagazzineremo i totali di ogni singolo mastro
        
        $totaleDare = 0;
        $totaleAvere = 0;

        foreach ($scritture as $scrittura) {
            $dareScrittura = 0;
            $avereScrittura = 0;

            foreach ($scrittura->righe as $riga) {
                // 1. Somma DARE/AVERE per la singola scrittura
                if ($riga->tipo_riga === 'dare') {
                    $dareScrittura += $riga->importo;
                } else {
                    $avereScrittura += $riga->importo;
                }

                // 2. AGGREGAZIONE PER MASTRI (Il vero Bilancio di Verifica)
                $mastroId = $riga->conto_contabile_id ?? 'Orfano';
                
                if (!isset($mastriTotali[$mastroId])) {
                    $nomeMastro = $riga->contoContabile 
                        ? "[{$riga->contoContabile->codice}] {$riga->contoContabile->nome}" 
                        : 'SCONOSCIUTO / ORFANO';

                    $mastriTotali[$mastroId] = [
                        'nome'  => $nomeMastro,
                        'dare'  => 0,
                        'avere' => 0,
                    ];
                }

                if ($riga->tipo_riga === 'dare') {
                    $mastriTotali[$mastroId]['dare'] += $riga->importo;
                } else {
                    $mastriTotali[$mastroId]['avere'] += $riga->importo;
                }

                // 3. DEEP CHECKS DI INTEGRITÀ RELAZIONALE
                if (is_null($riga->conto_contabile_id)) {
                    $anomalieIntegrita[] = [
                        'scrittura_id' => $scrittura->id,
                        'riga_id'      => $riga->id,
                        'errore'       => 'Riga orfana: conto_contabile_id mancante',
                    ];
                }

                if ($riga->voce_spesa_id) {
                    $vocePreventivo = Conto::find($riga->voce_spesa_id);
                    if ($vocePreventivo && $vocePreventivo->conto_contabile_id !== $riga->conto_contabile_id) {
                        $anomalieIntegrita[] = [
                            'scrittura_id' => $scrittura->id,
                            'riga_id'      => $riga->id,
                            'errore'       => "Disallineamento Mastro: La riga usa il Mastro {$riga->conto_contabile_id}, ma la Voce di Preventivo ({$vocePreventivo->nome}) richiede il Mastro {$vocePreventivo->conto_contabile_id}",
                        ];
                    }
                }

                if ($riga->cassa_id) {
                    $cassa = Cassa::find($riga->cassa_id);
                    if ($cassa && $cassa->conto_contabile_id !== $riga->conto_contabile_id) {
                        $anomalieIntegrita[] = [
                            'scrittura_id' => $scrittura->id,
                            'riga_id'      => $riga->id,
                            'errore'       => "Disallineamento Finanziario: La riga usa la cassa '{$cassa->nome}', ma il mastro contabile non corrisponde a quello della cassa.",
                        ];
                    }
                }
            }
            
            $totaleDare += $dareScrittura;
            $totaleAvere += $avereScrittura;

            // Controllo quadratura della singola scrittura
            if ($dareScrittura !== $avereScrittura) {
                $anomalieQuadratura[] = [
                    'scrittura_id' => $scrittura->id,
                    'causale'      => $scrittura->causale,
                    'totale_dare'  => number_format($dareScrittura / 100, 2, ',', '.') . ' €',
                    'totale_avere' => number_format($avereScrittura / 100, 2, ',', '.') . ' €',
                    'sbilancio'    => number_format(abs($dareScrittura - $avereScrittura) / 100, 2, ',', '.') . ' €',
                ];
            }
        }

        // Formattiamo il dettaglio dei mastri in modo elegante
        $bilancioDiVerifica = collect($mastriTotali)->map(function ($mastro) {
            $saldo = $mastro['dare'] - $mastro['avere'];
            
            if ($saldo > 0) {
                $natura = 'DARE (Attività/Costi)';
            } elseif ($saldo < 0) {
                $natura = 'AVERE (Passività/Ricavi)';
            } else {
                $natura = 'PAREGGIO (Chiuso)';
            }

            return [
                'mastro'       => $mastro['nome'],
                'totale_dare'  => number_format($mastro['dare'] / 100, 2, ',', '.') . ' €',
                'totale_avere' => number_format($mastro['avere'] / 100, 2, ',', '.') . ' €',
                'saldo_finale' => number_format(abs($saldo) / 100, 2, ',', '.') . ' €',
                'eccedenza'    => $natura,
            ];
        })->values()->all();

        $isPerfetto = count($anomalieQuadratura) === 0 && count($anomalieIntegrita) === 0;

        return response()->json([
            'status'               => $isPerfetto ? '🟢 PERFETTO (Deep Scan Superato)' : '🔴 ANOMALIE RILEVATE',
            'condominio'           => $condominio->nome,
            'statistiche_globali' => [
                'totale_scritture'     => $scritture->count(),
                'totale_dare_storico'  => number_format($totaleDare / 100, 2, ',', '.') . ' €',
                'totale_avere_storico' => number_format($totaleAvere / 100, 2, ',', '.') . ' €',
            ],
            'bilancio_di_verifica' => $bilancioDiVerifica, // <-- LA NUOVA SEZIONE!
            'anomalie_matematiche' => $anomalieQuadratura,
            'anomalie_integrita'   => $anomalieIntegrita
        ]);
    }
}
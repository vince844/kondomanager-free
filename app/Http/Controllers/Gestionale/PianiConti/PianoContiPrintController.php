<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Services\PDF\PdfService;
use Illuminate\Http\Request;

class PianoContiPrintController extends Controller
{
    /**
     * Stampa la Distinta delle Spese – una riga per capitolo con il relativo importo preventivato.
     * Orientamento: Portrait.
     */
    public function distinta(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoConto $pianoConto,
        PdfService $pdfService
    ) {
        // Carica i conti radice (senza parent) con i sottoconti, esclusi i tecnici (sopravvenienze)
        $conti = Conto::with(['sottoconti' => function ($q) {
            $q->orderBy('nome');
        }])
        ->where('piano_conto_id', $pianoConto->id)
        ->whereNull('parent_id')
        ->orderBy('nome')
        ->get();

        $totalePreventivo     = 0;
        $totaleSopravvenienze = 0;

        foreach ($conti as $conto) {
            if ($conto->is_tecnico) {
                $totaleSopravvenienze += $conto->importo ?? 0;
                foreach ($conto->sottoconti as $s) {
                    $totaleSopravvenienze += $s->importo ?? 0;
                }
            } else {
                // Per i conti con sottoconti il padre è solo un raggruppamento (importo = 0)
                foreach ($conto->sottoconti as $s) {
                    if ($s->is_tecnico) {
                        $totaleSopravvenienze += $s->importo ?? 0;
                    } else {
                        $totalePreventivo += $s->importo ?? 0;
                    }
                }
                // Se il conto è foglia (nessun sottoconto), conta lui stesso
                if ($conto->sottoconti->isEmpty()) {
                    $totalePreventivo += $conto->importo ?? 0;
                }
            }
        }

        $data = [
            'condominio'           => $condominio,
            'esercizio'            => $esercizio,
            'pianoConto'           => $pianoConto,
            'conti'                => $conti,
            'totalePreventivo'     => $totalePreventivo,
            'totaleSopravvenienze' => $totaleSopravvenienze,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.distinta_spese', $data, [
            'orientation' => 'P',
            'margin_top'  => 32,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Distinta Spese – ' . $esercizio->nome);

        return response($mpdf->Output('distinta_spese.pdf', 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    /**
     * Stampa la Ripartizione delle Spese – matrice condòmino × rata.
     * Per ogni unità immobiliare mostra quanto deve pagare su ogni rata del piano.
     * Orientamento: Landscape.
     */
    public function riparto(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoConto $pianoConto,
        PdfService $pdfService
    ) {
        // Recupera i piani rate collegati alla stessa gestione del piano dei conti
        $pianiRate = PianoRate::where('gestione_id', $pianoConto->gestione_id)
            ->with([
                'rate' => fn($q) => $q->orderBy('numero_rata'),
                'rate.rateQuote.anagrafica',
                'rate.rateQuote.immobile',
            ])
            ->get();

        // --- Costruzione matrice ---
        // Righe: unità immobiliari / anagrafiche
        // Colonne: rate (per numero e scadenza)
        $matrice    = [];   // chiave => [cod, nome, piano, interno, importi_per_rata[numero_rata], totale]
        $colonneRate = [];   // numero_rata => [nome, scadenza]

        foreach ($pianiRate as $pianoRate) {
            foreach ($pianoRate->rate as $rata) {
                // Colonna per questa rata
                $colonneRate[$rata->numero_rata] = [
                    'nome'    => $rata->numero_rata . 'ª Rata',
                    'scadenza'=> $rata->data_scadenza
                        ? $rata->data_scadenza->format('d/m/Y')
                        : '',
                ];

                foreach ($rata->rateQuote as $quota) {
                    if (!$quota->immobile_id && !$quota->anagrafica_id) continue;

                    $chiave = $quota->immobile_id
                        ? 'immobile_' . $quota->immobile_id
                        : 'anagrafica_' . $quota->anagrafica_id;

                    if (!isset($matrice[$chiave])) {
                        $immobile  = $quota->immobile;
                        $anagrafica = $quota->anagrafica;

                        // Nome del condòmino/intestatario
                        $nomeEnte = $anagrafica->nome ?? '';
                        $codice   = $immobile ? ($immobile->codice_immobile ?? '-') : '-';
                        $interno  = $immobile ? ($immobile->interno ?? '') : '';
                        $piano    = $immobile ? ($immobile->piano ?? '') : '';

                        $matrice[$chiave] = [
                            'cod'             => $codice,
                            'nome'            => $nomeEnte,
                            'interno'         => $interno,
                            'piano'           => $piano,
                            'importi_per_rata'=> [],
                            'totale'          => 0,
                        ];
                    }

                    if (!isset($matrice[$chiave]['importi_per_rata'][$rata->numero_rata])) {
                        $matrice[$chiave]['importi_per_rata'][$rata->numero_rata] = 0;
                    }

                    $matrice[$chiave]['importi_per_rata'][$rata->numero_rata] += $quota->importo;
                    $matrice[$chiave]['totale'] += $quota->importo;
                }
            }
        }

        // Ordina le colonne per numero rata e la matrice per codice immobile
        ksort($colonneRate);
        usort($matrice, fn($a, $b) => strcmp($a['cod'], $b['cod']));

        $data = [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'pianoConto'  => $pianoConto,
            'colonneRate' => $colonneRate,
            'matrice'     => $matrice,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.ripartizione_spese', $data, [
            'orientation' => 'L',
            'margin_top'  => 32,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Ripartizione Spese – ' . $esercizio->nome);

        return response($mpdf->Output('ripartizione_spese.pdf', 'I'))
            ->header('Content-Type', 'application/pdf');
    }
}

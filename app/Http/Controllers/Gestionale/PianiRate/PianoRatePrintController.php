<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\PDF\PdfService;
use App\Services\PianoRateQuoteService;
use Illuminate\Http\Request;

class PianoRatePrintController extends Controller
{
    /**
     * Stampa lo Scadenziario / Prospetto Rate.
     *
     * Supporta tre modalità di aggregazione tramite query param ?modalita=:
     *   - "anagrafica" (default): 1 riga per condòmino, somma tutti i suoi immobili
     *   - "immobile":             1 riga per unità immobiliare
     *   - "entrambi":             prima tabella per anagrafica, poi tabella per immobile (pagina nuova)
     *
     * Orientamento: Landscape.
     */
    public function scadenziario(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoRate $pianoRate,
        PdfService $pdfService,
        PianoRateQuoteService $quoteService
    ) {
        $modalita = $request->input('modalita', 'anagrafica');
        if (!in_array($modalita, ['anagrafica', 'immobile', 'entrambi'])) {
            $modalita = 'anagrafica';
        }

        $pianoRate->load([
            'rate'           => fn($q) => $q->orderBy('numero_rata'),
            'rate.rateQuote' => fn($q) => $q->with(['anagrafica', 'immobile', 'rata']),
        ]);

        // --- Costruisce le colonne rata (comuni a tutti i tipi) ---
        $colonneRate = [];
        foreach ($pianoRate->rate as $rata) {
            $colonneRate[$rata->numero_rata] = [
                'nome'    => $rata->numero_rata . 'ª Rata',
                'scadenza'=> $rata->data_scadenza?->format('d/m/Y') ?? '',
            ];
        }
        ksort($colonneRate);

        // --- Matrice per ANAGRAFICA ---
        $matriceAnagrafica = null;
        if (in_array($modalita, ['anagrafica', 'entrambi'])) {
            $matriceAnagrafica = $this->buildMatriceAnagrafica($pianoRate, $colonneRate);
        }

        // --- Matrice per IMMOBILE ---
        $matriceImmobile = null;
        if (in_array($modalita, ['immobile', 'entrambi'])) {
            $matriceImmobile = $this->buildMatriceImmobile($pianoRate, $colonneRate);
        }

        $data = [
            'condominio'        => $condominio,
            'esercizio'         => $esercizio,
            'pianoRate'         => $pianoRate,
            'colonneRate'       => $colonneRate,
            'modalita'          => $modalita,
            'matriceAnagrafica' => $matriceAnagrafica,
            'matriceImmobile'   => $matriceImmobile,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.prospetto_rate', $data, [
            'orientation' => 'L',
            'margin_top'  => 32,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Scadenziario Rate – ' . $pianoRate->nome);

        return response($mpdf->Output('prospetto_rate.pdf', 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    // -------------------------------------------------------------------------
    // HELPERS PRIVATI
    // -------------------------------------------------------------------------

    /**
     * Aggrega per anagrafica_id: 1 riga = 1 condòmino (somma tutti i suoi immobili).
     * Stessa logica del PianoRateQuoteService::quotePerAnagrafica() usata nel frontend.
     */
    private function buildMatriceAnagrafica(PianoRate $pianoRate, array $colonneRate): array
    {
        $matrice = [];

        foreach ($pianoRate->rate as $rata) {
            $quotePerAnagrafica = $rata->rateQuote->groupBy('anagrafica_id');

            foreach ($quotePerAnagrafica as $anagraficaId => $quotes) {
                if (!$anagraficaId) continue;
                $anagrafica = $quotes->first()->anagrafica;
                if (!$anagrafica) continue;

                if (!isset($matrice[$anagraficaId])) {
                    $matrice[$anagraficaId] = [
                        'etichetta'       => $anagrafica->nome ?? '—',
                        'importi_per_rata'=> [],
                        'totale'          => 0,
                    ];
                }

                $importoRata = $quotes->sum('importo');
                $matrice[$anagraficaId]['importi_per_rata'][$rata->numero_rata] = $importoRata;
                $matrice[$anagraficaId]['totale'] += $importoRata;
            }
        }

        uasort($matrice, fn($a, $b) => strcmp($a['etichetta'], $b['etichetta']));
        return array_values($matrice);
    }

    /**
     * Aggrega per immobile_id: 1 riga = 1 unità immobiliare.
     */
    private function buildMatriceImmobile(PianoRate $pianoRate, array $colonneRate): array
    {
        $matrice = [];

        foreach ($pianoRate->rate as $rata) {
            $quotePerImmobile = $rata->rateQuote->whereNotNull('immobile_id')->groupBy('immobile_id');

            foreach ($quotePerImmobile as $immobileId => $quotes) {
                $immobile   = $quotes->first()->immobile;
                $anagrafica = $quotes->first()->anagrafica;
                if (!$immobile) continue;

                if (!isset($matrice[$immobileId])) {
                    $codice  = $immobile->codice_immobile ?? '-';
                    $interno = $immobile->interno ?? '';
                    $piano   = $immobile->piano   ?? '';
                    $proprietario = $anagrafica->nome ?? '—';

                    $matrice[$immobileId] = [
                        'etichetta'       => $codice . ' — Int. ' . $interno . ' (Piano ' . $piano . ')',
                        'sub_etichetta'   => $proprietario,
                        'importi_per_rata'=> [],
                        'totale'          => 0,
                    ];
                }

                $importoRata = $quotes->sum('importo');
                $matrice[$immobileId]['importi_per_rata'][$rata->numero_rata] = $importoRata;
                $matrice[$immobileId]['totale'] += $importoRata;
            }
        }

        uasort($matrice, fn($a, $b) => strcmp($a['etichetta'], $b['etichetta']));
        return array_values($matrice);
    }
}

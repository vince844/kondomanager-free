<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\PDF\PdfService;
use App\Services\PianoRateQuoteService;
use App\Services\RipartoCapitoliService;
use App\Services\RipartoTabelleService;
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
    // RIPARTO PER TABELLA × SOGGETTO
    // -------------------------------------------------------------------------

    /**
     * Stampa il Riparto Bilancio Preventivo per Tabella × Soggetto.
     *
     * Per ogni unità immobiliare e ogni soggetto (Proprietario / Inquilino /
     * Usufruttuario / Comodatario) mostra la quota millesimale e l'importo
     * ripartito su ciascuna tabella millesimale configurata nel piano dei conti.
     *
     * Orientamento: Landscape, formato adattivo (A4-L fino a 6 tabelle, A3-L oltre).
     */
    public function ripartoTabelle(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoRate $pianoRate,
        PdfService $pdfService,
        RipartoTabelleService $ripartoService
    ) {
        $matrice = $ripartoService->buildMatrice($pianoRate);

        $nTabelle = count($matrice['tabelle']);

        // Adatta formato pagina al numero di tabelle:
        // ≤ 5 tabelle → A4 Landscape (297 × 210 mm)
        // 6-8 tabelle → A3 Landscape (420 × 297 mm)
        // > 8 tabelle → A3 Landscape con font ridotto (gestito nella view)
        $formato = $nTabelle > 5 ? 'A3-L' : 'A4-L';

        $data = [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'pianoRate'  => $pianoRate,
            'matrice'    => $matrice,
            'nTabelle'   => $nTabelle,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.riparto_tabelle', $data, [
            'format'      => $formato,
            'orientation' => 'L',
            'margin_top'  => 32,
            'margin_left' => 8,
            'margin_right'=> 8,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Riparto per Tabella – ' . $pianoRate->nome);

        return response($mpdf->Output('riparto_tabelle.pdf', 'I'))
            ->header('Content-Type', 'application/pdf');
    }

    /**
     * Stampa il Riparto Bilancio Preventivo per Capitolo di Spesa × Soggetto (Modello Danea).
     *
     * Per ogni unità immobiliare e ogni soggetto mostra l'importo calcolato 
     * su ciascun capitolo di spesa (conto foglia).
     */
    public function ripartoCapitoli(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoRate $pianoRate,
        PdfService $pdfService,
        RipartoCapitoliService $ripartoService
    ) {
        $matrice = $ripartoService->buildMatrice($pianoRate);

        $nCapitoli = count($matrice['capitoli']);

        // Adatta formato pagina al numero di capitoli:
        $formato = $nCapitoli > 5 ? 'A3-L' : 'A4-L';

        $data = [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'pianoRate'  => $pianoRate,
            'matrice'    => $matrice,
            'nCapitoli'  => $nCapitoli,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.riparto_capitoli', $data, [
            'format'      => $formato,
            'orientation' => 'L',
            'margin_top'  => 32,
            'margin_left' => 8,
            'margin_right'=> 8,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Riparto per Capitolo di Spesa – ' . $pianoRate->nome);

        return response($mpdf->Output('riparto_capitoli.pdf', 'I'))
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
                    $interno = $immobile->interno ?: '-';
                    $piano   = $immobile->piano   ?: '-';
                    $proprietario = $anagrafica->nome ?? '—';

                    // Identità primaria allineata alla vista a schermo ("Per immobile"):
                    // guida il nome dell'unità (immobile.nome, fallback codice), con
                    // interno/piano/codice come dettaglio e l'intestatario anagrafico
                    // come riga secondaria (identifica il debitore, valenza legale).
                    $matrice[$immobileId] = [
                        'etichetta'       => $immobile->nome ?: $codice,
                        'sub_etichetta'   => 'Int. ' . $interno . ' • Piano ' . $piano . ' · cod. ' . $codice,
                        'intestatario'    => $proprietario,
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

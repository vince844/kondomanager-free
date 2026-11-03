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

        // ⚠️ `Output(..., 'I')` scrive sull'output buffer e torna vuota: il browser riceveva il PDF
        // (e il nome fisso `prospetto_rate.pdf`) dall'echo di mPDF, ma il corpo della risposta Laravel
        // era vuoto e nessun test poteva ispezionarlo. STRING_RETURN restituisce i byte veri; il nome
        // parlante viene da PdfService::nomeFile. Stessa trappola e stessa cura del Libro Giornale (beta.23).
        $nomeFile = PdfService::nomeFile('scadenziario-rate', $condominio->nome, $esercizio->data_inizio->format('Y'), null);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
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
     * Orientamento: orizzontale, formato A4 sempre (dalla 1.11.0-beta.27: prima A3 oltre cinque tabelle).
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

        // ⚠️ **Sempre A4, orizzontale.** Fino alla 1.11.0-beta.26 oltre cinque tabelle il foglio
        // diventava A3: nessuna stampante di casa o di studio lo ha, e un amministratore ci ha
        // mostrato il risultato — l'A3 ristampato dal browser su un A4 verticale, illeggibile e su
        // due pagine, contro l'A4 orizzontale a una pagina del programma che usava prima. Deciso da
        // Vincenzo il 13/09/2026: si stampa sempre in A4 e si sceglie solo l'orientamento; con molte
        // tabelle il modello spezza in blocchi di pagina (`array_chunk`), non allarga il foglio.
        $formato = 'A4-L';

        $data = [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'pianoRate'  => $pianoRate,
            'matrice'    => $matrice,
            'nTabelle'   => $nTabelle,
            // La firma dell'amministratore in una riga sola, sotto le note, e un piè di pagina che
            // sta nel margine: vedi `pdf.base`.
            'firma_compatta' => true,
            'piede_compatto' => true,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.riparto_tabelle', $data, [
            'format'      => $formato,
            'orientation' => 'L',
            // 31: l'intestazione di pagina parte a 10 mm (margin_header); il suo testo finisce a 27,4 mm
            // ma il filetto sotto (`.header` in pdf/styles: padding-bottom 10px + bordo 2px) arriva a
            // 30,95 mm. A 30 le maiuscole del titolo gli stavano a 0,2 mm e le parentesi di «(Blocco 2
            // di 2)» lo toccavano; a 28 lo barrava. Su un A4 orizzontale ogni millimetro è una riga in
            // più per pagina. Se il nome del condominio va a capo (oltre ~88 caratteri a 16pt)
            // l'intestazione cresce di 7,9 mm e copre il titolo: caso non gestito, già così con il 32.
            'margin_top'  => 31,
            'margin_left' => 8,
            'margin_right'=> 8,
            // Il piè di pagina standard misura 10,6 mm (mPDF rende la tabella a 9pt, non ai 7 del div)
            // e con margin_footer 5 non sta nei 7 mm di 12 − 5: il filetto tagliava firma e ultima
            // riga. La variante `piede_compatto` di pdf.base sta in 6,5 mm; 15 di margine erano aria,
            // e con 48 righe quei millimetri decidono se legenda e firma stanno nella seconda pagina.
            'margin_bottom' => 12,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Riparto per Tabella – ' . $pianoRate->nome);

        $nomeFile = PdfService::nomeFile('riparto-tabelle', $condominio->nome, $esercizio->data_inizio->format('Y'), null);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
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

        // Sempre A4 orizzontale, come la gemella per tabelle: vedi la nota lì (beta.27).
        $formato = 'A4-L';

        $data = [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'pianoRate'  => $pianoRate,
            'matrice'    => $matrice,
            'nCapitoli'  => $nCapitoli,
            'firma_compatta' => true,
            'piede_compatto' => true,
        ];

        $mpdf = $pdfService->generate('pdf.gestionale.riparto_capitoli', $data, [
            'format'      => $formato,
            'orientation' => 'L',
            // Stessi margini e stesse ragioni del riparto per tabella, qui sopra.
            'margin_top'  => 31,
            'margin_left' => 8,
            'margin_right'=> 8,
            'margin_bottom' => 12,
        ]);

        $mpdf->SetHeader($condominio->nome . '||Riparto per Capitolo di Spesa – ' . $pianoRate->nome);

        $nomeFile = PdfService::nomeFile('riparto-capitoli', $condominio->nome, $esercizio->data_inizio->format('Y'), null);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
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

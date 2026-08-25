<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\ModelloF24Service;
use App\Services\PDF\PdfService;
use Illuminate\Http\Response;

/**
 * La stampa del modello F24.
 *
 * Il prospetto — quello che la beta.38 ha introdotto — serve a **trascrivere** i campi
 * nell'home banking. Questo serve a **pagare allo sportello**, ed è un caso d'uso reale per un
 * condominio: le avvertenze dell'Agenzia impongono il canale telematico ai soli titolari di
 * partita IVA e a chi compensa crediti, e un condominio ordinario non è né l'uno né l'altro.
 *
 * Esce dal server e non dal dialogo di stampa del browser per due motivi che si vedono
 * entrambi: il foglio ha misure fisse — una griglia di caselle che i margini di stampa del
 * browser sposterebbero — e il file scaricato prende un nome che lo rende ritrovabile, invece
 * di quello che il browser ricava dal titolo della finestra.
 */
class DelegaF24PrintController extends Controller
{
    public function __invoke(
        Condominio $condominio,
        DelegaF24 $delega,
        ModelloF24Service $modello,
        PdfService $pdfService,
    ): Response {
        abort_if($delega->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        $delega->load(['righe', 'condominio']);

        $configurazione = [
            'orientation' => 'P',
            // Il modulo ministeriale occupa quasi tutto il foglio: i margini di default della
            // casa (40 mm in testa, per l'intestazione condominiale) qui manderebbero le
            // ultime sezioni in seconda pagina, e un F24 su due fogli non è un F24.
            'margin_left' => 7,
            'margin_right' => 7,
            'margin_top' => 6,
            'margin_bottom' => 6,
            'margin_header' => 0,
            'margin_footer' => 0,
        ];

        if ($filigrana = $modello->filigrana($delega)) {
            $configurazione['watermarkText'] = $filigrana;
            $configurazione['showWatermarkText'] = true;
        }

        $pdf = $pdfService->generate('pdf.gestionale.modello_f24', [
            'delega' => $delega,
            'quadro' => $modello->quadro($delega),
            'copie' => $modello->copie(),
            'casellePettine' => ModelloF24Service::CASELLE_CODICE_FISCALE,
        ], $configurazione);

        // `I` apre il PDF nel visualizzatore invece di forzare il salvataggio: un F24 si
        // guarda prima di stamparlo, e il nome del file resta comunque quello che diamo noi.
        return response($pdf->Output($modello->nomeFile($delega), 'I'))
            ->header('Content-Type', 'application/pdf');
    }
}

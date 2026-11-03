<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\PDF\PdfService;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pagina Stato patrimoniale — punto 3 della sequenza di docs/registri_contabili.md, decisioni
 * D16–D20. Tre sezioni (situazione alla data, riepilogo finanziario dell'esercizio, controlli come
 * equazioni) più la vista sulla liquidità (D11) e il risultato di gestione (D18).
 *
 * Il controller non calcola niente: legge `StatoPatrimonialePaginaService::costruisci()` e la
 * stessa struttura va a schermo e in stampa. Nessun filtro: la data è quella dell'esercizio (D16),
 * e un selettore libero di data non esiste in questa beta — dichiarato in pagina.
 */
class StatoPatrimonialeController extends Controller
{
    use HasCondomini;

    public function __construct(private readonly StatoPatrimonialePaginaService $pagina) {}

    public function index(Condominio $condominio, Esercizio $esercizio): Response
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        return Inertia::render('gestionale/movimenti/statoPatrimoniale/Show', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'pagina' => $this->pagina->costruisci($condominio, $esercizio),
        ]);
    }

    public function stampa(Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        $pagina = $this->pagina->costruisci($condominio, $esercizio);

        $mpdf = $pdfService->generate('pdf.gestionale.stato_patrimoniale', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'pagina' => $pagina,
            // Fotografia calcolata, non atto: senza firma come il registro e il giornale (D20).
            // L'atto sottoscritto è il rendiconto (1.12).
            'senza_firma' => true,
        ], [
            // Stessa carta del registro (§7-quinquies): vista standalone, font propri.
            'default_font' => 'inter',
            'orientation' => 'P',
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $nomeFile = PdfService::nomeFile('stato-patrimoniale', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$pagina['data']);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }
}

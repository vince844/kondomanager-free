<?php

namespace App\Http\Controllers\Gestionale\Immobili;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Services\PDF\PdfService;
use App\Services\Riparto\ProspettoOneriAccessori;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * La stampa del prospetto degli oneri accessori di un'unità (B3a, 1.11.0-beta.34), aperta dalla pagina dei titolari.
 *
 * L'esercizio arriva in query, non nell'indirizzo: con `scopeBindings()` un `{esercizio}` dopo `{immobile}` si
 * cercherebbe fra i figli dell'unità, che non ne ha. Si verifica qui che sia del condominio, come l'unità. Senza
 * esercizio in query vale quello corrente del condominio.
 */
class ProspettoOneriController extends Controller
{
    use HasEsercizio;

    public function stampa(Request $request, Condominio $condominio, Immobile $immobile, ProspettoOneriAccessori $prospetto, PdfService $pdfService): Response
    {
        abort_unless((int) $immobile->condominio_id === (int) $condominio->id, 404);
        $esercizio = $request->filled('esercizio')
            ? Esercizio::find((int) $request->query('esercizio'))
            : $this->getEsercizioCorrente($condominio);
        abort_unless($esercizio !== null && (int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $mpdf = $pdfService->generate('pdf.gestionale.prospetto_oneri_accessori', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => $immobile,
            'prospetto'  => $prospetto->calcola($immobile, $esercizio),
        ], [
            'orientation' => 'P',
            // 32 come la stampa del piano rate: con 30 l'intestazione del condominio scende sotto il margine, e dalla
            // seconda pagina in poi la prima riga le finisce sopra (beta.47, prova a video con tre conduttori).
            'margin_top'  => 32,
        ]);

        // La forma della casa: libro, condominio, anno, e l'unità in coda (vedi `PdfService::nomeFile`).
        $nomeFile = PdfService::nomeFile('prospetto-oneri', $condominio->nome, $esercizio->data_inizio?->format('Y'), Str::slug($immobile->nome ?? ('unita-' . $immobile->id)));

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $nomeFile . '"');
    }
}

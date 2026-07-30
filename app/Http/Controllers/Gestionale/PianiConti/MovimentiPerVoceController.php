<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * I movimenti che compongono il consuntivo di una singola voce di spesa.
 *
 * Serve il drill-down dalla colonna Consuntivo del Piano dei Conti: il totale dice
 * «quanto», questo elenco dice «per colpa di cosa». Caricato su richiesta e non
 * insieme alla pagina, perché un piano con molte voci e molti movimenti gonfierebbe
 * il payload iniziale per un dettaglio che si apre su una voce alla volta.
 */
class MovimentiPerVoceController extends Controller
{
    public function __invoke(
        Condominio $condominio,
        Esercizio $esercizio,
        Conto $conto,
        SpesaPerVoceService $spesaPerVoce,
    ): JsonResponse {
        // Scoping esplicito: gli id arrivano dall'indirizzo, e senza questi controlli
        // si potrebbero leggere i movimenti di un altro condominio cambiando l'URL.
        if ($esercizio->condominio_id !== $condominio->id) {
            throw new NotFoundHttpException();
        }

        $appartieneAlCondominio = $conto->pianoConto
            && $conto->pianoConto->condominio_id === $condominio->id;

        if (! $appartieneAlCondominio) {
            throw new NotFoundHttpException();
        }

        $movimenti = $spesaPerVoce->movimentiPerVoce($esercizio, $conto->id);

        // Il totale è quello VERO della voce, non la somma delle righe mostrate: con
        // l'elenco troncato le due cose divergono, e il numero da cui la modale è
        // stata aperta deve restare quello autorevole.
        $totale = $spesaPerVoce->perEsercizio($esercizio, [$conto->id])[$conto->id] ?? 0;

        return response()->json([
            'voce' => [
                'id' => $conto->id,
                'nome' => $conto->nome,
                'codice' => $conto->codice,
            ],
            'movimenti' => $movimenti,
            'totale' => $totale,
            // Dichiarato, mai silenzioso: un elenco tagliato che non lo dice sembra
            // completo, e chi legge conclude che i movimenti siano solo quelli.
            'troncato' => count($movimenti) >= SpesaPerVoceService::LIMITE_MOVIMENTI,
            'limite' => SpesaPerVoceService::LIMITE_MOVIMENTI,
        ]);
    }
}

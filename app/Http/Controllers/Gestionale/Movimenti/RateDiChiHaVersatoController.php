<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\RataQuote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le rate di chi ha versato per un altro, fra cui l'amministratore sceglie dove va la parte in più (Coda 167, decisione
 * 30.8, 1.11.0-beta.40).
 *
 * Stesso perimetro della regola che valida la scelta (`StoreIncassoRateRequest`, `quota_parte_in_piu_id`): quote a
 * importo positivo di rate **emesse** di piani di **questo** condominio. Le pagate restano in elenco — se in una gestione
 * sono tutte pagate, la parte in più diventa credito sull'ultima —, segnate come tali. Il modulo le chiede quando si
 * sceglie «Versato da», come chiede la situazione debitoria quando si sceglie il pagante.
 */
class RateDiChiHaVersatoController extends Controller
{
    public function __invoke(Request $request, Condominio $condominio): JsonResponse
    {
        // Un numero, non un elenco: `integer()` di un array darebbe l'anagrafica n.1 (lo schema del reperto R5).
        $anagraficaId = (int) $request->validate(['anagrafica_id' => ['required', 'integer']])['anagrafica_id'];

        $quote = RataQuote::where('anagrafica_id', $anagraficaId)
            ->where('importo', '>', 0)
            ->whereHas('rata', fn ($r) => $r->where('stato', 'emessa'))
            ->whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominio->id))
            ->with('rata.pianoRate.gestione:id,nome')
            ->orderBy('data_scadenza')
            ->orderBy('id')
            ->get();

        return response()->json([
            'rate' => $quote->map(fn (RataQuote $q) => [
                'id'          => $q->id,
                'numero_rata' => $q->rata->numero_rata,
                'gestione_id' => $q->rata->pianoRate->gestione_id,
                'gestione'    => $q->rata->pianoRate->gestione?->nome,
                'scadenza'    => $q->data_scadenza?->format('d/m/Y'),
                // In euro, come la situazione debitoria: il modulo lavora in euro decimali.
                'residuo'     => MoneyHelper::fromCents(max(0, $q->importo - $q->importo_pagato)),
                'pagata'      => $q->importo_pagato >= $q->importo,
            ])->values(),
        ]);
    }
}

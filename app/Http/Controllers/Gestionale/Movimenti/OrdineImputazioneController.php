<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\ControllaImputazioneRequest;
use App\Models\Condominio;
use App\Services\Gestionale\Incassi\OrdineImputazione;
use Illuminate\Http\JsonResponse;

/**
 * Sola lettura (decisioni 217 e 218): la distribuzione che il modulo dell'incasso sta per salvare segue l'ordine delle scadenze? Se no,
 * quale rata, la più vecchia, resta aperta, anche di un'altra unità o di un'altra gestione della persona. Lo stesso calcolo che il
 * salvataggio rifà e scrive (`OrdineImputazione::perRichiesta`). Non scrive niente.
 */
class OrdineImputazioneController extends Controller
{
    public function __invoke(ControllaImputazioneRequest $request, Condominio $condominio, OrdineImputazione $ordine): JsonResponse
    {
        return response()->json($ordine->perRichiesta($request->validated(), (int) $condominio->id));
    }
}

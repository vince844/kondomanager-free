<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\FetchFattureSimiliRequest;
use App\Models\Condominio;
use App\Services\Gestionale\Duplicati\RicercaFattureSimili;
use Illuminate\Http\JsonResponse;

/**
 * Interrogato mentre il modulo di registrazione si compila — decisione **D4**
 * (`docs/prima_nota_rapida.md`), costruita nella 1.11.0-beta.13.
 *
 * ⚠️ **Non precaricato con la pagina, e non è un dettaglio implementativo: è la decisione presa
 * con Vincenzo aprendo questa beta.** I numeri del database dimostrativo (30 fatture in tutto, sette
 * per il condominio più carico) non sono una prova di scalabilità — usarli come tale è l'errore
 * che questa stessa beta ha già corretto due volte altrove (Coda 111). Un condominio reale dopo
 * anni ha centinaia di fatture: precaricarle tutte a ogni apertura del modulo è un costo che
 * cresce senza motivo quando un'unica interrogazione mirata basta.
 */
class FetchFattureSimiliController extends Controller
{
    public function __invoke(
        Condominio $condominio,
        FetchFattureSimiliRequest $request,
        RicercaFattureSimili $ricerca,
    ): JsonResponse {
        $dati = $request->validated();

        $simili = $ricerca->cerca(
            condominioId: $condominio->id,
            esercizioId: (int) $dati['esercizio_id'],
            fornitoreId: (int) $dati['fornitore_id'],
            numeroDocumento: $dati['numero_documento'] ?? null,
            totaleDocumentoCents: isset($dati['totale_documento_cents']) ? (int) $dati['totale_documento_cents'] : null,
            dataDocumento: $dati['data_documento'] ?? null,
            tipoDocumento: $dati['tipo_documento'] ?? 'fattura',
            escludiFatturaId: isset($dati['escludi_fattura_id']) ? (int) $dati['escludi_fattura_id'] : null,
        );

        return response()->json($simili->map->toArray()->values());
    }
}

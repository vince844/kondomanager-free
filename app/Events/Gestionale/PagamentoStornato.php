<?php

namespace App\Events\Gestionale;

use App\Models\Gestionale\PagamentoFornitore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento lanciato dopo lo storno di un pagamento fornitore.
 *
 * Listener attivi (v1.9.1):
 *  - InvalidaLiquidityForecastCache
 *  - RiapriScadenziarioPagamento
 *  - AnnullaF24SeNonAncoraVersato (se il pagamento aveva importo_ritenuta > 0)
 *
 * $crossEsercizio = true quando il pagamento originale era in esercizio chiuso
 * e lo storno è stato registrato nell'esercizio corrente (Variante B1).
 */
class PagamentoStornato
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PagamentoFornitore $pagamento,
        public readonly string $motivo,
        public readonly bool $crossEsercizio = false
    ) {}
}
<?php

namespace App\Events\Gestionale;

use App\Models\Gestionale\PagamentoFornitore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento lanciato dopo la registrazione di un pagamento fornitore.
 *
 * Listener attivi (v1.9.1):
 *  - SyncScadenziarioWithPagamento (segna il task "Pagare fornitore" come completato)
 *  - SyncF24WithPagamento (crea task F24 se importo_ritenuta > 0)
 *
 * IMPORTANTE: i listener devono usare `public bool $afterCommit = true`
 * per non tenere il lock pessimistico attivo durante la loro esecuzione.
 */
class PagamentoRegistrato
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PagamentoFornitore $pagamento
    ) {}
}
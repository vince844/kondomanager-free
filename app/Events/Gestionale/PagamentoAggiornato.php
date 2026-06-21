<?php

namespace App\Events\Gestionale;

use App\Models\Gestionale\PagamentoFornitore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento lanciato dopo la modifica di un pagamento fornitore esistente.
 *
 * I campi *Before portano i valori precedenti alla modifica, necessari ai
 * listener per capire se qualcosa di rilevante è cambiato (es. se aggiornare
 * la scadenza F24, se creare/chiudere un task Inbox).
 *
 * Listener attivi (v1.9.1-beta.9):
 *  - SyncF24WithPagamento::handleAggiornato() — aggiorna/crea/chiude il task F24
 *
 * IMPORTANTE: i listener devono usare `public bool $afterCommit = true`
 * per non tenere il lock pessimistico attivo durante la loro esecuzione.
 */
class PagamentoAggiornato
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PagamentoFornitore $pagamento,
        /** Data pagamento prima della modifica (formato Y-m-d), per rilevare cambio data. */
        public readonly ?string $dataPagamentoBefore,
        /** Importo ritenuta in centesimi prima della modifica, per rilevare cambio ritenuta. */
        public readonly int $importoRitenutaBefore,
    ) {}
}

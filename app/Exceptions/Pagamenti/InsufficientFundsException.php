<?php

namespace App\Exceptions\Pagamenti;

/**
 * Il saldo del conto corrente selezionato è insufficiente a coprire il pagamento.
 *
 * Porta dati strutturati in centesimi per permettere al frontend di mostrare
 * un modale preciso (saldo attuale, necessario, scopertura) senza parsing testuale.
 * Bypassabile con flag allow_overdraft = true (richiede nota obbligatoria nell'audit trail).
 *
 * HTTP: 409 Conflict
 */
class InsufficientFundsException extends PagamentoException
{
    /**
     * @param string $message           Messaggio human-readable
     * @param int    $saldoCents        Saldo attuale del conto (in centesimi)
     * @param int    $necessarioCents   Importo necessario per il pagamento (in centesimi)
     * @param int    $scoperturaCents   Differenza negativa — quanto manca (in centesimi)
     */
    public function __construct(
        string $message,
        public readonly int $saldoCents      = 0,
        public readonly int $necessarioCents = 0,
        public readonly int $scoperturaCents = 0,
    ) {
        parent::__construct($message);
    }
}

<?php

namespace App\Exceptions\Pagamenti;

/**
 * Il totale allocato (pagamenti + compensazioni) supera il netto_a_pagare
 * della fattura. Indica duplicazione, errore operatore, o NC mancante.
 *
 * Porta dati strutturati in centesimi per permettere al frontend di mostrare
 * un modale preciso senza fare parsing del messaggio testuale.
 *
 * HTTP: 422 Unprocessable Entity
 * Contesto: lanciata SOLO da validaInput(), mai da ricalcolaStatoFattura()
 * (che logga e marca flag inconsistenza_pagamento invece di bloccare il reconcile).
 */
class OverpaymentException extends PagamentoException
{
    /**
     * @param string $message            Messaggio human-readable
     * @param int    $allocatoCents      Importo allocato proposto (in centesimi)
     * @param int    $residuoCents       Residuo disponibile della fattura (in centesimi)
     * @param string $numFattura         Numero documento fattura coinvolta
     */
    public function __construct(
        string $message,
        public readonly int    $allocatoCents = 0,
        public readonly int    $residuoCents  = 0,
        public readonly string $numFattura    = '',
    ) {
        parent::__construct($message);
    }
}

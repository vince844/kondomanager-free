<?php

namespace App\Exceptions\Pagamenti;

/**
 * La fattura non può essere modificata direttamente: è necessario uno storno contabile.
 *
 * La modifica diretta è permessa solo su fatture ordinarie completamente aperte
 * (stato_pagamento = 'aperta', non pregresse, senza sopravvenienze, senza sforo pendente,
 * non in piano rate approvato, esercizio aperto).
 *
 * Per tutti gli altri casi usare la procedura di storno (Nota di Credito).
 *
 * HTTP: 422 Unprocessable Entity
 */
class FatturaModificaVietataException extends PagamentoException
{
    public function __construct(string $motivo = 'Fattura non modificabile')
    {
        parent::__construct($motivo);
    }
}

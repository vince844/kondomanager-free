<?php

namespace App\Exceptions\Pagamenti;

/**
 * Il pagamento non può essere modificato direttamente: è necessario uno storno contabile.
 *
 * La modifica diretta è permessa solo su pagamenti originali (pagamento_padre_id IS NULL)
 * non stornati, il cui esercizio di scrittura è ancora aperto.
 *
 * Per tutti gli altri casi usare la procedura di storno.
 *
 * HTTP: 422 Unprocessable Entity
 */
class PagamentoModificaVietataException extends PagamentoException
{
    public function __construct(string $motivo = 'Pagamento non modificabile')
    {
        parent::__construct($motivo);
    }
}

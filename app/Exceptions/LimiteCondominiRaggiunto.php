<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'installazione ha già tanti condomini quanti `LIMITE_CONDOMINI` ne consente. Chi la riceve
 * la mostra così com'è: il messaggio è neutro di proposito, non dice chi ha messo il limite né
 * come alzarlo — quello lo sa chi ospita l'installazione.
 */
class LimiteCondominiRaggiunto extends RuntimeException
{
    public function __construct(public readonly int $limite)
    {
        parent::__construct(__('condomini.limite_condomini_raggiunto'));
    }
}

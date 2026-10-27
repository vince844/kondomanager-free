<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Un'istruzione del dump non è riuscita a metà di un passo dell'import. Porta il punto di ripresa (l'istruzione
 * che non è riuscita: le precedenti sono già scritte) e, come eccezione precedente, l'errore vero, con il suo
 * messaggio.
 */
class ImportInterruptedException extends RuntimeException
{
    /**
     * @param  array{offset: int, delimiter: string}  $checkpoint
     */
    public function __construct(public readonly array $checkpoint, Throwable $errore)
    {
        parent::__construct($errore->getMessage(), (int) $errore->getCode(), $errore);
    }
}

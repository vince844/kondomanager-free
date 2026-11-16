<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Restore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Un'istruzione del dump non è riuscita a metà di un passo dell'import. Porta il punto di ripresa (dove finisce
 * l'ultima istruzione confermata nel database: il gruppo di righe aperto è annullato, e le istruzioni dopo il punto
 * non sono scritte) e, come eccezione precedente, l'errore vero, con il suo messaggio.
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

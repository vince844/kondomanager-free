<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

/**
 * Decisione 529: il versamento di un erede sul suo debito ereditato non si registra così com'è (più della sua parte ancora aperta, il debito già in una
 * rata nuova, una successione che non è sua). Prima della transazione, o dentro se nel frattempo il debito è cambiato: niente resta scritto.
 */
class DebitoEreditatoNonIncassabileException extends IncassoNonRegistrabileException
{
    public function __construct(string $messaggio)
    {
        parent::__construct($messaggio);
    }

    public function campo(): string
    {
        return 'debito_ereditato';
    }
}

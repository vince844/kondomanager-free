<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Una riga del registro di nomina e revoca dell'amministratore (art. 1130 n. 7 c.c.): la nomina o la revoca. */
enum EventoNomina: string
{
    use ConLeParole;

    case NOMINA = 'nomina';
    case REVOCA = 'revoca';

    public function parola(): string
    {
        return match ($this) {
            self::NOMINA => 'Nomina',
            self::REVOCA => 'Revoca',
        };
    }
}

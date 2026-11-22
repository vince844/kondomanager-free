<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** La convocazione di una seduta: prima o seconda (art. 1136 c.c.). */
enum ConvocazioneSeduta: string
{
    use ConLeParole;

    case PRIMA = 'prima';
    case SECONDA = 'seconda';

    public function parola(): string
    {
        return match ($this) {
            self::PRIMA => 'Prima',
            self::SECONDA => 'Seconda',
        };
    }
}

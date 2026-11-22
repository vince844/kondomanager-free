<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** I mezzi dell'art. 66 c. 3 disp. att. L'email semplice non è un mezzo di convocazione (Cass. 16399/2025, legge §1). */
enum MezzoConvocazione: string
{
    use ConLeParole;

    case PEC = 'pec';
    case RACCOMANDATA = 'raccomandata';
    case A_MANO = 'a_mano';
    case FAX = 'fax';

    public function parola(): string
    {
        return match ($this) {
            self::PEC => 'PEC',
            self::RACCOMANDATA => 'Raccomandata',
            self::A_MANO => 'A mano',
            self::FAX => 'Fax',
        };
    }
}

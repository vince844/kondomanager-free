<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Ordinaria o straordinaria amministrazione: chi vota con l'usufrutto e a chi va l'avviso (art. 67 c. 6 e 7 disp. att., legge §7). */
enum AmministrazionePunto: string
{
    use ConLeParole;

    case ORDINARIA = 'ordinaria';
    case STRAORDINARIA = 'straordinaria';

    public function parola(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria amministrazione',
            self::STRAORDINARIA => 'Straordinaria amministrazione',
        };
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Come è presente una persona in sala: nella sala, o «In collegamento» (art. 66 c. 6 disp. att.). */
enum ComePresente: string
{
    use ConLeParole;

    case IN_SALA = 'in_sala';
    case IN_COLLEGAMENTO = 'in_collegamento';

    public function parola(): string
    {
        return match ($this) {
            self::IN_SALA => 'In sala',
            self::IN_COLLEGAMENTO => 'In collegamento',
        };
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Da dove nasce un punto dell'ordine del giorno (§5). */
enum FontePunto: string
{
    use ConLeParole;

    case DI_SERIE = 'di_serie';
    case MEMO = 'memo';
    case PROGRAMMA = 'programma';
    case NOTA_SINTETICA = 'nota_sintetica';
    case A_MANO = 'a_mano';

    public function parola(): string
    {
        return match ($this) {
            self::DI_SERIE => 'Di serie',
            self::MEMO => 'Da te',
            self::PROGRAMMA => 'Dal programma',
            self::NOTA_SINTETICA => 'Dalla nota sintetica',
            self::A_MANO => 'Scritto a mano',
        };
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Perché su un punto straordinario vota l'usufruttuario e non il nudo proprietario (art. 67 c. 7 disp. att.): lo sceglie il presidente, e il verbale lo scrive. */
enum PercheVotaLUsufruttuario: string
{
    use ConLeParole;

    case ART_1006 = 'art_1006';
    case LAVORI_985_986 = 'lavori_985_986';
    case SEMPLICE_GODIMENTO = 'semplice_godimento';

    public function parola(): string
    {
        return match ($this) {
            self::ART_1006 => 'Si avvale dell\'art. 1006 c.c.',
            self::LAVORI_985_986 => 'Lavori od opere degli artt. 985 e 986 c.c.',
            self::SEMPLICE_GODIMENTO => 'Un affare di semplice godimento delle cose comuni',
        };
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Come si partecipa (art. 66 c. 6 disp. att.; decisione 517 (1)). */
enum ModalitaAssemblea: string
{
    use ConLeParole;

    case PRESENZA = 'presenza';
    case VIDEOCONFERENZA = 'videoconferenza';
    case MISTA = 'mista';

    public function parola(): string
    {
        return match ($this) {
            self::PRESENZA => 'In presenza',
            self::VIDEOCONFERENZA => 'In videoconferenza',
            self::MISTA => 'In presenza e in videoconferenza',
        };
    }
}

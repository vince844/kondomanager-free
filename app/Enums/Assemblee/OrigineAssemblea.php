<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Come è nata un'assemblea: preparata nel programma, o una riunione di carta registrata a mano (decisione 492 (1)). */
enum OrigineAssemblea: string
{
    use ConLeParole;

    case PROGRAMMA = 'programma';
    case A_MANO = 'a_mano';

    public function parola(): string
    {
        return match ($this) {
            self::PROGRAMMA => 'Preparata nel programma',
            self::A_MANO => 'Registrata a mano',
        };
    }
}

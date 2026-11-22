<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Il perimetro di un'assemblea o di un memo (decisione 492 (14)): tutto il condominio, una palazzina, una scala. */
enum AmbitoAssemblea: string
{
    use ConLeParole;

    case CONDOMINIO = 'condominio';
    case PALAZZINA = 'palazzina';
    case SCALA = 'scala';

    public function parola(): string
    {
        return match ($this) {
            self::CONDOMINIO => 'Tutto il condominio',
            self::PALAZZINA => 'La palazzina',
            self::SCALA => 'La scala',
        };
    }
}

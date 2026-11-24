<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Il perimetro di un'assemblea o di un memo (decisione 492 (14)): tutto il condominio, una palazzina, una scala; e, **per la sola assemblea**, i condòmini di
 * una tabella segnata «di un gruppo» (Coda 734, decisione 697 (2); art. 1123 c. 3 c.c.): il gruppo è la tabella del valore dell'assemblea
 * (`assemblee.tabella_id`). Il memo, il punto e la delibera a mano non hanno il gruppo: le loro regole usano {@see valoriDeiPunti()}.
 */
enum AmbitoAssemblea: string
{
    use ConLeParole;

    case CONDOMINIO = 'condominio';
    case PALAZZINA = 'palazzina';
    case SCALA = 'scala';
    case GRUPPO = 'gruppo';

    public function parola(): string
    {
        return match ($this) {
            self::CONDOMINIO => 'Tutto il condominio',
            self::PALAZZINA => 'La palazzina',
            self::SCALA => 'La scala',
            self::GRUPPO => 'I condòmini di una tabella',
        };
    }

    /** I perimetri di un punto, di un memo e di una delibera a mano: senza il gruppo, che è solo dell'assemblea. @return list<string> */
    public static function valoriDeiPunti(): array
    {
        return [self::CONDOMINIO->value, self::PALAZZINA->value, self::SCALA->value];
    }
}

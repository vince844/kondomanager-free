<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Lo stato di una versione del preventivo fermato, come quella del rendiconto. */
enum StatoVersionePreventivo: string
{
    use ConLeParole;

    case PRESENTATA = 'presentata';
    case APPROVATA = 'approvata';
    case SOSTITUITA = 'sostituita';

    public function parola(): string
    {
        return match ($this) {
            self::PRESENTATA => 'Presentata',
            self::APPROVATA => 'Approvata',
            self::SOSTITUITA => 'Sostituita',
        };
    }
}

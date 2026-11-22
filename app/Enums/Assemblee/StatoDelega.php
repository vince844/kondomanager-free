<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Lo stato di una delega: valida, rifiutata (con l'articolo o con la scelta del presidente), revocata (sostituita da una seconda delega). */
enum StatoDelega: string
{
    use ConLeParole;

    case VALIDA = 'valida';
    case RIFIUTATA = 'rifiutata';
    case REVOCATA = 'revocata';

    public function parola(): string
    {
        return match ($this) {
            self::VALIDA => 'Valida',
            self::RIFIUTATA => 'Rifiutata',
            self::REVOCATA => 'Revocata',
        };
    }
}

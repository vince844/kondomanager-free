<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** L'esito di una delibera (decisione 523). */
enum EsitoDelibera: string
{
    use ConLeParole;

    case APPROVATA = 'approvata';
    case APPROVATA_CON_MODIFICHE = 'approvata_con_modifiche';
    case RESPINTA = 'respinta';
    case RINVIATA = 'rinviata';
    case SENZA_VOTO = 'senza_voto';

    public function parola(): string
    {
        return match ($this) {
            self::APPROVATA => 'Approvata',
            self::APPROVATA_CON_MODIFICHE => 'Approvata con modifiche',
            self::RESPINTA => 'Respinta',
            self::RINVIATA => 'Rinviata',
            self::SENZA_VOTO => 'Senza voto',
        };
    }
}

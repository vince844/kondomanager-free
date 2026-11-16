<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums;

enum StatoPianoRate: string
{
    case BOZZA = 'bozza';
    case APPROVATO = 'approvato';

    // Metodo utile per il frontend (badge colore)
    public function color(): string
    {
        return match($this) {
            self::BOZZA => 'gray',
            self::APPROVATO => 'emerald',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::BOZZA => 'Bozza',
            self::APPROVATO => 'Approvato',
        };
    }
}
<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Il tipo dell'assemblea. */
enum TipoAssemblea: string
{
    use ConLeParole;

    case ORDINARIA = 'ordinaria';
    case STRAORDINARIA = 'straordinaria';

    public function parola(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria',
            self::STRAORDINARIA => 'Straordinaria',
        };
    }
}

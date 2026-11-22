<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Il conduttore su un punto (art. 10 L. 392/1978): vota al posto del proprietario sul riscaldamento e il condizionamento, interviene senza voto sugli altri servizi. */
enum ConduttorePunto: string
{
    use ConLeParole;

    case VOTA = 'vota';
    case INTERVIENE = 'interviene';

    public function parola(): string
    {
        return match ($this) {
            self::VOTA => 'Vota al posto del proprietario',
            self::INTERVIENE => 'Interviene senza voto',
        };
    }
}

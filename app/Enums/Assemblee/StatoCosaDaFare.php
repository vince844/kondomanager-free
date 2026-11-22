<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Lo stato di una cosa da fare dopo una delibera: da fare, fatta (dal programma o con «Segna eseguita»), non serve più (con il motivo). */
enum StatoCosaDaFare: string
{
    use ConLeParole;

    case DA_FARE = 'da_fare';
    case FATTA = 'fatta';
    case NON_SERVE = 'non_serve';

    public function parola(): string
    {
        return match ($this) {
            self::DA_FARE => 'Da fare',
            self::FATTA => 'Fatta',
            self::NON_SERVE => 'Non serve più',
        };
    }
}

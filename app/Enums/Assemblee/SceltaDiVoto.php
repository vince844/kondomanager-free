<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Il voto di una testa: favorevole, contrario, astenuto; discorde quando i comproprietari presenti votano in modo diverso. */
enum SceltaDiVoto: string
{
    use ConLeParole;

    case FAVOREVOLE = 'favorevole';
    case CONTRARIO = 'contrario';
    case ASTENUTO = 'astenuto';
    case DISCORDE = 'discorde';

    public function parola(): string
    {
        return match ($this) {
            self::FAVOREVOLE => 'Favorevole',
            self::CONTRARIO => 'Contrario',
            self::ASTENUTO => 'Astenuto',
            self::DISCORDE => 'Discorde',
        };
    }
}

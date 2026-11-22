<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use RuntimeException;

/** La casella PEC dello studio non è impostata: niente parte, e la pagina lo dice con questa frase. */
final class CasellaPecNonImpostata extends RuntimeException
{
    public const FRASE = 'La casella PEC dello studio non è impostata: le PEC non partono dal programma.';

    public function __construct()
    {
        parent::__construct(self::FRASE);
    }
}

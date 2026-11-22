<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** A chi è il compito di un memo con la data (decisione 501). */
enum AChiMemo: string
{
    use ConLeParole;

    case ME = 'me';
    case STUDIO = 'studio';
    case COLLABORATORE = 'collaboratore';

    public function parola(): string
    {
        return match ($this) {
            self::ME => 'A me',
            self::STUDIO => 'A tutto lo studio',
            self::COLLABORATORE => 'A un collaboratore',
        };
    }
}

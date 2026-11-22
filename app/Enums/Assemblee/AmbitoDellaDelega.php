<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Per che cosa l'assemblea dà la delega all'amministratore (decisione 622 (3)): uno dei «maggiori poteri conferitigli … dall'assemblea» (art. 1131 c. 1 c.c.).
 * Una voce sola adesso, gli accordi con i fornitori (lo stralcio del debito, nella sua beta); i tetti della spesa urgente verranno dopo.
 */
enum AmbitoDellaDelega: string
{
    use ConLeParole;

    case ACCORDI_FORNITORI = 'accordi_fornitori';

    public function parola(): string
    {
        return match ($this) {
            self::ACCORDI_FORNITORI => 'Accordi con i fornitori',
        };
    }
}

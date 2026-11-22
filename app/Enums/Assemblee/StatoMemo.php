<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Lo stato di un memo «Da portare in assemblea» (decisione 499). */
enum StatoMemo: string
{
    use ConLeParole;

    case IN_ATTESA = 'in_attesa';
    case NEL_PUNTO = 'nel_punto';
    case DELIBERATO = 'deliberato';
    case NON_SERVE = 'non_serve';

    public function parola(): string
    {
        return match ($this) {
            self::IN_ATTESA => 'In attesa',
            self::NEL_PUNTO => 'Nell\'ordine del giorno',
            self::DELIBERATO => 'Deliberato',
            self::NON_SERVE => 'Non serve più',
        };
    }
}

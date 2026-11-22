<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** L'esito di un invio della convocazione (§1.2): che cosa è successo dopo la spedizione. */
enum EsitoInvio: string
{
    use ConLeParole;

    case CONSEGNATA = 'consegnata';
    case IN_GIACENZA = 'in_giacenza';
    case RIFIUTATA = 'rifiutata';
    case NON_CONSEGNATA = 'non_consegnata';

    public function parola(): string
    {
        return match ($this) {
            self::CONSEGNATA => 'Consegnata',
            self::IN_GIACENZA => 'In giacenza',
            self::RIFIUTATA => 'Rifiutata',
            self::NON_CONSEGNATA => 'Non consegnata',
        };
    }
}

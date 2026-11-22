<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Come è arrivata una delega: segnata in sala, su carta prima dell'assemblea («Deleghe ricevute»), o dal portale dei condòmini quando ci sarà. */
enum ArrivoDellaDelega: string
{
    use ConLeParole;

    case IN_SALA = 'in_sala';
    case CARTA_PRIMA = 'carta_prima';
    case PORTALE = 'portale';

    public function parola(): string
    {
        return match ($this) {
            self::IN_SALA => 'Segnata in sala',
            self::CARTA_PRIMA => 'Su carta, prima dell\'assemblea',
            self::PORTALE => 'Dal portale',
        };
    }
}

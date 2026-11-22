<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** L'esito di un gesto della sala: applicato; doppio (lo stesso numero è già arrivato e conta una volta); in conflitto (due versioni: sceglie l'amministratore); rifiutato con il perché. */
enum EsitoDelGesto: string
{
    use ConLeParole;

    case APPLICATO = 'applicato';
    case DOPPIO = 'doppio';
    case IN_CONFLITTO = 'in_conflitto';
    case RIFIUTATO = 'rifiutato';

    public function parola(): string
    {
        return match ($this) {
            self::APPLICATO => 'Applicato',
            self::DOPPIO => 'Già arrivato',
            self::IN_CONFLITTO => 'Due versioni',
            self::RIFIUTATO => 'Rifiutato',
        };
    }
}

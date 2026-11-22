<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Lo stato del verbale di una riunione: «In sala» mentre si scrive la discussione dei punti, «Bozza» dopo la generazione dal modello, «Registrato» con la
 * copia sottoscritta e il numero del registro, «Annullato» quando la registrazione è stata annullata con il perché (la riga resta nel registro).
 * L'assemblea resta «Chiusa»: il verbale ha il suo stato.
 */
enum StatoVerbale: string
{
    use ConLeParole;

    case IN_SALA = 'in_sala';
    case BOZZA = 'bozza';
    case REGISTRATO = 'registrato';
    case ANNULLATO = 'annullato';

    public function parola(): string
    {
        return match ($this) {
            self::IN_SALA => 'In sala',
            self::BOZZA => 'Bozza',
            self::REGISTRATO => 'Registrato',
            self::ANNULLATO => 'Annullato',
        };
    }
}

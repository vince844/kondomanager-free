<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Lo stato di una riga del registro di nomina e revoca: «Proposta» dalla delibera di un verbale registrato, finché l'amministratore non la conferma (e non va
 * nella stampa); «Confermata»; «Annullata» con il perché, perché una riga sbagliata non si cancella.
 */
enum StatoNomina: string
{
    use ConLeParole;

    case PROPOSTA = 'proposta';
    case CONFERMATA = 'confermata';
    case ANNULLATA = 'annullata';

    public function parola(): string
    {
        return match ($this) {
            self::PROPOSTA => 'Proposta',
            self::CONFERMATA => 'Confermata',
            self::ANNULLATA => 'Annullata',
        };
    }
}

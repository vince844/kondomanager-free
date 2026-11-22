<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Lo stato di un'assemblea. «Registrata a mano» vale anche per un'assemblea preparata nel programma e tenuta su carta (R16, «Tenuta su carta»).
 * La sala aggiunge «In corso» (dall'apertura alla chiusura, anche sospesa fino alla data dell'avviso in cui prosegue), «Deserta» (tutte le sedute deserte) e
 * «Chiusa»; il verbale aggiungerà i suoi. Nel database è una stringa.
 */
enum StatoAssemblea: string
{
    use ConLeParole;

    case IN_PREPARAZIONE = 'in_preparazione';
    case CONVOCATA = 'convocata';
    case ANNULLATA = 'annullata';
    case REGISTRATA_A_MANO = 'registrata_a_mano';
    case IN_CORSO = 'in_corso';
    case DESERTA = 'deserta';
    case CHIUSA = 'chiusa';

    public function parola(): string
    {
        return match ($this) {
            self::IN_PREPARAZIONE => 'In preparazione',
            self::CONVOCATA => 'Convocata',
            self::ANNULLATA => 'Annullata',
            self::REGISTRATA_A_MANO => 'Registrata a mano',
            self::IN_CORSO => 'In corso',
            self::DESERTA => 'Deserta',
            self::CHIUSA => 'Chiusa',
        };
    }
}

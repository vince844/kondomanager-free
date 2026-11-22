<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Il ruolo di una delibera per una scheda (§1.4): la stessa delibera può essere la delibera di un piano e la ratifica di una fattura. */
enum RuoloCollegamento: string
{
    use ConLeParole;

    case DELIBERA = 'delibera';
    case RIFERIMENTO = 'riferimento';
    case RATIFICA = 'ratifica';
    case COMPETENZA = 'competenza';
    case ORIGINE_DELLA_SPESA = 'origine_della_spesa';
    case DISPENSA = 'dispensa';
    case REVOCA_DELLA_DISPENSA = 'revoca_della_dispensa';
    case AUTORIZZAZIONE = 'autorizzazione';
    case FONTE = 'fonte';
    case BASE = 'base';
    case DEROGA = 'deroga';
    case NOTA = 'nota';
    case CAUSALE = 'causale';
    case APPROVAZIONE = 'approvazione';
    case DELEGA = 'delega';
    case RIPARTO = 'riparto';

    public function parola(): string
    {
        return match ($this) {
            self::DELIBERA => 'La delibera',
            self::RIFERIMENTO => 'Riferito all\'assemblea',
            self::RATIFICA => 'La ratifica',
            self::COMPETENZA => 'La competenza della spesa',
            self::ORIGINE_DELLA_SPESA => 'L\'origine della spesa',
            self::DISPENSA => 'La dispensa',
            self::REVOCA_DELLA_DISPENSA => 'La revoca della dispensa',
            self::AUTORIZZAZIONE => 'L\'autorizzazione',
            self::FONTE => 'La fonte della regola',
            self::BASE => 'La base dell\'addebito',
            self::DEROGA => 'La deroga',
            self::NOTA => 'Una nota',
            self::CAUSALE => 'La causale',
            self::APPROVAZIONE => 'L\'approvazione',
            self::DELEGA => 'La delega',
            self::RIPARTO => 'Lo stato di riparto',
        };
    }
}

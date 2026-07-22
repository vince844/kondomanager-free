<?php

namespace App\Enums\Fiscale;

/**
 * Decide se l'importo va al punto 9 (ACCONTO, la norma) o al punto 10
 * (IMPOSTA, solo il 30% ai non residenti) della Certificazione Unica.
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum TitoloRitenuta: string
{
    case ACCONTO = 'acconto';
    case IMPOSTA = 'imposta';
}

<?php

namespace App\Enums\Fiscale;

/**
 * Come si accumula la soglia sotto la quale il versamento può essere rinviato.
 * Design: docs/design/f24_ritenute_design.md §2.2.
 */
enum PlafondRitenuta: string
{
    /** Soglia 500€, tre finestre annuali (gen-mag / giu-set / ott-dic) — art. 25-ter. */
    case SOGLIA_500_TRE_FINESTRE = 'soglia_500_tre_finestre';

    /** Soglia 100€ annuale (D.Lgs. 1/2024) — artt. 25 e 25-bis. */
    case SOGLIA_100_ANNUALE = 'soglia_100_annuale';

    /** Nessun rinvio possibile: si versa ogni mese a prescindere dall'importo. */
    case MENSILE_SEMPRE = 'mensile_sempre';
}

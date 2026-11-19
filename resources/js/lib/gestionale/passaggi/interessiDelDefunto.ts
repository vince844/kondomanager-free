// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 483: nella successione, gli interessi del defunto dopo il decesso. Di serie «si fermano al decesso» con l'arretrato agli eredi, «continuano a suo
 * nome» con l'arretrato a nome del defunto (anche con il legato): la stessa regola del server (`Subentro::interessiDiSerie`).
 *
 * Il modulo tiene la scelta dell'amministratore solo quando la tocca (`null` finché non la tocca): cambiando la strada dell'arretrato, la scelta non toccata
 * segue il di serie della strada nuova; quella toccata resta com'è.
 */
import type { SceltaInteressiDelDefunto } from '@/types/gestionale/passaggi';

export type StradaDellArretrato = 'eredi' | 'defunto';

/** La scelta di serie della strada dell'arretrato. */
export function interessiDiSerie(strada: StradaDellArretrato | null | undefined): SceltaInteressiDelDefunto {
    return strada === 'eredi' ? 'al_decesso' : 'continuano';
}

/** La scelta che vale: quella dell'amministratore se l'ha toccata, altrimenti il di serie della strada. */
export function interessiCheValgono(toccata: SceltaInteressiDelDefunto | null | undefined, strada: StradaDellArretrato | null | undefined): SceltaInteressiDelDefunto {
    return toccata ?? interessiDiSerie(strada);
}

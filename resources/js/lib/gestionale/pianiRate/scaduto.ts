/**
 * Lo scaduto della pagina del piano rate, per (persona o unità, numero rata), con le regole comuni dei debiti: conta solo una rata
 * **emessa** (una quota a giornale) che il server dice **scaduta** (il giorno della scadenza non è scaduto, nel giorno
 * dell'amministratore: `App\Services\Gestionale\Debiti\Scadenza`). Il browser non confronta date: fino alla 1.12.0-beta.2 la
 * pagina sommava anche le bozze e la rata del giorno stesso, con l'orologio del browser.
 *
 * Centesimi interi, come il payload di `PianoRateQuoteService`.
 */

export interface RataDelPiano {
    emessa?: boolean;
    scaduta?: boolean;
    stato: string;
    residuo: number;
}

/** Il badge «Scaduta» di una cella: una rata emessa, scaduta e ancora da pagare (la parziale ha il suo). */
export function rataScaduta(rata: RataDelPiano): boolean {
    return rata.emessa === true && rata.scaduta === true && rata.stato === 'da_pagare';
}

/** Lo scaduto da incassare: i residui delle rate emesse e scadute. Il residuo è già al netto di quanto versato e mai negativo. */
export function scadutoDaIncassare(rate: RataDelPiano[]): number {
    return rate.reduce((somma, r) => (r.emessa === true && r.scaduta === true ? somma + r.residuo : somma), 0);
}

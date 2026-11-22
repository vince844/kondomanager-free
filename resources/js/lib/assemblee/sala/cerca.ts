// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La ricerca nella sala, per nome o per unità: filtra le righe che la pagina ha già, senza chiedere niente al server, quindi vale anche senza rete. Ogni
 * parola cercata dev'essere l'inizio di una parola della riga, senza maiuscole né accenti: «colo» trova Colombo, «12» l'Int. 12 e non l'Int. 112.
 *
 * La stessa ricerca sta sopra gli elenchi lunghi delle finestre del modulo («Registra la spedizione», il foglio delle consegne a mano, i voti di un punto),
 * da `DA_QUANTI_SI_CERCA` persone in su.
 */

/** Da quante persone in su un elenco ha la ricerca sopra: sotto, si vede tutto a colpo d'occhio. */
export const DA_QUANTI_SI_CERCA = 8;

/** Le parole di un testo per la ricerca: minuscole, senza accenti, divise su spazi e segni («Int. 12/A» → int, 12, a). */
export function paroleDellaRicerca(testo: string): string[] {
    return testo.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
}

/** La riga risponde quando ogni parola cercata è l'inizio di una delle sue parole; la ricerca vuota le tiene tutte. */
export function rispondeAllaRicerca(cercato: string, testi: string[]): boolean {
    const cercate = paroleDellaRicerca(cercato);
    if (cercate.length === 0) return true;
    const parole = testi.flatMap(paroleDellaRicerca);

    return cercate.every((c) => parole.some((p) => p.startsWith(c)));
}

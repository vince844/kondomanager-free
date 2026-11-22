// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 688, «Urgenza» su una gestione straordinaria: le scelte del menu «La delibera, o l'urgenza» e le frasi del giorno dei lavori, sulle due schede del
 * nuovo piano e sulla fattura registrata dopo. Un giorno solo, non un periodo: chi era titolare quel giorno paga tutto.
 */
import { giornoItaliano } from '@/lib/giorno';

export const SCELTE_DELL_AUTORIZZAZIONE = [
    { valore: 'delibera', nome: 'Delibera dell\'assemblea' },
    { valore: 'urgenza', nome: 'Urgenza (art. 1135 c.c.)' },
];

/** «Chi era titolare il 02/03/2027 paga tutto: il giorno decide chi paga, non quanto.» */
export function fraseDelGiornoDeiLavori(giorno: string | null | undefined): string {
    if (!giorno) return 'Scegli il giorno: decide chi paga, non quanto.';
    return `Chi era titolare il ${giornoItaliano(giorno)} paga tutto: il giorno decide chi paga, non quanto.`;
}

/**
 * I campi dell'urgenza che il piano manda: il giorno, solo con «Urgenza», sulle due schede (sulle fatture la fattura senza periodo lo prende). Il server lo
 * scrive solo su una gestione straordinaria. `scheda` resta per chi la passa: le due schede oggi mandano la stessa cosa.
 */
export function campiDellUrgenza(tipo: string, _scheda: 'voci' | 'fatture', giorno: string): { giorno_dei_lavori: string | null } {
    return { giorno_dei_lavori: tipo === 'urgenza' && giorno ? giorno : null };
}

/**
 * Sulla scheda delle fatture, sotto il giorno: che cosa fa con le fatture del carrello. «La fattura senza periodo prende questo giorno; quella con il suo
 * periodo tiene il suo.», e quante lo prendono.
 */
export function fraseDelleFatture(giorno: string | null | undefined, senzaPeriodo: number): string {
    const regola = 'La fattura senza periodo prende questo giorno; quella con il suo periodo («Costo maturato dal … al …») tiene il suo.';
    if (senzaPeriodo <= 0) return regola;
    const quante = senzaPeriodo === 1 ? '1 fattura scelta non dice il periodo' : `${senzaPeriodo} fatture scelte non dicono il periodo`;
    if (!giorno) return `${regola} ${quante}.`;

    return `${regola} ${quante}: ${senzaPeriodo === 1 ? 'prende' : 'prendono'} il ${giornoItaliano(giorno)}.`;
}

/** La competenza che una voce ha in un piano rate (`CompetenzaDichiarataPerVoce`): `urgenza` quando il piano è d'urgenza. */
export interface CompetenzaDellaVoce {
    piano: string;
    tratti: { dal: string; al: string }[];
    urgenza?: boolean;
}

/** Il giorno dei lavori di un piano d'urgenza, con il nome del piano. */
export interface LavoriUrgenti {
    piano: string;
    giorno: string;
}

/**
 * La fattura registrata dopo: il giorno dei lavori del piano d'urgenza di una delle sue voci, la prima che lo dice con un giorno solo. Null se nessuna voce
 * sta in un piano d'urgenza.
 */
export function lavoriUrgentiDelleVoci(competenze: (CompetenzaDellaVoce | null | undefined)[]): LavoriUrgenti | null {
    for (const c of competenze) {
        const t = c?.tratti ?? [];
        if (c?.urgenza && t.length === 1 && t[0].dal === t[0].al) return { piano: c.piano, giorno: t[0].dal };
    }

    return null;
}

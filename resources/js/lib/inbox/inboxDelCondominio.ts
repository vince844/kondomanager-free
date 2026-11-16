// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole della riga dell'Inbox operativa della dashboard del condominio. Il gruppo della riga lo manda il server con la regola di `FasceDeiCompiti`, la
 * stessa dell'Action inbox: un compito di oggi non è scaduto; le segnalazioni aperte e i bonifici segnalati dai condòmini non hanno una scadenza e non scadono.
 * Qui come si dice la data: lo scaduto in rosso con i giorni, la segnalazione «aperta il … · da N giorni» e il bonifico «segnalato il … · da N giorni» in
 * grigio, gli altri «scade il … · fra N giorni». E le schede «Tutte», «Scadute», «In arrivo» nella testata (come nell'Action inbox: il filtro lo applica il
 * server, con la stessa regola) e le loro frasi.
 */
import { giornoItaliano, testoQuando, type Gruppo } from '@/lib/inbox/actionInbox';

/** «scaduto il 13/11/2026 · da 2 giorni», «aperta il 22/02/2026 · da 267 giorni», «segnalato il 10/11/2026 · da 5 giorni», «scade oggi», «scade il … · fra 12 giorni». */
export function dataDellaRiga(r: { gruppo: Gruppo; giorno: string; giorni: number }): string {
    const data = giornoItaliano(r.giorno);
    switch (r.gruppo) {
        case 'scaduti':
            return `scaduto il ${data} · ${testoQuando(r.giorni)}`;
        case 'segnalazioni':
            return `aperta il ${data} · ${testoQuando(r.giorni)}`;
        case 'bonifici':
            return `segnalato il ${data} · ${testoQuando(r.giorni)}`;
        default:
            if (r.giorni === 0) return 'scade oggi';
            if (r.giorni === 1) return 'scade domani';

            return `scade il ${data} · ${testoQuando(r.giorni)}`;
    }
}

/** Il colore della data: rosso solo lo scaduto; la segnalazione e il bonifico, che non scadono, e i compiti in arrivo in grigio. */
export const coloreDellaData = (r: { gruppo: Gruppo }): string => (r.gruppo === 'scaduti' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400');

/** Le schede dell'Inbox operativa: la chiave è il filtro dell'indirizzo (`?inbox=`), «Tutte» senza filtro. */
export type SchedaDellaInbox = 'tutti' | 'scaduti' | 'in_arrivo';
export const SCHEDE_DELLA_INBOX: ReadonlyArray<{ chiave: SchedaDellaInbox; etichetta: string }> = [
    { chiave: 'tutti', etichetta: 'Tutte' },
    { chiave: 'scaduti', etichetta: 'Scadute' },
    { chiave: 'in_arrivo', etichetta: 'In arrivo' },
];

/** Un numero all'italiana, con il punto delle migliaia anche a quattro cifre: «1.250». */
export const cifraItaliana = (n: number): string => new Intl.NumberFormat('it-IT', { useGrouping: 'always' } as Intl.NumberFormatOptions).format(n);
const numero = cifraItaliana;

/** I compiti dei sei mesi di una scheda: con la data passata in «Scadute», da oggi in poi in «In arrivo» (un compito di oggi non è scaduto), tutti in «Tutte». */
export function compitiDellaScheda<T extends { giorni: number }>(compiti: readonly T[], scheda: SchedaDellaInbox): T[] {
    if (scheda === 'scaduti') return compiti.filter((c) => c.giorni < 0);
    if (scheda === 'in_arrivo') return compiti.filter((c) => c.giorni >= 0);

    return [...compiti];
}

/** La riga sotto le schede «Scadute» e «In arrivo» quando ci sono segnalazioni o bonifici segnalati: non scadono, e stanno in «Tutte». */
export function testoFuoriDalleSchede(scheda: SchedaDellaInbox, quanti: number): string | null {
    if (scheda === 'tutti' || quanti <= 0) return null;

    return `Le segnalazioni e i bonifici segnalati non hanno una scadenza: ${quanti === 1 ? '1 è' : `${numero(quanti)} sono`} in «Tutte».`;
}

/** La scheda «Scadute» o «In arrivo» senza compiti. */
export const testoSchedaVuota = (scheda: SchedaDellaInbox): string => (scheda === 'scaduti' ? 'Nessuna attività scaduta.' : 'Nessuna attività in arrivo.');

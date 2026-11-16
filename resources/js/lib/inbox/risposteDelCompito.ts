// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le risposte ai compiti dell'agenda nelle due Inbox (la casella delle attività dello studio e l'Inbox operativa del condominio): «Fatto, con una nota»,
 * «Non serve, con il motivo» (sul bonifico segnalato dal portale è il rifiuto della segnalazione, che il condòmino legge), «Ricordamelo il…», e «Il registro»
 * quando il compito ha già delle risposte. Il compito di una segnalazione di guasto ha solo «Ricordamelo»: si chiude con la segnalazione. Le scrive il server (`admin.inbox.risposta`); qui ci sono le voci del menu e le parole delle finestre.
 */

export type RispostaAlCompito = 'fatto' | 'non_serve' | 'ricordamelo';
export type VoceDelCompito = RispostaAlCompito | 'registro';

export interface RigaDelRegistro {
    tipo: string;
    quando: string;
    utente: string;
    nota: string;
    /** Per «Ricordamelo», il giorno scelto (`giorno`). */
    dati?: Record<string, unknown>;
}

/** La riga di un compito come la mandano le due Inbox: ciò che serve alle risposte. */
export interface CompitoConRisposte {
    id: number;
    type: string;
    title: string;
    /** Per i tipi in cui il giorno dell'evento è una scadenza, e per i commenti e gli allegati: «Ricordamelo» scrive un promemoria a parte. */
    promemoria_a_parte?: boolean;
    /** La scadenza (Y-m-d nel giorno dell'amministratore), per i tipi con la scadenza. */
    scadenza?: string | null;
    /** Sul bonifico segnalato ancora in verifica, perché «Fatto» è spento. */
    fatto_spento?: string | null;
    risposta?: { tornato: string | null; registro: RigaDelRegistro[] } | null;
}

/** Il bonifico segnalato dal portale. */
export const eUnBonificoSegnalato = (c: Pick<CompitoConRisposte, 'type'>): boolean => c.type === 'verifica_pagamento';

/** La segnalazione di guasto: il suo compito si chiude quando si chiude la segnalazione, e il menu ha solo «Ricordamelo». */
export const eUnaSegnalazioneDiGuasto = (c: Pick<CompitoConRisposte, 'type'>): boolean => c.type === 'segnalazione_guasto';

/** Le voci del menu «⋯» del compito, nell'ordine; una voce spenta porta la frase che dice perché. */
export function vociDelMenu(c: CompitoConRisposte): { tipo: VoceDelCompito; etichetta: string; spenta?: string }[] {
    const voci: { tipo: VoceDelCompito; etichetta: string; spenta?: string }[] = eUnaSegnalazioneDiGuasto(c)
        ? [{ tipo: 'ricordamelo', etichetta: 'Ricordamelo il…' }]
        : [
            { tipo: 'fatto', etichetta: 'Fatto, con una nota', ...(c.fatto_spento ? { spenta: c.fatto_spento } : {}) },
            { tipo: 'non_serve', etichetta: eUnBonificoSegnalato(c) ? 'Non serve: rifiuta la segnalazione' : 'Non serve, con il motivo' },
            { tipo: 'ricordamelo', etichetta: 'Ricordamelo il…' },
        ];
    if (c.risposta?.registro?.length) {
        voci.push({ tipo: 'registro', etichetta: 'Il registro' });
    }

    return voci;
}

/** Il nome di un gesto nel registro; «Ricordamelo» con il giorno scelto. */
export function testoDelGestoDelCompito(tipo: string, dati?: Record<string, unknown>): string {
    if (tipo === 'ricordamelo' && typeof dati?.giorno === 'string') {
        return `Ricordamelo il ${giornoItaliano(dati.giorno)}`;
    }

    return ({ fatto: 'Fatto', non_serve: 'Non serve', ricordamelo: 'Ricordamelo' } as Record<string, string>)[tipo] ?? tipo;
}

/** «30/06/2026» da «2026-06-30», senza passare dal calendario del browser. */
export const giornoItaliano = (giorno: string): string => giorno.slice(0, 10).split('-').reverse().join('/');

/** Che cosa dice la finestra di «Ricordamelo» sul promemoria a parte, secondo il tipo del compito. */
export function fraseDelPromemoriaAParte(tipo: string): string {
    if (tipo === 'commento') return 'Torna prima se arriva un commento nuovo. Il promemoria è un evento a parte, nell\'agenda.';
    if (tipo === 'allegato') return 'Torna prima se arriva un allegato nuovo. Il promemoria è un evento a parte, nell\'agenda.';
    if (tipo === 'segnalazione_guasto') return 'La segnalazione resta aperta dal giorno in cui è arrivata: il promemoria è un evento a parte, nell\'agenda.';
    if (tipo === 'verifica_pagamento') return 'Il bonifico resta segnalato dal giorno in cui è arrivato: il promemoria è un evento a parte, nell\'agenda.';

    return 'Il compito resta nell\'agenda al suo giorno (la scadenza): il promemoria è un evento a parte.';
}

/** Il giorno scelto viene dopo la scadenza: fino a quel giorno il compito non si vede. */
export const fraseDopoLaScadenza = (scadenza: string): string =>
    `Il giorno scelto viene dopo la scadenza del ${giornoItaliano(scadenza)}: fino a quel giorno il compito non è nell'Inbox.`;

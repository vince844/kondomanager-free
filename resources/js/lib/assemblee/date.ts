// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il controllo delle date dell'avviso mentre si scrivono (R11 del progetto della tappa 2): lo stesso del server (`ControlloDelleDate::controlla`), con le
 * stesse frasi, provato sugli stessi casi (`tests/Fixtures/casi_delle_date_assemblea.json`, che un test PHP confronta con `ControlloDelleDate::CASI`).
 *
 * La seconda convocazione in un giorno di calendario diverso e successivo alla prima, non oltre dieci giorni (art. 1136 c. 3 c.c.; art. 66 disp. att.); le
 * date di prosecuzione vengono dopo quella che proseguono (art. 66 c. 5). Le ore sono «come si leggono»: si confrontano i giorni scritti, senza fusi.
 */
import type { ControlloDelleDate, TerminiDellaPrima } from '@/types/assemblee';

export const FRASE_MANCA_LA_PRIMA = 'Manca la prima convocazione.';
export const FRASE_STESSO_GIORNO = 'La seconda convocazione non può tenersi nello stesso giorno della prima: scegli un giorno dopo.';
export const FRASE_PRIMA_DELLA_PRIMA = 'La seconda convocazione viene dopo la prima: scegli un giorno dopo.';
export const FRASE_OLTRE_DIECI = 'La seconda convocazione va fissata non oltre dieci giorni dalla prima.';
export const FRASE_SOLO_LA_PRIMA = 'Solo la prima convocazione: se quel giorno non si raggiunge il numero, l\'assemblea va convocata di nuovo.';
export const FRASE_PROSECUZIONE = 'Una data di prosecuzione deve venire dopo la data precedente: scegli un giorno dopo.';
export const FRASE_PROSECUZIONE_SENZA_GIORNO = 'Manca il giorno di una data di prosecuzione: scrivilo o togli la riga.';
export const FRASE_DOPPIA = 'Due date hanno la stessa convocazione e lo stesso numero.';
export const FRASE_PROSECUZIONE_SENZA_SECONDA = 'Una data di prosecuzione segue la seconda convocazione: scrivi prima la seconda, o togli la riga.';

export interface SedutaDaControllare {
    convocazione: string;
    numero?: number | string | null;
    /** «2026-12-15 18:30» o «2026-12-15T18:30». */
    fissata_il: string | null | undefined;
}

/** Il giorno e l'ora scritti, nella forma che si confronta come testo («2026-12-15 18:30:00»), o `null` se non c'è un giorno. */
export function istante(valore: string | null | undefined): string | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/.exec((valore ?? '').trim());
    if (!m) {
        return null;
    }
    return `${m[1]}-${m[2]}-${m[3]} ${(m[4] ?? '00').padStart(2, '0')}:${m[5] ?? '00'}:${m[6] ?? '00'}`;
}

/** I giorni di calendario fra due giorni scritti, senza fusi (ora legale compresa). */
export function giorniFra(da: string, a: string): number {
    const g = (s: string) => {
        const [y, m, d] = s.slice(0, 10).split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    return Math.round((g(a) - g(da)) / 86_400_000);
}

export function controllaLeDate(sedute: SedutaDaControllare[]): ControlloDelleDate {
    const visti = new Set<string>();
    const per: Record<'prima' | 'seconda', Map<number, string | null>> = { prima: new Map(), seconda: new Map() };
    for (const s of sedute) {
        const conv = String(s.convocazione ?? '');
        const numero = Number(s.numero ?? 1) || 1;
        const chiave = `${conv}:${numero}`;
        if (visti.has(chiave)) {
            return { valide: false, frase: FRASE_DOPPIA };
        }
        visti.add(chiave);
        const quando = istante(s.fissata_il);
        // La seconda convocazione ancora senza giorno non c'è: non è una prosecuzione senza giorno.
        if ((conv === 'prima' || conv === 'seconda') && !(conv === 'seconda' && numero === 1 && quando === null)) {
            per[conv].set(numero, quando);
        }
    }
    const prima = per.prima.get(1) ?? null;
    if (prima === null) {
        return { valide: false, frase: FRASE_MANCA_LA_PRIMA };
    }
    // Le prosecuzioni seguono la seconda convocazione: senza la seconda non c'è da dove proseguire.
    if ((per.seconda.get(1) ?? null) === null && [...per.seconda.keys()].some((n) => n > 1)) {
        return { valide: false, frase: FRASE_PROSECUZIONE_SENZA_SECONDA };
    }
    for (const date of [per.prima, per.seconda]) {
        let precedente: string | null = null;
        for (const numero of [...date.keys()].sort((x, y) => x - y)) {
            const corrente = date.get(numero) ?? null;
            if (corrente === null) {
                return { valide: false, frase: FRASE_PROSECUZIONE_SENZA_GIORNO };
            }
            if (precedente !== null && corrente <= precedente) {
                return { valide: false, frase: FRASE_PROSECUZIONE };
            }
            precedente = corrente;
        }
    }
    const seconda = per.seconda.get(1) ?? null;
    if (seconda === null) {
        return { valide: true, frase: FRASE_SOLO_LA_PRIMA };
    }
    const giorni = giorniFra(prima, seconda);
    if (giorni === 0) {
        return { valide: false, frase: FRASE_STESSO_GIORNO };
    }
    if (giorni < 0) {
        return { valide: false, frase: FRASE_PRIMA_DELLA_PRIMA };
    }
    if (giorni > 10) {
        return { valide: false, frase: FRASE_OLTRE_DIECI };
    }
    return { valide: true, frase: `Un giorno diverso, ${giorni === 1 ? 'un giorno' : `${giorni} giorni`} dopo la prima: va bene.` };
}

/**
 * La frase dei cinque giorni dell'art. 66 c. 3 (decisione 662): la prima convocazione di quel giorno non ha il tempo, e i due giorni da cui lo avrebbe, per la
 * raccomandata e per la PEC o la consegna a mano, li ha calcolati il server da oggi. Non blocca. `null` se il giorno non c'è o ha il tempo.
 * È la stessa frase di `TerminiDellaConvocazione::fraseDeiCinqueGiorni()`.
 */
export function fraseDeiCinqueGiorni(prima: string | null | undefined, termini: TerminiDellaPrima | null | undefined): string | null {
    const giorno = (prima ?? '').slice(0, 10);
    if (!termini || !/^\d{4}-\d{2}-\d{2}$/.test(giorno) || giorno >= termini.dal_raccomandata) {
        return null;
    }
    const gm = (g: string) => `${g.slice(8, 10)}/${g.slice(5, 7)}`;

    return `Con la prima convocazione del ${gm(giorno)} non c'è il tempo dei cinque giorni (art. 66 c. 3): per raccomandata può essere dal ${gm(termini.dal_raccomandata)}, `
        + `per PEC o a mano dal ${gm(termini.dal_a_mano)}. Si può convocare lo stesso, ma la delibera è annullabile da chi non è convocato in tempo. `
        + 'Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).';
}

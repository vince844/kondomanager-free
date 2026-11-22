// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Chi scrive il testo nella coda (N1 e N3 del progetto del verbale), con l'orologio finto:
 *
 * - **ogni lettera sul disco**: la prima subito, le altre al più ogni 300 millisecondi per parte, con l'ultimo testo; il gesto ha la chiave di fusione e non
 *   parte da solo;
 * - **quando parte**: pochi secondi dopo la pausa, e almeno ogni 30 secondi mentre si scrive; prima di partire ciò che è in memoria va sul disco, e chi usa
 *   lo scrittore sa quali parti partono;
 * - la scheda che si chiude scrive subito; il disco che non prende il testo non lo perde.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { OpzioniDelGesto } from './coda';
import type { VoceInCoda } from './deposito';
import { ALMENO_OGNI_MS, PAUSA_MS, ScrittoreDelTesto, SUL_DISCO_OGNI_MS } from './testo';

const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
const testoDi = (d: Record<string, unknown>) => ((d.documento as NodoDelVerbale).content![0].content![0] as { text: string }).text;

function scrittore(piu: { rotto?: () => boolean } = {}) {
    const scritti: { quando: number; parte: string; testo: string; base: number; opzioni: OpzioniDelGesto }[] = [];
    const partenze: { quando: number; parti: { parte: string; il: string }[] }[] = [];
    let mandate = 0;
    const s = new ScrittoreDelTesto({
        segna: async (dati, base, opzioni) => {
            if (piu.rotto?.()) throw new Error('Il disco è pieno');
            scritti.push({ quando: Date.now(), parte: String(dati.parte), testo: testoDi(dati), base, opzioni });
            return { gesto: String(scritti.length) } as VoceInCoda;
        },
        manda: () => {
            mandate += 1;
        },
        dati: (parte, documento) => ({ verbale_id: 7, parte, documento }),
        base: () => 4,
        parte: (parti) => {
            partenze.push({ quando: Date.now(), parti });
        },
    });

    return { s, scritti, partenze, mandate: () => mandate };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-12-15T19:16:00Z'));
});
afterEach(() => vi.useRealTimers());

describe('ogni lettera sul disco', () => {
    test('la prima lettera subito, poi al più ogni 300 millisecondi per parte con l\'ultimo testo, con la chiave di fusione e senza partire', async () => {
        const { s, scritti } = scrittore();
        s.scrivi('punto:4', doc('P'));
        await vi.advanceTimersByTimeAsync(0);
        expect(scritti.map((x) => x.testo)).toEqual(['P']);
        for (const [i, t] of ['Pr', 'Pre', 'Prev', 'Preve'].entries()) {
            await vi.advanceTimersByTimeAsync(50);
            s.scrivi('punto:4', doc(t));
            expect(scritti).toHaveLength(1 + (i >= 5 ? 1 : 0));
        }
        await vi.advanceTimersByTimeAsync(SUL_DISCO_OGNI_MS);
        expect(scritti.map((x) => x.testo)).toEqual(['P', 'Preve']);
        expect(scritti[1].quando - scritti[0].quando).toBeLessThanOrEqual(SUL_DISCO_OGNI_MS);
        expect(scritti[1]).toMatchObject({ parte: 'punto:4', base: 4, opzioni: { fondi: 'testo:punto:4', parti: false } });
        // Un'altra parte ha il suo tempo: la prima lettera subito.
        s.scrivi('intero', doc('Il verbale'));
        await vi.advanceTimersByTimeAsync(0);
        expect(scritti.map((x) => x.parte)).toEqual(['punto:4', 'punto:4', 'intero']);
    });

    test('la scheda che si chiude scrive subito ciò che è in memoria', async () => {
        const { s, scritti } = scrittore();
        s.scrivi('punto:4', doc('Uno'));
        await vi.advanceTimersByTimeAsync(10);
        s.scrivi('punto:4', doc('Uno due'));
        expect(s.inScrittura('punto:4')).toEqual(doc('Uno due'));
        await s.sulDisco();
        expect(scritti.map((x) => x.testo)).toEqual(['Uno', 'Uno due']);
        expect(s.inScrittura('punto:4')).toBeNull();
    });

    test('il disco che non prende il testo non lo perde: resta in memoria, e va sul disco alla volta dopo', async () => {
        let rotto = true;
        const { s, scritti } = scrittore({ rotto: () => rotto });
        s.scrivi('punto:4', doc('Non perderlo'));
        await vi.advanceTimersByTimeAsync(0);
        expect(scritti).toEqual([]);
        expect(s.inScrittura('punto:4')).toEqual(doc('Non perderlo'));
        rotto = false;
        await s.sulDisco();
        expect(scritti.map((x) => x.testo)).toEqual(['Non perderlo']);
    });
});

describe('quando parte', () => {
    test('pochi secondi dopo la pausa, non prima; prima di partire ciò che è in memoria va sul disco, e si sa quali parti partono', async () => {
        const { s, scritti, partenze, mandate } = scrittore();
        s.scrivi('punto:4', doc('Uno'));
        await vi.advanceTimersByTimeAsync(100);
        s.scrivi('punto:4', doc('Uno due'));
        await vi.advanceTimersByTimeAsync(PAUSA_MS - 1);
        expect(mandate()).toBe(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(mandate()).toBe(1);
        expect(scritti.map((x) => x.testo)).toEqual(['Uno', 'Uno due']);
        expect(partenze).toEqual([{ quando: Date.now(), parti: [{ parte: 'punto:4', il: '2026-12-15T19:16:00.100Z' }] }]);
        expect(s.haDaMandare).toBe(false);
    });

    test('almeno ogni 30 secondi mentre si scrive senza mai fermarsi', async () => {
        const { s, mandate } = scrittore();
        for (let i = 0; i < 31; i++) {
            s.scrivi('intero', doc('lettera ' + i));
            await vi.advanceTimersByTimeAsync(1000);
        }
        expect(mandate()).toBe(1);
        expect(ALMENO_OGNI_MS).toBe(30000);
        // Smesso di scrivere, parte dopo la pausa.
        await vi.advanceTimersByTimeAsync(PAUSA_MS);
        expect(mandate()).toBe(2);
    });

    test('«mandaOra» (la rete che torna, prima di stampare la bozza) parte subito con tutto sul disco', async () => {
        const { s, scritti, mandate } = scrittore();
        s.scrivi('punto:4', doc('A'));
        s.scrivi('punto:4', doc('AB'));
        await s.mandaOra();
        // Sul disco c'è l'ultimo testo: le due lettere scritte nello stesso istante fanno una scrittura sola.
        expect(scritti.map((x) => x.testo)).toEqual(['AB']);
        expect(mandate()).toBe(1);
        await vi.advanceTimersByTimeAsync(ALMENO_OGNI_MS * 2);
        expect(mandate()).toBe(1);
    });
});

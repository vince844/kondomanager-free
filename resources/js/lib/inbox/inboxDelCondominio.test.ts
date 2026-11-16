// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La riga della data nell'Inbox operativa del condominio, con il gruppo che manda il server (la regola di `FasceDeiCompiti`, la stessa dell'Action inbox):
 * lo scaduto «scaduto il … · da N giorni» in rosso; la segnalazione «aperta il … · da N giorni» e il bonifico segnalato «segnalato il … · da N giorni» in
 * grigio, perché non hanno una scadenza; gli altri «scade oggi», «scade domani», «scade il … · fra N giorni». Le schede «Tutte», «Scadute», «In arrivo», con
 * la riga per le segnalazioni e i bonifici, che stanno in «Tutte»; il cartellino in alto a destra non c'è più (le schede nella testata dicono le scadute).
 */
import { describe, expect, test } from 'vitest';
import * as inbox from './inboxDelCondominio';
import { SCHEDE_DELLA_INBOX, cifraItaliana, coloreDellaData, compitiDellaScheda, dataDellaRiga, testoFuoriDalleSchede, testoSchedaVuota } from './inboxDelCondominio';

describe('la data della riga', () => {
    test('scaduto, segnalazione, bonifico, in arrivo', () => {
        expect(dataDellaRiga({ gruppo: 'scaduti', giorno: '2026-11-13', giorni: -2 })).toBe('scaduto il 13/11/2026 · da 2 giorni');
        expect(dataDellaRiga({ gruppo: 'scaduti', giorno: '2026-11-14', giorni: -1 })).toBe('scaduto il 14/11/2026 · da ieri');
        expect(dataDellaRiga({ gruppo: 'segnalazioni', giorno: '2026-02-22', giorni: -267 })).toBe('aperta il 22/02/2026 · da 267 giorni');
        expect(dataDellaRiga({ gruppo: 'segnalazioni', giorno: '2026-11-15', giorni: 0 })).toBe('aperta il 15/11/2026 · oggi');
        expect(dataDellaRiga({ gruppo: 'bonifici', giorno: '2026-11-10', giorni: -5 })).toBe('segnalato il 10/11/2026 · da 5 giorni');
        expect(dataDellaRiga({ gruppo: 'avanti', giorno: '2026-11-15', giorni: 0 })).toBe('scade oggi');
        expect(dataDellaRiga({ gruppo: 'avanti', giorno: '2026-11-16', giorni: 1 })).toBe('scade domani');
        expect(dataDellaRiga({ gruppo: 'avanti', giorno: '2026-11-27', giorni: 12 })).toBe('scade il 27/11/2026 · fra 12 giorni');
    });

    test('rosso solo lo scaduto; gli altri grigi', () => {
        expect(coloreDellaData({ gruppo: 'scaduti' })).toBe('text-red-600 dark:text-red-400');
        for (const gruppo of ['segnalazioni', 'bonifici', 'avanti'] as const) {
            expect(coloreDellaData({ gruppo })).toBe('text-slate-500 dark:text-slate-400');
        }
    });
});

describe('il cartellino non c\'è più', () => {
    test('le schede nella testata dicono già quante sono le scadute: niente funzione del cartellino; i numeri all\'italiana restano', () => {
        expect('testoDelCartellino' in inbox).toBe(false);
        expect(cifraItaliana(1250)).toBe('1.250');
    });
});

describe('le schede dell\'Inbox operativa', () => {
    test('«Tutte», «Scadute», «In arrivo», come nell\'Action inbox', () => {
        expect(SCHEDE_DELLA_INBOX.map((s) => s.etichetta)).toEqual(['Tutte', 'Scadute', 'In arrivo']);
        expect(SCHEDE_DELLA_INBOX.map((s) => s.chiave)).toEqual(['tutti', 'scaduti', 'in_arrivo']);
    });

    test('i compiti dei sei mesi: con la data passata in «Scadute», da oggi in poi in «In arrivo», tutti in «Tutte»', () => {
        const c = [{ giorni: -3 }, { giorni: 0 }, { giorni: 12 }];
        expect(compitiDellaScheda(c, 'tutti')).toEqual(c);
        expect(compitiDellaScheda(c, 'scaduti')).toEqual([{ giorni: -3 }]);
        expect(compitiDellaScheda(c, 'in_arrivo')).toEqual([{ giorni: 0 }, { giorni: 12 }]);
    });

    test('la riga sotto le schede: le segnalazioni e i bonifici, che non scadono, stanno in «Tutte»', () => {
        expect(testoFuoriDalleSchede('scaduti', 2)).toBe('Le segnalazioni e i bonifici segnalati non hanno una scadenza: 2 sono in «Tutte».');
        expect(testoFuoriDalleSchede('in_arrivo', 1)).toBe('Le segnalazioni e i bonifici segnalati non hanno una scadenza: 1 è in «Tutte».');
        expect(testoFuoriDalleSchede('tutti', 2)).toBeNull();
        expect(testoFuoriDalleSchede('scaduti', 0)).toBeNull();
    });

    test('la scheda vuota lo dice', () => {
        expect(testoSchedaVuota('scaduti')).toBe('Nessuna attività scaduta.');
        expect(testoSchedaVuota('in_arrivo')).toBe('Nessuna attività in arrivo.');
    });
});

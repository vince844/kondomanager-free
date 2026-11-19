// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'assegnazione delle segnalazioni (decisione 294): le due funzioni che le pagine usano.
 *
 * - `opzioniFiltroAssegnazione(assegnatari, io)`: le voci del filtro «Assegnata a» dell'elenco. Prima «A me» (`mie`) e
 *   «A nessuno» (`nessuno`), poi le persone in ordine d'arrivo, con l'id come stringa. Chi guarda non compare fra le
 *   persone: per lui c'è «A me».
 * - `rigaAssegnazione(assegnataA)`: la riga «Assegnata da … il … alle …» della scheda, come chiave di lingua e argomenti.
 *   Se chi ha assegnato non c'è più, la riga dice solo quando.
 */

import { describe, expect, test } from 'vitest';
import { opzioniFiltroAssegnazione, rigaAssegnazione } from '@/lib/segnalazioni/assegnazione';

const persone = [
    { id: 4, name: 'Anna Collaboratrice', attivo: true },
    { id: 9, name: 'Elena Studio', attivo: true },
    { id: 12, name: 'Ida Sospesa', attivo: false },
    { id: 1, name: 'Studio Bianchi', attivo: true },
];

describe('opzioniFiltroAssegnazione', () => {
    test('prima «A me» e «A nessuno», poi le persone, senza chi guarda', () => {
        expect(opzioniFiltroAssegnazione(persone, 1).map((o) => ({ value: o.value, label: o.label }))).toEqual([
            { value: 'mie', label: 'segnalazioni.assignment.filter_mine' },
            { value: 'nessuno', label: 'segnalazioni.assignment.filter_nobody' },
            { value: '4', label: 'Anna Collaboratrice' },
            { value: '9', label: 'Elena Studio' },
            { value: '12', label: 'Ida Sospesa' },
        ]);
    });

    test('i valori sono stringhe, come quelli degli altri filtri', () => {
        for (const opzione of opzioniFiltroAssegnazione(persone, 4)) {
            expect(typeof opzione.value).toBe('string');
        }
    });

    test('senza persone restano le due voci fisse', () => {
        expect(opzioniFiltroAssegnazione([], 1).map((o) => o.value)).toEqual(['mie', 'nessuno']);
        expect(opzioniFiltroAssegnazione(undefined, 1).map((o) => o.value)).toEqual(['mie', 'nessuno']);
    });
});

describe('rigaAssegnazione', () => {
    test('con chi ha assegnato: «Assegnata da … il … alle …»', () => {
        expect(rigaAssegnazione({ id: 9, name: 'Elena Studio', attivo: true, da: 'Studio Bianchi', il: '10/11/2026', alle: '09:30' })).toEqual({
            chiave: 'segnalazioni.assignment.assigned_by',
            argomenti: { user: 'Studio Bianchi', date: '10/11/2026', time: '09:30' },
        });
    });

    test('senza chi ha assegnato: «Assegnata il … alle …»', () => {
        expect(rigaAssegnazione({ id: 9, name: 'Elena Studio', attivo: true, da: null, il: '10/11/2026', alle: '09:30' })).toEqual({
            chiave: 'segnalazioni.assignment.assigned_on',
            argomenti: { date: '10/11/2026', time: '09:30' },
        });
    });

    test('senza nessuno, o senza la data, non c\'è riga', () => {
        expect(rigaAssegnazione(null)).toBeNull();
        expect(rigaAssegnazione(undefined)).toBeNull();
        expect(rigaAssegnazione({ id: 9, name: 'Elena Studio', attivo: true })).toBeNull();
    });
});

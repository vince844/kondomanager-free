// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Lo stato «Programmata» di una comunicazione (Coda 589, decisione 290).
 *
 * `statoPubblicazione()` riceve un terzo valore, l'ora scelta (`data_programmata`): una comunicazione approvata, non
 * pubblicata e con l'ora scelta è «Programmata». Senza il terzo valore tutto resta com'era, per le segnalazioni, per
 * i documenti e per le comunicazioni di prima.
 *
 * `giornoEOraProgrammati()` dà il giorno e l'ora da mostrare, tagliando la stringa ricevuta: l'ora è già nel fuso
 * dell'installazione, e la pagina non fa conti con i fusi.
 */

import { describe, expect, test } from 'vitest';
import { colorePubblicazione, etichettaStatoPubblicazione, giornoEOraProgrammati, statoPubblicazione } from './pubblicazione';
import { visibilityConstants } from './comunicazioni/constants';

describe('lo stato «Programmata»', () => {
    test('approvata, non pubblicata e con l\'ora scelta: è programmata', () => {
        expect(statoPubblicazione(false, true, '2027-01-15T09:00')).toBe('scheduled');
        expect(statoPubblicazione(0, 1, '2027-01-15T09:00')).toBe('scheduled');
    });

    test('senza l\'ora scelta è una bozza, come prima', () => {
        expect(statoPubblicazione(false, true)).toBe('draft');
        expect(statoPubblicazione(false, true, null)).toBe('draft');
        expect(statoPubblicazione(false, true, undefined)).toBe('draft');
        expect(statoPubblicazione(false, true, '')).toBe('draft');
    });

    test('pubblicata resta pubblicata, in attesa resta in attesa: l\'ora scelta non le cambia', () => {
        expect(statoPubblicazione(true, true, '2027-01-15T09:00')).toBe('published');
        expect(statoPubblicazione(false, false, '2027-01-15T09:00')).toBe('pending');
        expect(statoPubblicazione(true, false, '2027-01-15T09:00')).toBe('pending');
    });

    test('con due valori le risposte di sempre', () => {
        expect(statoPubblicazione(true, true)).toBe('published');
        expect(statoPubblicazione(false, true)).toBe('draft');
        expect(statoPubblicazione(true, false)).toBe('pending');
    });

    test('ha la sua etichetta e il suo colore', () => {
        expect(etichettaStatoPubblicazione('comunicazioni', false, true, '2027-01-15T09:00')).toBe('comunicazioni.publication.scheduled');
        expect(etichettaStatoPubblicazione('comunicazioni', false, true)).toBe('comunicazioni.publication.draft');
        expect(colorePubblicazione('scheduled' as never)).toBe('bg-sky-500');
        expect(colorePubblicazione('published')).toBe('bg-emerald-500');
    });

    test('il filtro dell\'elenco ha la voce «Programmata»', () => {
        const voce = visibilityConstants.find((v) => v.value === 'scheduled');

        expect(voce).toBeDefined();
        expect(voce!.label).toBe('comunicazioni.publication.scheduled');
        expect(visibilityConstants.map((v) => v.value)).toEqual(expect.arrayContaining(['published', 'draft', 'pending', 'scheduled']));
    });
});

describe('il giorno e l\'ora di una programmata', () => {
    test('li taglia dalla stringa com\'è scritta', () => {
        expect(giornoEOraProgrammati('2027-01-15T09:00')).toEqual({ date: '15/01/2027', time: '09:00' });
        expect(giornoEOraProgrammati('2027-07-01T00:30')).toEqual({ date: '01/07/2027', time: '00:30' });
        expect(giornoEOraProgrammati('2026-12-31T23:59')).toEqual({ date: '31/12/2026', time: '23:59' });
    });

    test('senza un\'ora scritta bene non dà niente', () => {
        expect(giornoEOraProgrammati(null)).toBeNull();
        expect(giornoEOraProgrammati(undefined)).toBeNull();
        expect(giornoEOraProgrammati('')).toBeNull();
        expect(giornoEOraProgrammati('15/01/2027 09:00')).toBeNull();
        expect(giornoEOraProgrammati('2027-01-15')).toBeNull();
    });
});

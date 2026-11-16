// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il filtro «I sei mesi del …» dell'elenco «Rate scadute», che apre il riquadro dei sei mesi: la testata e il «di cui» sotto la cifra della riga.
 */
import { describe, expect, test } from 'vitest';
import { testoDiCui, testoSeiMesiDel } from './rateScadute';

describe('«I sei mesi del …»', () => {
    test('la testata con l\'esercizio da cui viene la data, e il «di cui»', () => {
        expect(testoSeiMesiDel({ data: '2026-06-30', esercizio: 'Esercizio 2025', persone: 4, cifra: 324000 }))
            .toBe('Persone con rate dei sei mesi del 30/06/2026 (esercizio 2025): 4, € 3.240,00 di quell\'esercizio.');
        expect(testoSeiMesiDel({ data: '2026-06-30', esercizio: null, persone: 1, cifra: 90000 }))
            .toBe('Persone con rate dei sei mesi del 30/06/2026: 1, € 900,00 di quell\'esercizio.');
        expect(testoDiCui('2026-06-30', 90000)).toBe('di cui dei sei mesi del 30/06/2026: € 900,00');
    });
});

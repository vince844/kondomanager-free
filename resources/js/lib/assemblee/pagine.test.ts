// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I due aiuti della strada dei passi: quando un passo si apre con un clic (fatto o di adesso; dopo e chiuso no), e la riga del primo passo mentre le date si
 * scrivono in «Prepara l'assemblea», con le stesse parole del server («14/12 e 15/12 · 6 punti»).
 *
 * Che cosa non copre: la riga scritta dal server (`PassiDellAssembleaTest`).
 */
import { describe, expect, test } from 'vitest';
import * as pagine from './pagine';

describe('i passi della strada', () => {
    test('si apre un passo fatto o quello di adesso; uno dopo o chiuso no', () => {
        expect(['fatto', 'adesso', 'dopo', 'chiuso'].map((stato) => pagine.siApre({ stato } as never))).toEqual([true, true, false, false]);
        expect(pagine.siApre(null)).toBe(false);
    });

    test('la riga delle date: i giorni in ordine, una volta sola, e i punti', () => {
        expect(pagine.rigaDelleDate([{ giorno: '2026-12-15', ora: '18:30' }, { giorno: '2026-12-14', ora: '08:00' }], 6)).toBe('14/12 e 15/12 · 6 punti');
        expect(pagine.rigaDelleDate([{ giorno: '2026-12-14', ora: '08:00' }, { giorno: '2026-12-14', ora: '18:00' }, { giorno: '2026-12-15' },
            { giorno: '2026-12-16' }], 1)).toBe('14/12, 15/12 e 16/12 · 1 punto');
        expect(pagine.rigaDelleDate([{ giorno: '' }, { giorno: '' }], 0)).toBe('senza le date · nessun punto');
    });
});

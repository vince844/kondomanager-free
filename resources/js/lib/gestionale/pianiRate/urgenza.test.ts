// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 688, il giorno dei lavori urgenti: le frasi della scheda delle fatture e il giorno che la fattura registrata dopo trova sulla sua voce.
 *
 * - `lavoriUrgentiDelleVoci`: il piano d'urgenza di una voce delle righe, se dice un giorno solo (`CompetenzaDichiarataPerVoce`, `urgenza`);
 * - `fraseDelleFatture`: sulla scheda delle fatture la fattura senza periodo prende il giorno del piano, quella con il suo periodo tiene il suo.
 *
 * Che cosa non copre: il riquadro (`LaDeliberaOLUrgenza.test.ts`) e il pannello della competenza (`CompetenzaFatturaLavoriUrgenti.test.ts`).
 */
import { describe, expect, test } from 'vitest';
import { fraseDelleFatture, lavoriUrgentiDelleVoci } from './urgenza';

describe('il giorno dei lavori sulla voce della fattura', () => {
    test('la prima voce con un piano d\'urgenza di un giorno solo', () => {
        expect(lavoriUrgentiDelleVoci([
            null,
            { piano: 'Riscaldamento', tratti: [{ dal: '2026-01-01', al: '2026-04-15' }] },
            { piano: 'Colonna — rate', tratti: [{ dal: '2026-03-02', al: '2026-03-02' }], urgenza: true },
        ])).toEqual({ piano: 'Colonna — rate', giorno: '2026-03-02' });
    });

    test('un piano senza urgenza, o con un periodo, non è un giorno dei lavori', () => {
        expect(lavoriUrgentiDelleVoci([{ piano: 'Facciata', tratti: [{ dal: '2026-03-02', al: '2026-03-02' }] }])).toBeNull();
        expect(lavoriUrgentiDelleVoci([{ piano: 'Colonna', tratti: [{ dal: '2026-03-02', al: '2026-03-20' }], urgenza: true }])).toBeNull();
        expect(lavoriUrgentiDelleVoci([])).toBeNull();
    });
});

describe('la scheda delle fatture con il giorno dei lavori', () => {
    test('che cosa fa il giorno con le fatture, e quante lo prendono', () => {
        const regola = 'La fattura senza periodo prende questo giorno; quella con il suo periodo («Costo maturato dal … al …») tiene il suo.';
        expect(fraseDelleFatture('2026-03-02', 0)).toBe(regola);
        expect(fraseDelleFatture('2026-03-02', 1)).toBe(`${regola} 1 fattura scelta non dice il periodo: prende il 02/03/2026.`);
        expect(fraseDelleFatture('2026-03-02', 2)).toBe(`${regola} 2 fatture scelte non dicono il periodo: prendono il 02/03/2026.`);
        expect(fraseDelleFatture('', 2)).toBe(`${regola} 2 fatture scelte non dicono il periodo.`);
    });
});

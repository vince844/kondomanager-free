// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I cinque giorni dell'art. 66 c. 3 al passo 1 (decisione 662, rilievo C41): la frase con i due giorni che il server calcola da oggi
 * (`TerminiDellaConvocazione::primiGiorniDaOggi()`); nessuna frase se la prima convocazione ha il tempo, o se il giorno non c'è. Non blocca.
 *
 * Che cosa non copre: il calcolo dei giorni (il server, `CinqueGiorniAlPrimoPassoTest`); la riga nella pagina (`DateDellAssembleaCinqueGiorni.test.ts`).
 */
import { describe, expect, test } from 'vitest';
import { fraseDeiCinqueGiorni } from './date';

const TERMINI = { oggi: '2026-11-26', dal_a_mano: '2026-12-01', dal_raccomandata: '2026-12-06' };

describe('fraseDeiCinqueGiorni', () => {
    test('la prima convocazione troppo vicina: i due giorni e il lavoro urgente, come la dice il server', () => {
        expect(fraseDeiCinqueGiorni('2026-11-28', TERMINI)).toBe('Con la prima convocazione del 28/11 non c\'è il tempo dei cinque giorni (art. 66 c. 3): '
            + 'per raccomandata può essere dal 06/12, per PEC o a mano dal 01/12. Si può convocare lo stesso, ma la delibera è annullabile da chi non è convocato '
            + 'in tempo. Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).');
    });
    test('con la PEC c\'è il tempo e con la raccomandata no: la frase c\'è lo stesso', () => {
        expect(fraseDeiCinqueGiorni('2026-12-03', TERMINI)).toContain('Con la prima convocazione del 03/12');
    });
    test('con il tempo, senza giorno o senza i termini: nessuna frase', () => {
        expect(fraseDeiCinqueGiorni('2026-12-06', TERMINI)).toBeNull();
        expect(fraseDeiCinqueGiorni('2026-12-14', TERMINI)).toBeNull();
        expect(fraseDeiCinqueGiorni('', TERMINI)).toBeNull();
        expect(fraseDeiCinqueGiorni('2026-11-28', null)).toBeNull();
    });
});

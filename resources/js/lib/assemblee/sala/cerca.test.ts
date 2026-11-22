// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La ricerca nella sala, per nome o per unità: ogni parola cercata è l'inizio di una parola della riga, senza maiuscole né accenti. «Colombo», «colo» e «12»
 * trovano Colombo Rita dell'Int. 12; «12» non trova l'Int. 112; la ricerca vuota tiene tutte le righe.
 *
 * Che cosa non copre: la pagina (`components/assemblee/sala/PresenzeDellaSalaRicerca.test.ts`).
 */
import { describe, expect, test } from 'vitest';
import { paroleDellaRicerca, rispondeAllaRicerca } from './cerca';

const colombo = ['Colombo Rita', 'Ferri Luca, conduttore', 'Int. 12'];

describe('la ricerca nella sala', () => {
    test('le parole: minuscole, senza accenti, divise sui segni', () => {
        expect(paroleDellaRicerca('Int. 12/A')).toEqual(['int', '12', 'a']);
        expect(paroleDellaRicerca('  Niccolò D\'Angelo ')).toEqual(['niccolo', 'd', 'angelo']);
        expect(paroleDellaRicerca('')).toEqual([]);
    });

    test('per nome: il cognome, l\'inizio, il nome, senza maiuscole né accenti', () => {
        for (const cercato of ['Colombo', 'colo', 'rita', 'RITA COLOMBO', 'colombo r']) expect(rispondeAllaRicerca(cercato, colombo), cercato).toBe(true);
        expect(rispondeAllaRicerca('lombo', colombo)).toBe(false);
        expect(rispondeAllaRicerca('Rossi', colombo)).toBe(false);
        expect(rispondeAllaRicerca('niccolo', ['Niccolò Bruni', 'Int. 4'])).toBe(true);
    });

    test('per unità: «12» e «int 12» trovano l\'Int. 12, non l\'Int. 112; il conduttore trova la riga dell\'unità', () => {
        expect(rispondeAllaRicerca('12', colombo)).toBe(true);
        expect(rispondeAllaRicerca('int. 12', colombo)).toBe(true);
        expect(rispondeAllaRicerca('12', ['Gallo Enzo', 'Int. 112'])).toBe(false);
        expect(rispondeAllaRicerca('Ferri', colombo)).toBe(true);
    });

    test('la ricerca vuota, o di soli segni, tiene tutte le righe', () => {
        expect(rispondeAllaRicerca('', colombo)).toBe(true);
        expect(rispondeAllaRicerca(' . ', colombo)).toBe(true);
    });
});

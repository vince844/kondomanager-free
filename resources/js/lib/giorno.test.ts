/**
 * «Oggi» nel browser: la data del calendario di chi usa la pagina, non quella di Greenwich.
 *
 * `new Date().toISOString().split('T')[0]` dà il giorno UTC: alle 00:30 del 01/01 a Roma è ancora il 31/12, e la data di
 * emissione proposta faceva nascere il dovuto della rata nell'anno prima. Il fuso del test è quello di Roma, fissato qui: la
 * macchina che lancia la suite può stare altrove.
 */
process.env.TZ = 'Europe/Rome';

import { afterEach, describe, expect, test, vi } from 'vitest';
import { CalendarDate } from '@internationalized/date';
import { giornoDelCalendario, giornoItaliano, oggiLocale, piuGiorni } from './giorno';

afterEach(() => vi.useRealTimers());

describe('oggiLocale', () => {
    test('alle 00:30 del 01/01/2027 a Roma (le 23:30 del 31/12 in UTC) è il 01/01/2027', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-12-31T23:30:00Z'));

        expect(new Date().toISOString().split('T')[0]).toBe('2026-12-31');
        expect(oggiLocale()).toBe('2027-01-01');
    });

    test('alle 23:30 del 31/12 a Roma è ancora il 31/12', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-12-31T22:30:00Z'));

        expect(oggiLocale()).toBe('2026-12-31');
    });

    test('con l\'ora legale: alle 01:30 del 01/07 a Roma (le 23:30 del 30/06 in UTC) è il 01/07', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-30T23:30:00Z'));

        expect(oggiLocale()).toBe('2026-07-01');
    });
});

describe('piuGiorni: una data senza ora più N giorni, sui numeri della data', () => {
    test('con il passaggio all\'ora legale di marzo: 10/03 + 30 giorni è il 09/04 (con `toISOString` era l\'08/04)', () => {
        const d = new Date('2026-03-10');
        d.setDate(d.getDate() + 30);
        expect(d.toISOString().substring(0, 10)).toBe('2026-04-08');

        expect(piuGiorni('2026-03-10', 30)).toBe('2026-04-09');
        expect(piuGiorni('2026-03-20', 10)).toBe('2026-03-30');
    });

    test('a cavallo del mese, dell\'anno, del 29 febbraio, all\'indietro, e a ottobre', () => {
        expect(piuGiorni('2026-01-31', 1)).toBe('2026-02-01');
        expect(piuGiorni('2026-12-31', 1)).toBe('2027-01-01');
        expect(piuGiorni('2028-02-28', 1)).toBe('2028-02-29');
        expect(piuGiorni('2026-01-01', -7)).toBe('2025-12-25');
        expect(piuGiorni('2026-10-10', 30)).toBe('2026-11-09');
        expect(piuGiorni('2026-10-20', 10)).toBe('2026-10-30');
    });

    test('legge solo il giorno di una data con l\'ora', () => {
        expect(piuGiorni('2026-03-10T00:00:00.000000Z', 30)).toBe('2026-04-09');
    });
});

describe('giornoItaliano', () => {
    test('aaaa-mm-gg come gg/mm/aaaa; vuota senza data', () => {
        expect(giornoItaliano('2027-01-15')).toBe('15/01/2027');
        expect(giornoItaliano('2027-01-15T00:00:00Z')).toBe('15/01/2027');
        expect(giornoItaliano(null)).toBe('');
    });
});

describe('giornoDelCalendario: il giorno scelto in un calendario', () => {
    test('una data del calendario (anno, mese, giorno) resta quel giorno, anche alla mezzanotte locale', () => {
        // La mezzanotte locale del 06/11 a Roma è il 05/11 in UTC: il filtro dell'agenda partiva dal giorno prima.
        const mezzanotte = new Date(2026, 10, 6);
        expect(mezzanotte.toISOString().split('T')[0]).toBe('2026-11-05');

        // Il valore scelto nel calendario dell'agenda è una `CalendarDate` di @internationalized/date: anno, mese (da 1) e giorno.
        const scelta = new CalendarDate(2026, 11, 6);
        expect([scelta.year, scelta.month, scelta.day]).toEqual([2026, 11, 6]);
        expect(scelta.toDate('Europe/Rome').toISOString().split('T')[0]).toBe('2026-11-05');

        expect(giornoDelCalendario(scelta)).toBe('2026-11-06');
        expect(giornoDelCalendario(mezzanotte)).toBe('2026-11-06');
        expect(giornoDelCalendario('2026-11-06')).toBe('2026-11-06');
        expect(giornoDelCalendario(null)).toBeUndefined();
    });
});

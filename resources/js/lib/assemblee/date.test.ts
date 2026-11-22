// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il controllo delle date dell'avviso mentre si scrivono (R11 del progetto della 1.12.0-beta.13): sulla stessa tabella dei casi del server
 * (`tests/Fixtures/casi_delle_date_assemblea.json`, che `CasiDelleDateTest` confronta con `ControlloDelleDate::CASI`), la stessa validità e la stessa frase.
 * In più: l'ora scritta con la «T» del campo e senza i secondi, e il giorno di calendario senza fusi (il passaggio all'ora legale di marzo).
 *
 * Che cosa non copre: il salvataggio (lo controlla il server con la sua frase).
 */
import { describe, expect, test } from 'vitest';
import casi from '../../../../tests/Fixtures/casi_delle_date_assemblea.json';
import { controllaLeDate, giorniFra, istante } from './date';

describe('il controllo delle date della pagina è quello del server', () => {
    test('la tabella ha i casi', () => {
        expect(casi.length).toBeGreaterThanOrEqual(10);
    });

    test.each(casi.map((c) => [c.che_cosa, c] as const))('%s', (_nome, caso) => {
        expect(controllaLeDate(caso.sedute)).toEqual({ valide: caso.valide, frase: caso.frase });
    });

    test('l\'ora del campo con la «T», senza i secondi, e i giorni di calendario attraverso l\'ora legale', () => {
        expect(istante('2026-12-15T18:30')).toBe('2026-12-15 18:30:00');
        expect(istante('')).toBeNull();
        expect(giorniFra('2026-03-28', '2026-03-30')).toBe(2);
        expect(controllaLeDate([{ convocazione: 'prima', fissata_il: '2026-03-28T20:00' }, { convocazione: 'seconda', fissata_il: '2026-03-29T10:00' }]).valide).toBe(true);
    });
});

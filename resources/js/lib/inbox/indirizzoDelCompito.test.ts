// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Il collegamento di un compito nell'agenda non dipende dall'indirizzo del sito con cui è stato scritto: restano il percorso, la query e l'ancora.
 */
import { describe, expect, test } from 'vitest';
import { percorsoDelCompito } from './indirizzoDelCompito';

describe('il collegamento di un compito', () => {
    test('dall\'indirizzo intero restano il percorso, la query e l\'ancora; un percorso resta com\'è; vuoto, niente', () => {
        expect(percorsoDelCompito('https://kondomanager-free.test/admin/gestionale/4/esercizi/7/piani-rate/12?vista=rate#rata-3'))
            .toBe('/admin/gestionale/4/esercizi/7/piani-rate/12?vista=rate#rata-3');
        expect(percorsoDelCompito('http://localhost/admin/gestionale/2/fatture?search=12')).toBe('/admin/gestionale/2/fatture?search=12');
        expect(percorsoDelCompito('/admin/gestionale/2/rate-scadute')).toBe('/admin/gestionale/2/rate-scadute');
        expect(percorsoDelCompito('/gestionale/4/esercizi/7/piani-rate/12')).toBe('/admin/gestionale/4/esercizi/7/piani-rate/12');
        expect(percorsoDelCompito('')).toBeNull();
        expect(percorsoDelCompito(null)).toBeNull();
    });

    test('la barra rovesciata, una tabulazione o più barre all\'inizio non portano a un altro sito', () => {
        for (const salvato of ['/\\altro.example', '\\\\altro.example', 'https://sito.example/\\altro.example', '/\t/altro.example', 'https://sito.example//altro.example']) {
            const percorso = percorsoDelCompito(salvato) ?? '';
            expect(percorso, salvato).toMatch(/^\/(?![/\\])/);
            expect(new URL(percorso, 'https://kondomanager.example').host, salvato).toBe('kondomanager.example');
        }
        expect(percorsoDelCompito('https://sito.example/\\altro.example')).toBe('/altro.example');
    });
});

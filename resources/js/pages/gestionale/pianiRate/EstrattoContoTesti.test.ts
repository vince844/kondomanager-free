// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'estratto conto della persona (`EstrattoContoAnagrafica.vue`): nessun testo sotto i 12 px, e le etichette con il riquadro sono `rounded-md` (tondi restano
 * pallini e cerchi con l'icona).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue'), 'utf8');

describe('i testi dell\'estratto conto', () => {
    test('nessun testo sotto i 12 px', () => {
        expect(sorgente.match(/text-\[(\d|1[01])(\.\d+)?px\]|text-\[0\.[0-6]\d*rem\]|font-size:\s*(\d|1[01])px/g) ?? []).toEqual([]);
    });

    test('le etichette con il riquadro sono rounded-md, non con l\'angolo di serie né a pillola', () => {
        const etichette = [...sorgente.matchAll(/class="([^"]*)"/g)].map((m) => m[1]).filter((c) => /\buppercase\b/.test(c) && /\bborder\b|\bbg-/.test(c) && /\bpx-/.test(c));

        expect(etichette.length).toBeGreaterThanOrEqual(4);
        expect(etichette.filter((c) => !/\brounded-md\b/.test(c))).toEqual([]);
    });

    test('nelle card il titolo non tocca l\'icona, anche quando la card è stretta', () => {
        const intestazioni = [...sorgente.matchAll(/<div class="(flex flex-row items-center justify-between[^"]*)">/g)].map((m) => m[1]);

        expect(intestazioni.length).toBeGreaterThanOrEqual(8);
        expect(intestazioni.filter((c) => !/\bgap-2\b/.test(c))).toEqual([]);
    });
});

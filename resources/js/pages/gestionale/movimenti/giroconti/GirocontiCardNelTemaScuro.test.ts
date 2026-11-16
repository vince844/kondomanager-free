// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina «Giroconti» nel tema scuro: le tre card in alto e la card del riallineamento non restano bianche (o ambra chiaro) con i numeri scuri.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const righe = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/movimenti/giroconti/GirocontiList.vue'), 'utf8').split('\n');
const senzaScuro = (chiaro: RegExp, scuro: RegExp) => righe.map((r, i) => ({ r, n: i + 1 })).filter(({ r }) => chiaro.test(r) && !scuro.test(r)).map(({ n }) => n);

describe('«Giroconti» nel tema scuro', () => {
    test('nessuno sfondo bianco senza il suo sfondo scuro', () => {
        expect(senzaScuro(/\bbg-white\b/, /dark:bg-/)).toEqual([]);
    });
    test('i numeri delle tre card e la card del riallineamento hanno il loro colore scuro', () => {
        expect(senzaScuro(/\btext-slate-900\b/, /dark:text-/)).toEqual([]);
        expect(senzaScuro(/\bbg-amber-50\b/, /dark:bg-/)).toEqual([]);
        expect(senzaScuro(/\btext-amber-900\b/, /dark:text-/)).toEqual([]);
    });
});

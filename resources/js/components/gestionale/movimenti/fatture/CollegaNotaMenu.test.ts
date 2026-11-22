// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Collega a una fattura» della nota di credito: il menu è un `v-select` e le sue voci vanno a capo invece di tagliarsi («n. FT-2026-1 del 09/07/2026 —
 * € 732,00 · Esercizio anno 2026» si leggeva fino a «Esercizio anno 202»).
 *
 * Che cosa non copre: la larghezza vera della finestra (la prova a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/movimenti/fatture/DataTableRowActions.vue'), 'utf8');
const menu = sorgente.slice(sorgente.indexOf('data-testid="scegli-fattura-rettificata"') - 600, sorgente.indexOf('data-testid="scegli-fattura-rettificata"') + 600);

describe('il menu della fattura da rettificare', () => {
    test('le voci vanno a capo, anche quella scelta', () => {
        expect(menu).toContain('<template #option="c">');
        expect(menu).toContain('<template #selected-option="c">');
        expect((menu.match(/whitespace-normal/g) ?? []).length).toBeGreaterThanOrEqual(2);
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Il giro di correzioni della tappa 6 (rilievo 12): le due righe toccate dalla tappa stanno negli standard delle pagine.
 * Il riquadro delle entrate del mastrino ha il tema scuro; la voce «le unità con i loro soggetti» del menu delle stampe del piano non è sotto 12 px.
 */
describe('standard delle righe toccate dalla tappa 6', () => {
    it('il riquadro delle entrate del mastrino ha le classi del tema scuro', () => {
        const src = readFileSync(resolve(__dirname, 'Show.vue'), 'utf8');
        const riga = src.split('\n').find((r) => r.includes('data-testid="mastrino-entrate"')) ?? '';

        expect(riga).toContain('bg-blue-50');
        expect(riga).toMatch(/dark:bg-/);
        expect(riga).toMatch(/dark:text-/);
        expect(riga).toMatch(/dark:border-/);
    });

    it('la voce del menu «le unità con i loro soggetti» non è sotto 12 px', () => {
        const src = readFileSync(resolve(__dirname, '../../pianiRate/PianiRateShow.vue'), 'utf8');
        const riga = src.split('\n').find((r) => r.includes('le unità con i loro soggetti')) ?? '';

        expect(riga).not.toContain('text-[10px]');
        expect(riga).toContain('text-xs');
    });
});

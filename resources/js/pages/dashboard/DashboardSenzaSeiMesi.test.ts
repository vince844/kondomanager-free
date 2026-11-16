// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La Dashboard social non ha la lista dei sei mesi di tutti i condomini (è nell'Action inbox) e non ha «Guida» nell'intestazione: è l'unica pagina senza.
 * Si legge il sorgente della pagina.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/dashboard/Dashboard.vue'), 'utf8');

describe('la Dashboard social', () => {
    test('senza la carta dei sei mesi', () => {
        expect(sorgente).not.toContain('SeiMesiDelloStudio');
        expect(sorgente).not.toContain('seiMesiCondomini');
    });

    test('senza «Guida» e senza il suo cassetto', () => {
        expect(sorgente).not.toContain('PulsanteGuida');
        expect(sorgente).not.toContain('DashboardStudioGuide');
        expect(sorgente).not.toMatch(/>\s*Guida\s*</);
    });
});

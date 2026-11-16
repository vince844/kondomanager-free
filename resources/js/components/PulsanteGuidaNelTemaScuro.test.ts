// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Guida» (`PulsanteGuida`, nell'intestazione di ogni pagina) nel tema scuro: il bordo e il passaggio del mouse hanno il loro colore scuro, così il
 * pulsante non diventa indaco chiaro con il testo indaco al passaggio del mouse.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const classi = readFileSync(resolve(process.cwd(), 'resources/js/components/PulsanteGuida.vue'), 'utf8').match(/<button[\s\S]*?class="([^"]+)"/)![1].split(/\s+/);

describe('«Guida» nel tema scuro', () => {
    test('ogni colore chiaro del pulsante ha la sua classe scura: lo sfondo, il bordo, il passaggio del mouse, il testo', () => {
        expect(classi).toContain('bg-indigo-50');
        expect(classi.some((c) => c.startsWith('dark:bg-'))).toBe(true);
        expect(classi.some((c) => c.startsWith('dark:border-'))).toBe(true);
        expect(classi.some((c) => c.startsWith('dark:hover:bg-'))).toBe(true);
        expect(classi.some((c) => c.startsWith('dark:text-'))).toBe(true);
    });
});

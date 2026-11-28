// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La home pubblica non carica caratteri da un altro sito (Coda 387): ogni visita direbbe a quel sito chi apre la
 * pagina, e la politica dei contenuti dovrebbe aprirgli la porta. Usa il carattere di sistema, come le pagine interne.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/Welcome.vue'), 'utf8');

describe('Welcome.vue', () => {
    test('non nomina rsms.me', () => {
        expect(sorgente).not.toContain('rsms.me');
    });

    test('non carica fogli di stile da un indirizzo esterno', () => {
        expect(sorgente).not.toMatch(/<link[^>]+href="https?:\/\//);
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli stati che la sala aggiunge all'assemblea («In corso», «Deserta», «Chiusa») hanno il loro colore nel badge, e l'archivio li filtra.
 *
 * Che cosa non copre: il filtro sul server (`PagineDellaSalaTest`).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { coloreDelloStato } from './assemblee';

describe('gli stati della sala', () => {
    test('in corso verde, deserta ambra, chiusa neutro', () => {
        expect(coloreDelloStato('in_corso')).toBe('verde');
        expect(coloreDelloStato('deserta')).toBe('ambra');
        expect(coloreDelloStato('chiusa')).toBe('neutro');
        expect(coloreDelloStato('convocata')).toBe('blu');
    });

    test('l\'archivio li filtra: «In corso», «Tenute in sala», «Deserte»', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/assemblee/Archivio.vue'), 'utf8');
        for (const voce of ['{ valore: \'in_corso\', nome: \'In corso\' }', '{ valore: \'chiusa\', nome: \'Tenute in sala\' }', '{ valore: \'deserta\', nome: \'Deserte\' }']) {
            expect(s, voce).toContain(voce);
        }
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guida della registrazione di una fattura spiega il riquadro «Competenza» con lo stesso schema della pagina: a che cosa serve, le tre scelte con un
 * esempio, quando conta. Prima la guida ne parlava solo per la fattura pregressa.
 *
 * Che cosa non copre: il disegno del cassetto (la prova a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const guida = readFileSync(resolve(process.cwd(), 'resources/js/components/guides/FatturaRegistrazioneGuide.vue'), 'utf8').replace(/\s+/g, ' ');

describe('la guida della registrazione: la competenza', () => {
    test('una parte sua, con a che cosa serve e le tre scelte', () => {
        expect(guida).toContain('La competenza: a chi tocca la spesa dopo una vendita');
        expect(guida).toContain('dice a chi tocca la spesa se l\'unità cambia proprietario');
        for (const scelta of ['<strong>Nessuna</strong>', '<strong>Costo maturato dal … al …</strong>', '<strong>Spesa deliberata il …</strong>']) {
            expect(guida).toContain(scelta);
        }
        expect(guida).toContain('in un piano approvato non si cambia più');
    });
});

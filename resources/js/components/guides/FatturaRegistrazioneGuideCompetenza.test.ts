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

/*
 * 1.12.0-beta.14, tappa 6, Coda 754 (4), (5), (6): la parte del debito dell'esercizio precedente dice il campo senza IVA con il totale sotto, la scelta
 * dell'origine nella finestra, e che con il fondo il programma prepara la copertura e nessun piano rate la chiede ai condòmini.
 */
describe('la guida della registrazione: il debito dell\'esercizio precedente', () => {
    test('il campo «Imponibile (senza IVA)» e il totale che il programma aggiunge', () => {
        expect(guida).toContain('<strong>«Imponibile (senza IVA)»</strong>');
        expect(guida).toContain('il programma aggiunge l\'IVA');
        expect(guida).not.toMatch(/Imponibile Lordo/i);
    });

    test('la finestra della parte non coperta: l\'origine si sceglie, il fondo prepara la copertura', () => {
        expect(guida).toContain('<strong>Origine dell\'incarico</strong>');
        expect(guida).toContain('con l\'urgenza la fattura resta da ratificare in assemblea; con la delibera no');
        expect(guida).toContain('<strong>Usa fondo riserva</strong>');
        expect(guida).toContain('nessun piano rate la chiede ai condòmini');
    });
});

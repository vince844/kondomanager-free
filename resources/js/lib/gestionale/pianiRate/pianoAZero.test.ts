// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 686 (e): quando il nuovo piano verrebbe a € 0,00, e la frase che lo dice prima di salvare.
 *
 * Il caso di partenza è quello della domanda del forum del 18/11/2026 («A bis», `SpesaNonPrevistaARateTest`): l'unica voce della gestione è spenta nel menu, il piano senza
 * voci scelte prende la voce esaurita e ripartisce € 0,00. Qui la regola, caso per caso; la pagina che la mostra la prova
 * `PianiRateNewSpesaFuoriPreventivo.test.ts`.
 *
 * Cosa non copre: il calcolo vero del piano, che fa il server (una voce di entrata accesa conta come «qualcosa da chiedere», anche se si sottrae: la regola
 * guarda se c'è qualcosa, non quanto).
 */

import { describe, expect, test } from 'vitest';
import { motivoPianoAZero, type StatoDelPianoNuovo } from './pianoAZero';

const base: StatoDelPianoNuovo = {
    tipo: 'ordinario', gestione: 'Spesa fuori bilancio', caricamento: false, conSaldi: false, vociDisponibili: [], vociScelte: [], fattureScelte: [],
};

describe('il piano che verrebbe a € 0,00', () => {
    test('senza voci scelte, con l\'unica voce spenta (lo sforo rimandato al conguaglio): lo dice, con la gestione e dove guardare', () => {
        expect(motivoPianoAZero({ ...base, vociDisponibili: [{ disabled: true, residuo: 0 }] }))
            .toBe('Il piano verrebbe a € 0,00: nessuna voce di «Spesa fuori bilancio» ha ancora qualcosa da mettere a rate. Le voci esaurite, o già in un altro piano, sono spente nel menu «Voci di bilancio incluse».');
    });

    test('senza voci nella gestione: lo stesso', () => {
        expect(motivoPianoAZero(base)).toContain('nessuna voce di «Spesa fuori bilancio»');
    });

    test('con le voci scelte e gli importi a zero', () => {
        expect(motivoPianoAZero({ ...base, vociDisponibili: [{ disabled: false, residuo: 1220 }], vociScelte: [{ tipo: 'spesa', importo_da_usare: '0,00' }] }))
            .toBe('Il piano verrebbe a € 0,00: le voci scelte non chiedono niente. Scrivi l\'importo da richiedere per ognuna, o scegli altre voci.');
    });

    test('con le fatture scelte e gli importi a zero', () => {
        expect(motivoPianoAZero({ ...base, tipo: 'straordinario', fattureScelte: [{ importo_suggerito: '0.00' }] }))
            .toBe('Il piano verrebbe a € 0,00: le fatture scelte hanno l\'importo da mettere a rate a zero. Scrivi quanto chiedere per ognuna.');
    });

    test('controprova: con una voce accesa che ha qualcosa da chiedere, o con importi veri, non dice niente', () => {
        expect(motivoPianoAZero({ ...base, vociDisponibili: [{ disabled: true, residuo: 0 }, { disabled: false, residuo: 1220 }] })).toBeNull();
        expect(motivoPianoAZero({ ...base, vociScelte: [{ tipo: 'spesa', importo_da_usare: '1.220,00' }] })).toBeNull();
        expect(motivoPianoAZero({ ...base, tipo: 'straordinario', fattureScelte: [{ importo_suggerito: '1220.00' }] })).toBeNull();
    });

    test('controprova: con i saldi pregressi da mettere nel piano, mentre la pagina carica, senza gestione o senza fatture scelte non dice niente', () => {
        const spenta = { ...base, vociDisponibili: [{ disabled: true, residuo: 0 }] };
        expect(motivoPianoAZero({ ...spenta, conSaldi: true })).toBeNull();
        expect(motivoPianoAZero({ ...spenta, caricamento: true })).toBeNull();
        expect(motivoPianoAZero({ ...spenta, gestione: null })).toBeNull();
        expect(motivoPianoAZero({ ...base, tipo: 'straordinario' })).toBeNull();
    });
});

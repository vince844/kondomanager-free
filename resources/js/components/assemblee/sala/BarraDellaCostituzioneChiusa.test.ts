// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La barra della costituzione a sala chiusa: non dice più «Da aprire» né «la sala li conta dall'apertura», ma com'è finita («Chiusa alle 17:10 · costituita in
 * prima convocazione con 12 su 15 e 732,60 millesimi»).
 *
 * Con un condòmino solo, il singolare (rilievo C44): «1 condòmino», «servono 1 condòmino».
 *
 * Che cosa non copre: la barra prima e durante l'apertura (`Sala.test.ts`); i dati della chiusura (`CorrezioniDellaSimulazioneTest`).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import Barra from './BarraDellaCostituzione.vue';

const base = { conto: null, convocazione: 'prima', totaleTeste: 15, totaleValore: 100000000, presentiTeste: 0, presentiValore: 0, perDelega: 0 };
const chiusura = { alle: '17:10', convocazione: 'prima', costituita: true, teste: 12, totale_teste: 15, valore: 73260000, totale_valore: 100000000 };

describe('la barra a sala chiusa', () => {
    test('dice com\'è finita, non «Da aprire»', () => {
        const w = mount(Barra, { props: { ...base, chiusura } as never });
        expect(w.get('[data-testid="barra-stato"]').text()).toBe('Chiusa alle 17:10 · costituita in prima convocazione');
        expect(w.get('[data-testid="barra-teste"]').text()).toBe('12 su 15 condòmini in sala');
        expect(w.get('[data-testid="barra-valore"]').text()).toBe('732,60 millesimi su 1.000');
        expect(w.text()).not.toContain('Da aprire');
        expect(w.text()).not.toContain('la sala li conta dall\'apertura');
        expect(w.get('[data-testid="barra-servono"]').text()).toBe('Chiusa alle 17:10 · costituita in prima convocazione con 12 su 15 e 732,60 millesimi.');
    });

    test('con l\'assemblea non costituita lo dice', () => {
        const w = mount(Barra, { props: { ...base, chiusura: { ...chiusura, costituita: false, convocazione: 'seconda', teste: 3, valore: 10000000 } } as never });
        expect(w.get('[data-testid="barra-servono"]').text()).toBe('Chiusa alle 17:10 · non costituita in seconda convocazione con 3 su 15 e 100,00 millesimi.');
    });

    test('i numeri grandi sono quelli della costituzione (3 su 4, 760,00 millesimi), non zero; la frase dice «costituita in prima convocazione con 3 su 4»', () => {
        const w = mount(Barra, { props: { ...base, totaleTeste: 4, chiusura: { alle: '08:00', convocazione: 'prima', costituita: true, teste: 3, totale_teste: 4, valore: 76000000,
            totale_valore: 100000000 } } as never });
        expect(w.get('[data-testid="barra-teste"]').text()).toBe('3 su 4 condòmini in sala');
        expect(w.get('[data-testid="barra-valore"]').text()).toBe('760,00 millesimi su 1.000');
        expect(w.get('[data-testid="barra-servono"]').text()).toBe('Chiusa alle 08:00 · costituita in prima convocazione con 3 su 4 e 760,00 millesimi.');
    });

    test('senza la chiusura la barra è quella di prima', () => {
        const w = mount(Barra, { props: { ...base, chiusura: null } as never });
        expect(w.get('[data-testid="barra-stato"]').text()).toBe('Da aprire in prima convocazione');
    });
});

describe('con un condòmino solo la barra dice il singolare (rilievo C44)', () => {
    test('da aprire, aperta e chiusa: «1 condòmino», «servono 1 condòmino»', () => {
        const uno = { ...base, totaleTeste: 1 };
        const daAprire = mount(Barra, { props: { ...uno, chiusura: null } as never });
        expect(daAprire.get('[data-testid="barra-teste"]').text()).toBe('1 condòmino');
        expect(daAprire.get('[data-testid="barra-servono"]').text()).toBe('In prima convocazione servono 1 condòmino e 666,67 millesimi: la sala li conta dall\'apertura.');
        const aperta = mount(Barra, { props: { ...uno, presentiTeste: 0, conto: { costituita: false, mancano: 'mancano 1 condòmino e 666,67 millesimi' } } as never });
        expect(aperta.get('[data-testid="barra-teste"]').text()).toBe('0 su 1 condòmino in sala');
        const chiusa = mount(Barra, { props: { ...uno, chiusura: { ...chiusura, teste: 1, totale_teste: 1 } } as never });
        expect(chiusa.get('[data-testid="barra-teste"]').text()).toBe('1 su 1 condòmino in sala');
        expect(chiusa.text()).not.toContain('condòmini');
    });
});

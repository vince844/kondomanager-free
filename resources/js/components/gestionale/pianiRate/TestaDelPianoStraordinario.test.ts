// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La testa del piano straordinario: il fondo speciale (decisione 622 (2), la scena 3) come riga arancio con le parole della legge, senza «Ignora»; i lavori
 * urgenti (Coda 688, la scena 13) con «Urgenza», «Approvato», il giorno dei lavori, il motivo come testo e la prima assemblea. Senza i due, niente.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import Testa from './TestaDelPianoStraordinario.vue';

const fondo = { frase: 'Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.', legge: 'La legge vuole un fondo «di importo pari all\'ammontare dei lavori»; …',
    chiesti: 900000, deliberati: 1450000, delibera_id: 4, assemblea_id: 7 };
const urgenza = { giorno: '02/03/2027', motivo: '<i>Rottura</i> della colonna montante', frase: 'Lavori urgenti (art. 1135 c. 2 c.c.): il piano è nato approvato, senza una delibera.',
    assemblea: 'Da riferire alla prima assemblea di Via Roma 12: il punto è già proposto quando la prepari.' };

describe('la testa del piano straordinario', () => {
    test('il fondo speciale: la riga arancio con la frase e la legge, senza «Ignora»', () => {
        const w = mount(Testa, { props: { fondo } });
        const riga = w.get('[data-testid="fondo-speciale"]');
        expect(riga.text()).toContain('Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.');
        expect(riga.text()).toContain('La legge vuole un fondo «di importo pari all\'ammontare dei lavori»');
        expect(riga.classes()).toContain('bg-amber-50');
        expect(riga.find('button').exists()).toBe(false);
        expect(w.text()).not.toContain('Ignora');
    });

    test('i lavori urgenti: il giorno, il motivo come testo, la prima assemblea; badge rounded-md', () => {
        const w = mount(Testa, { props: { urgenza } });
        expect(w.get('[data-testid="giorno-dei-lavori"]').text()).toBe('Lavori ordinati il 02/03/2027 · il giorno decide chi paga, non quanto');
        expect(w.get('[data-testid="motivo-dell-urgenza"]').text()).toBe('Motivo: «<i>Rottura</i> della colonna montante»');
        expect(w.find('i').exists()).toBe(false);
        expect(w.text()).toContain('Da riferire alla prima assemblea di Via Roma 12');
        expect(w.html()).toContain('rounded-md');
        expect(w.html()).not.toContain('rounded-full');
    });

    test('senza fondo né urgenza non c\'è niente', () => {
        expect(mount(Testa, { props: {} }).find('section').exists()).toBe(false);
    });
});

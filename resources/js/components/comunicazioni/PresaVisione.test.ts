// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Sotto la comunicazione, nel portale: che cosa vede lo studio di me, e il pulsante «Confermo di aver letto»
 * (Coda 594, decisione 310).
 *
 * Il componente riceve `comunicazioneId` e `lettura`, la sola lettura di chi guarda, così come la manda il server:
 * - `lettura.apertura` piena: un elemento `[data-lettura="apertura"]` dice che lo studio vede l'apertura, e quando;
 * - `lettura.puo_confermare`: un elemento `[data-lettura="chiedi"]` con la riga che spiega e il pulsante
 *   `button[data-azione="presa-visione"]`, che manda subito
 *   `router.post(route('user.comunicazioni.presa-visione', { comunicazione: <id> }), {}, { preserveScroll: true })`;
 * - `lettura.presa_visione` piena: un elemento `[data-lettura="presa-visione"]` dice quando ha confermato, e il
 *   pulsante non c'è;
 * - niente di tutto questo: il componente non mostra niente.
 *
 * `trans()` restituisce la chiave seguita dai valori passati.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
const rotta = vi.fn((nome: string, parametri?: Record<string, unknown>) => `/${nome}/${parametri?.comunicazione ?? ''}`);
(globalThis as Record<string, unknown>).route = rotta;

const PresaVisione = (await import('./PresaVisione.vue')).default;

function monta(lettura: Record<string, unknown> = {}) {
    return mount(PresaVisione, {
        props: { comunicazioneId: 7, lettura: { apertura: null, puo_confermare: false, presa_visione: null, ...lettura } } as never,
        global: { mocks: { route: rotta } },
    });
}

const pulsante = (w: ReturnType<typeof monta>) => w.find('button[data-azione="presa-visione"]');

beforeEach(() => {
    rotta.mockClear();
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('PresaVisione — senza niente da dire', () => {
    test('non mostra niente', () => {
        const w = monta();

        expect(w.find('[data-lettura]').exists()).toBe(false);
        expect(pulsante(w).exists()).toBe(false);
        expect(w.text()).toBe('');
    });
});

describe('PresaVisione — lo studio vede l\'apertura', () => {
    test('il condòmino lo legge, con il giorno e l\'ora', () => {
        const w = monta({ apertura: { il: '12/11/2026', alle: '11:00' } });
        const nota = w.find('[data-lettura="apertura"]');

        expect(nota.text()).toContain('comunicazioni.readings.portal_opened');
        expect(nota.text()).toContain('12/11/2026');
        expect(nota.text()).toContain('11:00');
        expect(pulsante(w).exists()).toBe(false);
    });

    test('senza apertura registrata la nota non c\'è', () => {
        expect(monta({ puo_confermare: true }).find('[data-lettura="apertura"]').exists()).toBe(false);
    });
});

describe('PresaVisione — «Confermo di aver letto»', () => {
    test('dove è chiesta c\'è il pulsante, con la riga che spiega', () => {
        const w = monta({ puo_confermare: true });

        expect(w.find('[data-lettura="chiedi"]').text()).toContain('comunicazioni.readings.confirm_hint');
        expect(pulsante(w).text()).toContain('comunicazioni.readings.confirm_button');
        expect(w.find('[data-lettura="presa-visione"]').exists()).toBe(false);
    });

    test('premuto, manda la conferma alla rotta del portale, per questa comunicazione', async () => {
        const w = monta({ puo_confermare: true });

        await pulsante(w).trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        const chiamata = vi.mocked(router.post).mock.calls[0];
        expect(rotta).toHaveBeenCalledWith('user.comunicazioni.presa-visione', { comunicazione: 7 });
        expect(chiamata[0]).toBe('/user.comunicazioni.presa-visione/7');
        expect(chiamata[1]).toEqual({});
        expect(chiamata[2]).toMatchObject({ preserveScroll: true });
    });

    test('dopo la conferma dice quando, e il pulsante non c\'è più', () => {
        const w = monta({ apertura: { il: '12/11/2026', alle: '11:00' }, presa_visione: { il: '12/11/2026', alle: '11:05' } });
        const conferma = w.find('[data-lettura="presa-visione"]');

        expect(conferma.text()).toContain('comunicazioni.readings.confirmed_on');
        expect(conferma.text()).toContain('12/11/2026');
        expect(conferma.text()).toContain('11:05');
        expect(pulsante(w).exists()).toBe(false);
        expect(w.find('[data-lettura="chiedi"]').exists()).toBe(false);
        expect(w.find('[data-lettura="apertura"]').exists()).toBe(true);
    });

    test('la conferma data resta scritta anche se il server non la chiede più', () => {
        const w = monta({ puo_confermare: false, presa_visione: { il: '12/11/2026', alle: '11:05' } });

        expect(w.find('[data-lettura="presa-visione"]').exists()).toBe(true);
        expect(pulsante(w).exists()).toBe(false);
    });
});

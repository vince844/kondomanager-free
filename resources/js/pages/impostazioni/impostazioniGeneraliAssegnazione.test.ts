// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le impostazioni generali: i due interruttori dell'assegnazione delle segnalazioni (decisione 294, punti 3 e 4).
 *
 * Ogni interruttore sta in un riquadro con `data-impostazione="<nome dell'impostazione>"`, con il suo titolo e la sua
 * spiegazione, e un `Switch` legato al campo del modulo con lo stesso nome. La pagina si apre sui valori ricevuti e li
 * rimanda con il resto del modulo.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniGenerali = (await import('./impostazioniGenerali.vue')).default;

const PORTALE = 'segnalazioni_assegnazione_visibile_nel_portale';
const STATO = 'segnalazioni_assegnazione_porta_in_lavorazione';

function monta(valori: Record<string, unknown> = {}) {
    pagina.props = {
        flash: {},
        can_register: true,
        language: 'it',
        app_name: 'Studio Bianchi',
        open_condominio_on_login: false,
        default_condominio_id: null,
        condomini: [],
        default_user_role: 'utente',
        commenti_segnalazioni_moderati_di_serie: false,
        commenti_comunicazioni_moderati_di_serie: true,
        default_per_page: 30,
        per_page_disponibili: [10, 30, 50],
        roles: [{ id: 1, name: 'utente' }, { id: 2, name: 'amministratore' }],
        ruolo_registrazione_modificabile: true,
        ...valori,
    };

    return shallowMount(ImpostazioniGenerali, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const riquadro = (w: ReturnType<typeof monta>, nome: string) => w.find(`[data-impostazione="${nome}"]`);
const interruttore = (w: ReturnType<typeof monta>, nome: string) => riquadro(w, nome).findComponent({ name: 'Switch' });

function mandato(w: ReturnType<typeof monta>): Record<string, unknown> {
    (w.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

    return vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('impostazioniGenerali — i due interruttori dell\'assegnazione delle segnalazioni', () => {
    test.each([PORTALE, STATO])('%s ha il suo riquadro, con titolo, spiegazione e interruttore', (nome) => {
        const w = monta();

        expect(riquadro(w, nome).exists()).toBe(true);
        expect(riquadro(w, nome).text()).toContain(`impostazioni.dialogs.${nome}_title`);
        expect(riquadro(w, nome).text()).toContain(`impostazioni.dialogs.${nome}_description`);
        expect(interruttore(w, nome).exists()).toBe(true);
    });

    test('la pagina si apre sui valori ricevuti, e li rimanda', () => {
        const w = monta({ [PORTALE]: true, [STATO]: false });

        expect(interruttore(w, PORTALE).props('modelValue')).toBe(true);
        expect(interruttore(w, STATO).props('modelValue')).toBe(false);
        expect(mandato(w)).toMatchObject({ [PORTALE]: true, [STATO]: false, app_name: 'Studio Bianchi' });
    });

    test('senza i valori, i due interruttori sono spenti', () => {
        const w = monta();

        expect(interruttore(w, PORTALE).props('modelValue')).toBe(false);
        expect(interruttore(w, STATO).props('modelValue')).toBe(false);
        expect(mandato(w)).toMatchObject({ [PORTALE]: false, [STATO]: false });
    });

    test('accesi dalla pagina, partono accesi', async () => {
        const w = monta();

        interruttore(w, PORTALE).vm.$emit('update:modelValue', true);
        interruttore(w, STATO).vm.$emit('update:modelValue', true);
        await w.vm.$nextTick();

        expect(mandato(w)).toMatchObject({ [PORTALE]: true, [STATO]: true });
    });

    test('i valori arrivano anche come 0 e 1, e la pagina li legge come spento e acceso', () => {
        const w = monta({ [PORTALE]: 1, [STATO]: 0 });

        expect(mandato(w)).toMatchObject({ [PORTALE]: true, [STATO]: false });
    });
});

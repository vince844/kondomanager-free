// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le impostazioni generali: «I condòmini possono avvisare lo studio dalla pagina d'errore» (Coda 788, decisione 713).
 *
 * - L'interruttore sta in un riquadro con `data-impostazione="errori_avviso_dalla_pagina"`, con il titolo, la spiegazione e un `Switch` legato al campo del
 *   modulo con lo stesso nome.
 * - È acceso di serie: senza il valore nella pagina l'interruttore è acceso e il modulo manda acceso; spento resta spento.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave seguita dai valori passati.
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
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniGenerali = (await import('./impostazioniGenerali.vue')).default;

const NOME = 'errori_avviso_dalla_pagina';

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

type Pagina = ReturnType<typeof monta>;
const riquadro = (w: Pagina) => w.find(`[data-impostazione="${NOME}"]`);
const interruttore = (w: Pagina) => riquadro(w).findComponent({ name: 'Switch' });

function mandato(w: Pagina): Record<string, unknown> {
    (w.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

    return vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('impostazioniGenerali — «I condòmini possono avvisare lo studio dalla pagina d\'errore»', () => {
    test('ha il suo riquadro, con titolo, spiegazione e interruttore', () => {
        const w = monta();

        expect(riquadro(w).exists()).toBe(true);
        expect(riquadro(w).text()).toContain(`impostazioni.dialogs.${NOME}_title`);
        expect(riquadro(w).text()).toContain(`impostazioni.dialogs.${NOME}_description`);
        expect(interruttore(w).exists()).toBe(true);
    });

    test('senza il valore l\'interruttore è acceso, e la pagina manda acceso', () => {
        const w = monta();

        expect(interruttore(w).props('modelValue')).toBe(true);
        expect(mandato(w)).toMatchObject({ [NOME]: true, app_name: 'Studio Bianchi' });
    });

    test('la pagina si apre sul valore ricevuto, anche come 0 e 1, e lo rimanda', () => {
        expect(interruttore(monta({ [NOME]: false })).props('modelValue')).toBe(false);
        expect(mandato(monta({ [NOME]: false }))).toMatchObject({ [NOME]: false });
        expect(mandato(monta({ [NOME]: 1 }))).toMatchObject({ [NOME]: true });
        expect(mandato(monta({ [NOME]: 0 }))).toMatchObject({ [NOME]: false });
    });

    test('spento dalla pagina, parte spento', async () => {
        const w = monta();

        interruttore(w).vm.$emit('update:modelValue', false);
        await w.vm.$nextTick();

        expect(mandato(w)).toMatchObject({ [NOME]: false });
    });
});

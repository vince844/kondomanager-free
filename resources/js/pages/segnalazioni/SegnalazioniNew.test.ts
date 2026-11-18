// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Crea segnalazione» del pannello: la pagina si apre e «Commenti in moderazione» parte dal valore di serie.
 *
 * La pagina leggeva il valore di partenza da una variabile non definita e restava bianca. Il valore di serie
 * arriva dal server come prop `commenti_segnalazioni_moderati_di_serie`; il modulo c'è, e l'interruttore parte da lì.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true,
        hasPermission: () => true,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniNew = (await import('./SegnalazioniNew.vue')).default;

function monta(moderati?: boolean) {
    return shallowMount(SegnalazioniNew, {
        props: {
            condomini: [], anagrafiche: [],
            ...(moderati === undefined ? {} : { commenti_segnalazioni_moderati_di_serie: moderati }),
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

function moduloDi(pagina: ReturnType<typeof monta>) {
    return (pagina.vm as unknown as { $: { setupState: { form: { commenti_moderati: boolean } } } }).$.setupState.form;
}

describe('SegnalazioniNew — la pagina si apre e parte dal valore di serie dei commenti', () => {
    test('il modulo c\'è', () => {
        expect(monta(false).find('form').exists()).toBe(true);
    });

    test('con il valore di serie acceso, «Commenti in moderazione» parte acceso', () => {
        expect(moduloDi(monta(true)).commenti_moderati).toBe(true);
    });

    test('con il valore di serie spento, parte spento', () => {
        expect(moduloDi(monta(false)).commenti_moderati).toBe(false);
    });

    test('senza la prop parte spento', () => {
        expect(moduloDi(monta()).commenti_moderati).toBe(false);
    });
});

// Fase 2 della 1.12.0-beta.11 (lo scettico del changelog, C1): «Commenti in moderazione» lo cambia solo chi può moderare. Il server ignora il campo
// di chi non modera; il modulo non mostra la casella, come quello delle comunicazioni. Senza la prop la casella non c'è: Vue legge un booleano
// mancante come falso, come nei moduli delle comunicazioni; il server la manda sempre (`ModerazioneSoloAChiModeraTest`).
describe('SegnalazioniNew — «Commenti in moderazione» solo per chi può moderare', () => {
    function montaCon(puo?: boolean) {
        return shallowMount(SegnalazioniNew, {
            props: { condomini: [], anagrafiche: [], ...(puo === undefined ? {} : { puo_scegliere_moderazione: puo }) },
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });
    }

    test('chi non può moderare non vede la casella', () => {
        expect(montaCon(false).find('#commenti_moderati').exists()).toBe(false);
    });

    test('chi può moderare la vede', () => {
        expect(montaCon(true).find('#commenti_moderati').exists()).toBe(true);
    });

    test('senza la prop non la vede: un booleano mancante vale falso', () => {
        expect(montaCon().find('#commenti_moderati').exists()).toBe(false);
    });
});

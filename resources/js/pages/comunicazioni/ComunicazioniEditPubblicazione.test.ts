// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina di modifica di una comunicazione (pannello): chi non può pubblicare una bozza (Coda 695).
 *
 * Il server manda `pubblicazione` (`puo_pubblicare`, `nota`). Quando non può, la tendina della visibilità (`v-select` con `id="stato"`) non offre
 * «Pubblicata» e sotto c'è la frase del server (`[data-avviso="non-puoi-pubblicare"]`); quando può, o senza la prop, la pagina è quella di sempre.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true,
    }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniEdit = (await import('./ComunicazioniEdit.vue')).default;

const FRASE = 'Resta in bozza: la pubblica chi ha il permesso «Pubblica comunicazioni».';

function monta(pubblicazione?: Record<string, unknown>, campi: Record<string, unknown> = {}) {
    return shallowMount(ComunicazioniEdit, {
        props: {
            condomini: [], anagrafiche: [],
            comunicazione: {
                id: 11, subject: 'Lavori al portone', description: 'Il portone si cambia.', priority: 'media',
                is_private: false, is_published: false, is_approved: true, is_featured: false, can_comment: true,
                data_programmata: null,
                condomini: { options: [{ value: 3, label: 'Condominio Aurora' }], full: [] },
                anagrafiche: [],
                ...campi,
            },
            programmazione: { puo_programmare: false, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00' },
            ...(pubblicazione ? { pubblicazione } : {}),
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const tendina = (pagina: VueWrapper) => pagina.findAllComponents(vSelect).find((c) => c.attributes('id') === 'stato')!;
const valori = (pagina: VueWrapper) => (tendina(pagina).props('options') as { value: unknown }[]).map((o) => o.value);
const avviso = (pagina: VueWrapper) => pagina.find('[data-avviso="non-puoi-pubblicare"]');

describe('ComunicazioniEdit — chi non può pubblicare una bozza', () => {
    test('la tendina non offre «Pubblicata» e sotto c\'è la frase del server', async () => {
        const pagina = monta({ puo_pubblicare: false, nota: FRASE });
        await flushPromises();

        expect(valori(pagina)).toEqual([false]);
        expect(tendina(pagina).props('modelValue')).toBe(false);
        expect(avviso(pagina).text()).toBe(FRASE);
    });

    test('chi può pubblicare ha le due voci e nessuna frase', async () => {
        const pagina = monta({ puo_pubblicare: true, nota: null });
        await flushPromises();

        expect(valori(pagina).sort()).toEqual([false, true]);
        expect(avviso(pagina).exists()).toBe(false);
    });

    test('senza la prop la pagina resta quella di sempre', async () => {
        const pagina = monta();
        await flushPromises();

        expect(valori(pagina).sort()).toEqual([false, true]);
        expect(avviso(pagina).exists()).toBe(false);
    });
});

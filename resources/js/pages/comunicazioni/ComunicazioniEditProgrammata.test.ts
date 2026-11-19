// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina di modifica di una comunicazione (pannello): la pubblicazione programmata (Coda 589, decisione 290).
 *
 * Aperta su una comunicazione programmata, la tendina dello stato (`v-select` con `id="stato"`) è su «Programmata»
 * (`'scheduled'`) e il campo dell'ora (`input[data-campo="data-programmata"]`) porta l'ora ricevuta. Mandata senza
 * toccare niente, la pagina rimanda la stessa ora; riportata a «Bozza», manda `data_programmata` vuota, che toglie la
 * programmazione.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
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

function monta(campi: Record<string, unknown> = {}, programmazione: Record<string, unknown> = {}) {
    return shallowMount(ComunicazioniEdit, {
        props: {
            condomini: [], anagrafiche: [],
            comunicazione: {
                id: 11, subject: 'Chiusura dell\'acqua', description: 'Martedì dalle 9 alle 12.', priority: 'media',
                is_private: false, is_published: false, is_approved: true, is_featured: false, can_comment: true,
                data_programmata: '2027-01-15T09:00',
                condomini: { options: [{ value: 3, label: 'Condominio Aurora' }], full: [] },
                anagrafiche: [],
                ...campi,
            },
            programmazione: { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00', ...programmazione },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const tendina = (pagina: VueWrapper) => pagina.findAllComponents(vSelect).find((c) => c.attributes('id') === 'stato')!;
const valori = (pagina: VueWrapper) => (tendina(pagina).props('options') as { value: unknown }[]).map((o) => o.value);
const campo = (pagina: VueWrapper) => pagina.find('input[data-campo="data-programmata"]');
const mandato = () => vi.mocked(router.put).mock.calls.at(-1)![1] as Record<string, unknown>;

async function scegli(pagina: VueWrapper, stato: unknown) {
    tendina(pagina).vm.$emit('update:modelValue', stato);
    await flushPromises();
}

function invia(pagina: VueWrapper) {
    (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
}

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('ComunicazioniEdit — una comunicazione programmata', () => {
    test('si apre su «Programmata», con l\'ora ricevuta nel campo', async () => {
        const pagina = monta();
        await flushPromises();

        expect(tendina(pagina).props('modelValue')).toBe('scheduled');
        expect(campo(pagina).exists()).toBe(true);
        expect((campo(pagina).element as HTMLInputElement).value).toBe('2027-01-15T09:00');
        // Decisione 504: con il fuso italiano la riga sotto la data non c'è.
        expect(pagina.find('[data-nota="fuso"]').exists()).toBe(false);
    });

    test('decisione 504: con un fuso diverso da quello italiano sotto la data c\'è solo «Ora di …»', async () => {
        const pagina = monta({}, { fuso: 'Europe/London' });
        await flushPromises();
        expect(pagina.find('[data-nota="fuso"]').text()).toContain('comunicazioni.scheduling.timezone_hint');
    });

    test('mandata senza toccare niente, rimanda la stessa ora', async () => {
        const pagina = monta();
        await flushPromises();

        invia(pagina);

        expect(mandato()).toMatchObject({ is_published: false, data_programmata: '2027-01-15T09:00' });
    });

    test('cambiata l\'ora, manda quella nuova', async () => {
        const pagina = monta();
        await flushPromises();
        await campo(pagina).setValue('2027-02-01T18:30');

        invia(pagina);

        expect(mandato()).toMatchObject({ is_published: false, data_programmata: '2027-02-01T18:30' });
    });

    test('riportata a «Bozza» manda `data_programmata` vuota; a «Pubblicata» anche, con `is_published` vero', async () => {
        const pagina = monta();
        await flushPromises();

        await scegli(pagina, false);
        expect(campo(pagina).exists()).toBe(false);
        invia(pagina);
        expect(mandato()).toMatchObject({ is_published: false, data_programmata: null });

        await scegli(pagina, true);
        invia(pagina);
        expect(mandato()).toMatchObject({ is_published: true, data_programmata: null });
    });

    test('con il pianificatore fermo la pagina lo dice subito, perché è già su «Programmata»', async () => {
        const pagina = monta({}, { pianificatore_attivo: false });
        await flushPromises();

        expect(pagina.find('[data-avviso="pianificatore-fermo"]').text()).toContain('comunicazioni.scheduling.scheduler_down');
    });
});

describe('ComunicazioniEdit — le altre comunicazioni', () => {
    test('una bozza si apre su «Bozza», senza campo, e può diventare programmata', async () => {
        const pagina = monta({ data_programmata: null });
        await flushPromises();

        expect(tendina(pagina).props('modelValue')).toBe(false);
        expect(campo(pagina).exists()).toBe(false);
        expect(valori(pagina)).toEqual(expect.arrayContaining([true, false, 'scheduled']));

        await scegli(pagina, 'scheduled');
        await campo(pagina).setValue('2027-01-15T09:00');
        invia(pagina);

        expect(mandato()).toMatchObject({ is_published: false, data_programmata: '2027-01-15T09:00' });
    });

    test('una pubblicata si apre su «Pubblicata»', async () => {
        const pagina = monta({ is_published: true, data_programmata: null });
        await flushPromises();

        expect(tendina(pagina).props('modelValue')).toBe(true);
        expect(campo(pagina).exists()).toBe(false);
    });

    test('dove non si può programmare la voce non c\'è', async () => {
        const pagina = monta({ is_approved: false, data_programmata: null }, { puo_programmare: false });
        await flushPromises();

        expect(valori(pagina).sort()).toEqual([false, true]);
    });
});

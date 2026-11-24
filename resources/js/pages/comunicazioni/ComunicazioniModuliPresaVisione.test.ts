// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I moduli «Crea» e «Modifica» di una comunicazione, nel pannello: la spunta «Chiedi la presa visione»
 * (Coda 594, decisione 310, punto 3).
 *
 * In tutte e due le pagine la spunta è un `Checkbox` con `id="chiedi_presa_visione"`, con la sua etichetta
 * (`comunicazioni.label.ask_acknowledgement`) e la sua spiegazione (`comunicazioni.tooltip.ask_acknowledgement`), ed è
 * legata al campo `chiedi_presa_visione` del modulo.
 * - **Crea**: parte spenta, e la pagina manda sempre il campo.
 * - **Modifica**: parte dal valore ricevuto e lo rimanda; quando il server rimanda la pagina, il campo segue il valore
 *   nuovo. Come `commenti_moderati`, il campo è nel modulo solo se la comunicazione ricevuta lo porta.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';

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

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;
const ComunicazioniEdit = (await import('./ComunicazioniEdit.vue')).default;

type Stato = { submit: () => void; form: Record<string, unknown> };
const stato = (pagina: VueWrapper) => (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
const spunta = (pagina: VueWrapper) => pagina.findAllComponents({ name: 'Checkbox' }).find((c) => c.attributes('id') === 'chiedi_presa_visione');

function montaCrea() {
    return shallowMount(ComunicazioniNew, {
        props: {
            condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true,
            programmazione: { puo_programmare: true, fuso: 'Europe/Rome', pianificatore_attivo: true, adesso: '2026-11-12T11:00' },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

function comunicazione(campi: Record<string, unknown> = {}) {
    return {
        id: 11, subject: 'Lettura dei contatori', description: 'Giovedì passa il letturista.',
        priority: 'media', is_private: false, is_published: true, is_approved: true, is_featured: false, can_comment: false,
        chiedi_presa_visione: false, data_programmata: null,
        condomini: { options: [{ value: 3, label: 'Condominio Aurora' }], full: [] },
        anagrafiche: [],
        ...campi,
    };
}

function montaModifica(campi: Record<string, unknown> = {}) {
    return shallowMount(ComunicazioniEdit, {
        props: { condomini: [], anagrafiche: [], comunicazione: comunicazione(campi) as never },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const mandatoCrea = () => vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;
const mandatoModifica = () => vi.mocked(router.put).mock.calls.at(-1)![1] as Record<string, unknown>;

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
    vi.mocked(router.put).mockClear();
});

describe('ComunicazioniNew — «Chiedi la presa visione»', () => {
    test('la spunta c\'è, con l\'etichetta e la spiegazione', () => {
        const pagina = montaCrea();

        expect(spunta(pagina)).toBeDefined();
        expect(pagina.text()).toContain('comunicazioni.label.ask_acknowledgement');
        expect(pagina.text()).toContain('comunicazioni.tooltip.ask_acknowledgement');
    });

    test('parte spenta, e la pagina manda il campo', async () => {
        const pagina = montaCrea();
        await flushPromises();

        stato(pagina).submit();

        expect(vi.mocked(router.post).mock.calls.at(-1)![0]).toBe('/comunicazioni.store');
        expect(mandatoCrea()).toMatchObject({ chiedi_presa_visione: false });
    });

    test('messa la spunta, la pagina la manda', async () => {
        const pagina = montaCrea();
        await flushPromises();

        stato(pagina).form.chiedi_presa_visione = true;
        stato(pagina).submit();

        expect(mandatoCrea()).toMatchObject({ chiedi_presa_visione: true, is_published: true, data_programmata: null });
    });
});

describe('ComunicazioniEdit — «Chiedi la presa visione»', () => {
    test('la spunta c\'è, con l\'etichetta e la spiegazione', () => {
        const pagina = montaModifica();

        expect(spunta(pagina)).toBeDefined();
        expect(pagina.text()).toContain('comunicazioni.label.ask_acknowledgement');
        expect(pagina.text()).toContain('comunicazioni.tooltip.ask_acknowledgement');
    });

    test('aperta e mandata senza toccare niente, rimanda il valore ricevuto', async () => {
        for (const valore of [true, false]) {
            const pagina = montaModifica({ chiedi_presa_visione: valore });
            await flushPromises();

            stato(pagina).submit();

            expect(vi.mocked(router.put).mock.calls.at(-1)![0]).toBe('/comunicazioni.update');
            expect(mandatoModifica()).toMatchObject({ chiedi_presa_visione: valore });
        }
    });

    test('il valore arriva anche come 0 e 1', async () => {
        const pagina = montaModifica({ chiedi_presa_visione: 1 });
        await flushPromises();

        stato(pagina).submit();

        expect(mandatoModifica().chiedi_presa_visione).toBe(true);
    });

    test('tolta la spunta, la pagina manda falso', async () => {
        const pagina = montaModifica({ chiedi_presa_visione: true });
        await flushPromises();

        stato(pagina).form.chiedi_presa_visione = false;
        stato(pagina).submit();

        expect(mandatoModifica()).toMatchObject({ chiedi_presa_visione: false });
    });

    test('quando il server rimanda la pagina, il campo segue il valore nuovo', async () => {
        // È ciò che succede dopo un errore di validazione, o dopo un salvataggio che resta sulla pagina.
        const pagina = montaModifica({ chiedi_presa_visione: true });
        await flushPromises();
        stato(pagina).form.chiedi_presa_visione = false;

        await pagina.setProps({ comunicazione: comunicazione({ chiedi_presa_visione: true }) as never });
        await flushPromises();
        stato(pagina).submit();

        expect(mandatoModifica()).toMatchObject({ chiedi_presa_visione: true });
    });
});

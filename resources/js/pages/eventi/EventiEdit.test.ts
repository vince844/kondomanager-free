// @vitest-environment jsdom

/**
 * La pagina di modifica di una scadenza in agenda (pannello) legge delle persone soltanto `id` e `nome` (Coda 408).
 *
 * L'elenco della tendina «Anagrafiche» e i destinatari già scelti arrivano con questi due campi e nient'altro: aperta
 * e mandata senza toccare niente, la pagina manda i dati ricevuti, con i destinatari come elenco di id.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const EventiEdit = (await import('./EventiEdit.vue')).default;

function evento(campi: Record<string, unknown> = {}) {
    return {
        id: 21, title: "Assemblea dell'Aurora — «ordinaria» & <straordinaria>", description: "Prima riga\nseconda riga",
        note: "Portare l'elenco\ndelle deleghe", visibility: 'public', category_id: 2, recurrence_id: null,
        start_time: '2026-11-20T18:30', end_time: '2026-11-20T20:00', condomini_ids: [3, 5], recurrence: null,
        anagrafiche: [{ id: 41, nome: 'Anna Neri' }, { id: 42, nome: "Bruno D'Angelo" }],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(EventiEdit, {
        props: {
            condomini: [], categorie: [], evento: evento(campi) as never,
            anagrafiche: [{ id: 41, nome: 'Anna Neri' }, { id: 42, nome: "Bruno D'Angelo" }, { id: 43, nome: 'Carla Rossi' }],
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('EventiEdit — la tendina delle persone con id e nome', () => {
    test('le persone si mostrano per nome, anche i destinatari già scelti', () => {
        const pagina = monta();
        const opzioni = (pagina.vm as unknown as { $: { setupState: { anagraficheOptions: { id: number; nome: string }[] } } }).$.setupState.anagraficheOptions;

        expect(opzioni.map((o) => o.nome)).toEqual(['Anna Neri', "Bruno D'Angelo", 'Carla Rossi']);
    });

    test('aperta e mandata senza toccare niente, manda i dati ricevuti', () => {
        const pagina = monta();

        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/admin.eventi.update');
        expect(chiamata[1]).toEqual({
            title: "Assemblea dell'Aurora — «ordinaria» & <straordinaria>", description: "Prima riga\nseconda riga", condomini_ids: [3, 5],
            category_id: 2, note: "Portare l'elenco\ndelle deleghe", visibility: 'public', start_time: '2026-11-20T18:30',
            end_time: '2026-11-20T20:00', recurrence_frequency: null, recurrence_interval: 1, recurrence_by_day: [],
            recurrence_until: '', anagrafiche: [41, 42], mode: 'only_this', occurrence_date: null,
        });
    });

    test('senza destinatari scelti manda un elenco vuoto', () => {
        const pagina = monta({ anagrafiche: [] });

        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        expect(vi.mocked(router.put).mock.calls.at(-1)![1]).toMatchObject({ anagrafiche: [] });
    });
});

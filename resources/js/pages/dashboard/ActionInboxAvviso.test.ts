// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il messaggio in alto dopo una risposta a un compito si mostra una volta: sparito da solo, non ricompare quando arriva la pagina dopo della lista (un
 * caricamento parziale, che lascia alla pagina il messaggio di prima). Ricompare con la visita intera di una risposta nuova.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const ascoltatori = vi.hoisted(() => ({ start: [] as Array<(e: unknown) => void>, success: [] as Array<(e: unknown) => void> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { patch: vi.fn(), get: vi.fn(), post: vi.fn(), visit: vi.fn(),
        on: (nome: 'start' | 'success', f: (e: unknown) => void) => { ascoltatori[nome]?.push(f); return () => undefined; } },
    usePage: () => ({ url: '/admin/inbox', props: { flash: { message: { type: 'success', message: 'Fatto: il compito è chiuso.' } },
        auth: { user: { roles: [], permissions: ['Modifica scadenza in agenda'] } } } }),
}));
(globalThis as Record<string, unknown>).route = (n: string) => `/${n}`;
const ActionInbox = (await import('./ActionInbox.vue')).default;
const passa = { template: '<div><slot /></div>' };

/** Una visita di Inertia: l'inizio, con le sue chiavi, e la riuscita. */
async function visita(parziale: boolean) {
    const visit = { only: parziale ? ['tasks'] : [], except: [], reset: [] };
    ascoltatori.start.forEach((f) => f({ detail: { visit } }));
    ascoltatori.success.forEach((f) => f({ detail: { page: {} } }));
    await flushPromises();
}

afterEach(() => { vi.useRealTimers(); ascoltatori.start = []; ascoltatori.success = []; });

describe('il messaggio dopo una risposta', () => {
    test('sparito da solo, non torna con la pagina dopo della lista; torna con una risposta nuova', async () => {
        vi.useFakeTimers();
        const w = mount(ActionInbox, {
            props: { tasks: { data: [], total: 0 }, counts: { all: 0, scaduti: 0, settimana: 0, entro_30: 0, bonifici: 0, segnalazioni: 0, piu_vecchio: null },
                grafici: { eta: [], tipi: [], fuori: { segnalazioni: 0, bonifici: 0, oltre_30: 0 } }, dove: [], schede: { tutti: 0, scaduti: 0, in_arrivo: 0 },
                gruppi: { scaduti: 0, segnalazioni: 0, bonifici: 0, avanti: 0 }, modo: 'elenco', anteprima: [],
                filtri: { quando: null, tipo: null, cerca: null, ordine: 'vecchi', condominio_id: null, scheda: 'tutti' }, condomini: [], seiMesiCondomini: [],
                oggi: '2026-10-28' } as never,
            global: { mocks: { route: (globalThis as Record<string, unknown>).route }, stubs: { AppLayout: passa, RisposteDelCompito: true } },
        });
        await flushPromises();
        expect(w.text()).toContain('Fatto: il compito è chiuso.');
        vi.advanceTimersByTime(7000);
        await flushPromises();
        expect(w.text()).not.toContain('Fatto: il compito è chiuso.');

        await visita(true);
        expect(w.text()).not.toContain('Fatto: il compito è chiuso.');

        await visita(false);
        expect(w.text()).toContain('Fatto: il compito è chiuso.');
        w.unmount();
    });
});

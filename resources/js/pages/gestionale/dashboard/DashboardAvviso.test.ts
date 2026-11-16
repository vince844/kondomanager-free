// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La dashboard del condominio: il messaggio in alto dopo una risposta (a un compito dell'Inbox o ai sei mesi) si mostra una volta. Sparito da solo, non
 * ricompare con i caricamenti parziali (lo scorrimento dell'Inbox, il riquadro dei sei mesi ricaricato); ricompare con la visita intera di una risposta nuova.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const ascoltatori = vi.hoisted(() => ({ start: [] as Array<(e: unknown) => void>, success: [] as Array<(e: unknown) => void> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(),
        on: (nome: 'start' | 'success', f: (e: unknown) => void) => { ascoltatori[nome]?.push(f); return () => undefined; } },
    usePage: () => ({ props: { flash: { message: { type: 'success', message: 'Fatto: il compito è chiuso.' } }, auth: { user: { roles: [], permissions: [] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;
const passa = { template: '<div><slot /></div>' };
const compiti = { total: 0, data: [] };

async function monta() {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { virtuale: 0, addebiti_personali: 0, uscite_totali: 0, scoperto: 0, is_completo: true, has_sforo: false, orfani: [], scoperto_count: 0, spese: 0, entrate: 0, da_ripartire: 0, preventivo: 0 } as never,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: compiti, widgets: {}, oggi: '2026-10-28',
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
                DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa, DropdownMenuItem: passa, DropdownMenuSeparator: passa,
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

async function visita(only: string[]) {
    const visit = { only, except: [], reset: [] };
    ascoltatori.start.forEach((f) => f({ detail: { visit } }));
    ascoltatori.success.forEach((f) => f({ detail: { page: {} } }));
    await flushPromises();
}

afterEach(() => {
    vi.useRealTimers();
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
    ascoltatori.start = [];
    ascoltatori.success = [];
});

describe('il messaggio dopo una risposta, nella dashboard del condominio', () => {
    test('sparito da solo, non torna con lo scorrimento dell\'Inbox né con il riquadro dei sei mesi ricaricato; torna con una risposta nuova', async () => {
        vi.useFakeTimers();
        await monta();
        const testo = () => document.body.textContent ?? '';
        expect(testo()).toContain('Fatto: il compito è chiuso.');
        vi.advanceTimersByTime(7000);
        await flushPromises();
        expect(testo()).not.toContain('Fatto: il compito è chiuso.');

        await visita(['inboxTasks']);
        expect(testo()).not.toContain('Fatto: il compito è chiuso.');
        await visita(['seiMesi', 'compitiSeiMesi', 'widgets']);
        expect(testo()).not.toContain('Fatto: il compito è chiuso.');

        await visita([]);
        expect(testo()).toContain('Fatto: il compito è chiuso.');
    });
});

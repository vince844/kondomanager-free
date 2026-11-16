// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La prossima azione di un compito, nell'Action inbox e nell'Inbox operativa del condominio, è il collegamento più usato delle due pagine: un indirizzo del
 * sito si apre con un collegamento di Inertia, con il prefetch, senza ricaricare la pagina da capo. Un indirizzo di un'altra origine resta un collegamento
 * normale (Inertia non lo può aprire).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { name: 'Link', props: ['href', 'prefetch'], template: '<a data-inertia="si" :href="href" :data-prefetch="prefetch === undefined || prefetch === false ? null : \'si\'"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const RigaDelCompito = (await import('./RigaDelCompito.vue')).default;
const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const DEL_SITO = '/admin/gestionale/7/pagamenti-fornitori/create?fattura_id=12';
const ALTRA_ORIGINE = 'https://banca.example/bonifici/12';
const azione = () => document.body.querySelector('[data-testid="prossima-azione"]') as HTMLElement;

const riga = (url: string) => ({
    id: 1, title: 'Pagare Idraulica Rossi', titolo: 'Pagare Idraulica Rossi', sotto: 'Fattura n. F-12 · € 450,00', description: '', date: '2026-11-13T09:00:00Z',
    giorno: '2026-11-13', giorni: -2, fascia: 'ultimi_30', gruppo: 'scaduti', condominio: 'Via Verdi 12', condominio_id: 7, type: 'pagamento_fornitore',
    azione: { testo: 'Paga la fattura', url }, context: { anagrafica_nome: null, action_url: url, related_id: null }, risposta: null, promemoria_a_parte: false,
    scadenza: null, fatto_spento: null, priorita: null, action_url: url, status: 'expired', days_pending: 2,
});

async function riga_(url: string) {
    w = mount(RigaDelCompito, { attachTo: document.body, props: { riga: riga(url), puoChiudere: true } as never, global: { stubs: { MenuDelCompito: true } } });
    await flushPromises();
}

async function dashboard(url: string) {
    const passa = { template: '<div><slot /></div>' };
    w = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 7, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [], copertura: null,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 1, data: [riga(url)] }, widgets: {}, oggi: '2026-11-15', inboxScaduti: 1,
            inboxSchede: { tutti: 1, scaduti: 1, in_arrivo: 0 },
        },
        global: {
            stubs: {
                GestionaleLayout: passa, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                SeiMesiPerAgireWidget: true, SeiMesiRisposte: true, RisposteDelCompito: true, DashboardCondominioGuide: true, MenuDelCompito: true,
                TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: passa,
            },
        },
    });
    await flushPromises();
}

describe('la prossima azione di un compito si apre con Inertia', () => {
    test.each([
        ['nell\'Action inbox (RigaDelCompito)', riga_],
        ['nell\'Inbox operativa del condominio', dashboard],
    ])('%s: un indirizzo del sito è un collegamento di Inertia con il prefetch', async (_, monta) => {
        await monta(DEL_SITO);
        expect(azione().getAttribute('data-inertia')).toBe('si');
        expect(azione().getAttribute('data-prefetch')).toBe('si');
        expect(azione().getAttribute('href')).toBe(DEL_SITO);
    });

    test.each([
        ['nell\'Action inbox (RigaDelCompito)', riga_],
        ['nell\'Inbox operativa del condominio', dashboard],
    ])('%s: un indirizzo di un\'altra origine resta un collegamento normale', async (_, monta) => {
        await monta(ALTRA_ORIGINE);
        expect(azione().tagName).toBe('A');
        expect(azione().getAttribute('data-inertia')).toBeNull();
        expect(azione().getAttribute('href')).toBe(ALTRA_ORIGINE);
    });
});

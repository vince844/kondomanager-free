// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La fila della tesoreria nella dashboard del condominio: sempre due terzi e un terzo; a sinistra la tesoreria, a destra la card «Saldo
 * previsto» e sotto, quando c'è, «Crediti da compensare». Le card tengono la loro altezza (`items-start`); al telefono una colonna, nello stesso ordine. La
 * tesoreria riceve, per ogni persona, la riga dei sei mesi dove è la sua prossima azione. La guida dell'intestazione dice ciò che la tesoreria fa.
 *
 * Lo scenario scelto nella card «Saldo previsto» arriva alla tesoreria («Fra 30 giorni»); la card riceve i dati delle azioni consigliate. L'intestazione ha
 * «Guida» con il cassetto, in una fila che non va a capo; le tre card restano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: [] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;
const { tesoreria } = await import('@/pages/gestionale/dashboard/components/tesoreriaDiProva');

const Tesoreria = { name: 'TreasuryGuardianWidget', props: ['treasury', 'righeSeiMesi', 'scenario'], template: '<div data-testid="tesoreria-finta" />' };
const PageHeaderGuide = { name: 'PageHeaderGuide', props: ['guides', 'pageTitle', 'pageSubtitle', 'breadcrumbs', 'condominio', 'condomini', 'esercizio', 'esercizi', 'hasTextGuide',
    'textGuideTitle', 'unaFila'], emits: ['open-text-guide'], template: '<div />' };
const Saldo = { name: 'SaldoPrevistoCard', props: ['saldoPrevisto', 'scenario', 'recupero', 'suggerisce', 'condominioId', 'oggi'], emits: ['update:scenario'],
    template: '<div data-testid="saldo-finto" />' };
const Guida = { name: 'DashboardCondominioGuide', props: ['open'], emits: ['update:open'], template: '<div v-if="open" data-testid="guida-finta" />' };
let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

async function monta(widgets: Record<string, unknown>, altro: Record<string, unknown> = {}) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 7, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: null, pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets, ...altro,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide, TreasuryGuardianWidget: Tesoreria, ControlliPostImportWidget: true,
                CreditiDaCompensareWidget: { template: '<div data-testid="crediti-finti" />' }, SaldoPrevistoCard: Saldo, DashboardCondominioGuide: Guida,
                SeiMesiPerAgireWidget: true, SeiMesiRisposte: true, RisposteDelCompito: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
    await flushPromises();
}
const fila = () => document.body.querySelector('[data-testid="fila-tesoreria"]')!;

describe('la fila della tesoreria', () => {
    test('due terzi e un terzo: la tesoreria a sinistra, a destra il saldo previsto sopra i crediti; le card tengono la loro altezza', async () => {
        await monta({ treasury_guardian: tesoreria(), crediti_da_compensare: [{ anagrafica_id: 1 }] });
        expect(fila().className).toContain('lg:grid-cols-3');
        expect(fila().className).toContain('items-start');
        expect(fila().className).toContain('grid-cols-1');
        const [sinistra, destra] = Array.from(fila().children) as HTMLElement[];
        expect(sinistra.getAttribute('data-testid')).toBe('tesoreria-finta');
        expect(sinistra.className).toContain('lg:col-span-2');
        expect(destra.className).toContain('lg:col-span-1');
        expect(Array.from(destra.children).map((c) => c.getAttribute('data-testid'))).toEqual(['saldo-finto', 'crediti-finti']);
    });

    test('senza crediti da compensare, a destra il solo saldo previsto, e la fila resta due terzi e un terzo', async () => {
        await monta({ treasury_guardian: tesoreria() });
        const destra = fila().children[1] as HTMLElement;
        expect(destra.className).toContain('lg:col-span-1');
        expect(Array.from(destra.children).map((c) => c.getAttribute('data-testid'))).toEqual(['saldo-finto']);
    });

    test('la tesoreria riceve la riga dei sei mesi di ogni persona: la prima in cui compare', async () => {
        const riga = (eid: number, persone: number[]) => ({ chiave: `sei-mesi-${eid}`, tutte: persone.map((id) => ({ anagrafica_id: id, nome: `P${id}`, cifra: 1, situazione: '' })), persone: [] });
        await monta({ treasury_guardian: tesoreria() }, { seiMesi: { oggi: '2026-11-15', righe: [riga(25, [1, 2]), riga(26, [2, 3])], rendiconti: [], compiti: [] } });
        expect(pagina!.getComponent(Tesoreria).props('righeSeiMesi')).toEqual({ 1: 'sei-mesi-25', 2: 'sei-mesi-25', 3: 'sei-mesi-26' });
    });

    test('la guida dell\'intestazione dice ciò che la tesoreria fa: la fila, i due cassetti, il grafico, «Da dove viene»', async () => {
        await monta({ treasury_guardian: tesoreria() });
        const carte = pagina!.getComponent(PageHeaderGuide).props('guides') as Array<{ title: string; description: string }>;
        const tesoreriaNellaGuida = carte.find((c) => c.title === 'Tesoreria a 30 giorni');
        expect(tesoreriaNellaGuida?.description).toContain('«Da dove viene»');
        expect(tesoreriaNellaGuida?.description).toContain('due cassetti');
        expect(tesoreriaNellaGuida?.description).toContain('saldo previsto giorno per giorno');
        expect(carte).toHaveLength(3);
    });

    test('lo scenario scelto nella card arriva alla tesoreria; la card riceve i dati delle azioni consigliate', async () => {
        await monta({ treasury_guardian: tesoreria({ suggerisceAzione: false }) });
        const card = pagina!.getComponent(Saldo);
        expect(card.props('scenario')).toBe('senza');
        expect(pagina!.getComponent(Tesoreria).props('scenario')).toBe('senza');
        expect(card.props('recupero')).toEqual((tesoreria() as any).recupero);
        expect(card.props('suggerisce')).toBe(false);
        expect(card.props('condominioId')).toBe(7);
        expect(card.props('oggi')).toBe('2026-11-15');
        card.vm.$emit('update:scenario', 'tutti');
        await flushPromises();
        expect(pagina!.getComponent(Tesoreria).props('scenario')).toBe('tutti');
        expect(pagina!.getComponent(Saldo).props('scenario')).toBe('tutti');
    });
});

describe('la «Guida» della dashboard del condominio', () => {
    test('il pulsante nell\'intestazione, in una fila che non va a capo, apre il cassetto con la guida; le tre card restano', async () => {
        await monta({ treasury_guardian: tesoreria() });
        const intestazione = pagina!.getComponent(PageHeaderGuide);
        expect(intestazione.props('hasTextGuide')).toBe(true);
        expect(intestazione.props('textGuideTitle')).toBe('Guida');
        expect(intestazione.props('unaFila')).toBe(true);
        expect(intestazione.props('guides')).toHaveLength(3);
        expect(document.body.querySelector('[data-testid="guida-finta"]')).toBeNull();
        intestazione.vm.$emit('open-text-guide');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-finta"]')).not.toBeNull();
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I sei mesi per agire nel cruscotto del condominio: i compiti calcolati stanno in cima all'Inbox operativa, sopra quelli dell'agenda, e il numero della
 * scheda «Tutte» nella testata li somma; il riquadro sta a tutta larghezza prima della fila dei crediti e della tesoreria. Senza le due prop la pagina è quella di prima.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Modifica scadenza in agenda'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

const compiti = {
    total: 2,
    data: [{ id: 11, type: 'controllo_incassi', title: 'Verifica incassi - Rata 3', description: 'Rata 3', action_url: '/x', status: 'scheduled', days_pending: 0, context: { anagrafica_nome: null } }],
};
const compitoSeiMesi = {
    chiave: 'sei-mesi-25', argomento: 'sei_mesi', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-30', giorni: 41, colore: 'vicina', quante: 4, cifra: 324000,
    cartellini: { senza_lettera: 2, sollecito: 0, diffida_spedita: 1, diffida_da_spedire: 0, accordo: 0, dispensata: 0 }, tornato: null, ancora: 'sei-mesi-25', tutte: [],
};

let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

async function monta(altro: Record<string, unknown>) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { virtuale: 0, addebiti_personali: 0, uscite_totali: 0, scoperto: 0, is_completo: true, has_sforo: false, orfani: [], scoperto_count: 0, spese: 0, entrate: 0, da_ripartire: 0 } as never,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: compiti, widgets: {}, ...altro,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                SeiMesiPerAgireWidget: { template: '<div data-testid="riquadro-sei-mesi" />' }, SeiMesiRisposte: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('i sei mesi nel cruscotto del condominio', () => {
    test('il compito dei sei mesi in cima all\'Inbox, e il numero in testa somma i due', async () => {
        await monta({ compitiSeiMesi: [compitoSeiMesi], seiMesi: { oggi: '2026-05-20', righe: [], rendiconti: [], compiti: [compitoSeiMesi] } });
        expect(testo()).toContain('Tutte 3');
        const lista = document.body.querySelector('[data-testid="compiti-sei-mesi"]')!;
        expect(lista.textContent).toContain('Sei mesi per agire: esercizio 2025');
        // Sopra il compito dell'agenda.
        expect(testo().indexOf('Sei mesi per agire: esercizio 2025')).toBeLessThan(testo().indexOf('Verifica incassi - Rata 3'));
        expect(document.body.querySelector('[data-testid="riquadro-sei-mesi"]')).not.toBeNull();
    });

    test('senza le due prop la pagina è quella di prima', async () => {
        await monta({});
        expect(testo()).toContain('Tutte 2');
        expect(document.body.querySelector('[data-testid="compiti-sei-mesi"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="riquadro-sei-mesi"]')).toBeNull();
    });
});

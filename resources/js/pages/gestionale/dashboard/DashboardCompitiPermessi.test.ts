// @vitest-environment jsdom

/**
 * I compiti della casella nel cruscotto del gestionale (Coda 402).
 *
 * Il cruscotto elenca i compiti della casella delle attività. «Rifiuta» (una segnalazione di pagamento) e «Completa» (gli
 * altri compiti) chiudono un compito, che è una scadenza in agenda: le rotte `admin.inbox.reject` e `admin.inbox.complete`
 * chiedono «Modifica scadenza in agenda» (`EventoPolicy::chiudiCompito`), e chi non lo ha non vede i due comandi.
 * «Registra» e «Risolvi» portano alla pagina del compito e restano. I ruoli di serie che entrano nel gestionale hanno il
 * permesso.
 *
 * La pagina è quella vera; si stubbano la cornice, la guida e i widget, come negli altri test del cruscotto.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
const inertia = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: inertia.patch, reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], get permissions() { return chiGuarda.permessi; } } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;

const compiti = {
    total: 2,
    data: [
        { id: 11, type: 'verifica_pagamento', title: 'Verifica pagamento', description: "Rata di Mario D'Àngelo", action_url: '/admin/incassi/1', status: 'scheduled', days_pending: 0, context: { anagrafica_nome: "Mario D'Àngelo" } },
        { id: 12, type: 'scadenza_fattura', title: 'Fattura in scadenza', description: 'Idraulica', action_url: '/admin/fatture/4', status: 'expired', days_pending: 27, context: { anagrafica_nome: null } },
    ],
};

async function monta() {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { virtuale: 0, addebiti_personali: 0, uscite_totali: 0, scoperto: 0, is_completo: true, has_sforo: false, orfani: [], scoperto_count: 0, spese: 0, entrate: 0, da_ripartire: 0, preventivo: 0 } as never,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: compiti, widgets: {},
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

beforeEach(() => {
    chiGuarda.permessi = [];
    inertia.patch.mockClear();
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il cruscotto del gestionale — «Rifiuta» e «Completa» chiedono «Modifica scadenza in agenda» (Coda 402)', () => {
    test('senza il permesso, anche con la consultazione dell\'agenda, si vedono i compiti, con «Registra» e «Risolvi», senza «Rifiuta» e «Completa»', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda'];
        await monta();

        const testo = document.body.textContent ?? '';
        expect(testo).toContain('Verifica pagamento');
        expect(testo).toContain('Registra');
        expect(testo).toContain('Risolvi');
        expect(document.body.querySelector('button[title="Rifiuta segnalazione"]')).toBeNull();
        expect(document.body.querySelector('button[title="Segna come completato"]')).toBeNull();
    });

    test('con «Modifica scadenza in agenda», come i ruoli di serie, ci sono «Rifiuta» e «Completa», e «Completa» chiama la sua rotta', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'];
        await monta();

        expect(document.body.querySelector('button[title="Rifiuta segnalazione"]')).not.toBeNull();
        (document.body.querySelector('button[title="Segna come completato"]') as HTMLButtonElement).click();
        expect(inertia.patch).toHaveBeenCalledTimes(1);
        expect(inertia.patch.mock.calls[0][0]).toBe('/admin.inbox.complete');
    });
});

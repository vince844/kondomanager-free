// @vitest-environment jsdom

/**
 * La casella delle attività del pannello (Coda 402).
 *
 * La casella si apre con «Visualizza scadenze in agenda» (`EventoPolicy::viewAny`). «Rifiuta» (una segnalazione di
 * pagamento) e «Completa» (gli altri compiti) chiudono un compito, che è una scadenza in agenda: le rotte
 * `admin.inbox.reject` e `admin.inbox.complete` chiedono «Modifica scadenza in agenda» (`EventoPolicy::chiudiCompito`), e
 * chi ha la sola consultazione non vede i due comandi. «Registra» e «Risolvi» portano alla pagina del compito e restano.
 * I ruoli di serie che aprono la casella hanno tutti e due i permessi.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
const inertia = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { patch: inertia.patch, get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        url: '/admin/inbox',
        props: { flash: {}, auth: { user: { roles: [], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ActionInbox = (await import('./ActionInbox.vue')).default;

const passa = { template: '<div><slot /></div>' };

const compiti = [
    {
        id: 11, type: 'verifica_pagamento', status: 'scheduled', date: '2026-10-20T09:00:00', days_pending: 0, condominio: 'Condominio Aurora',
        title: 'Verifica pagamento', description: "Rata di Mario D'Àngelo", amount: 38000,
        context: { anagrafica_nome: "Mario D'Àngelo", action_url: '/admin/gestionale/1/incassi/create' },
    },
    {
        id: 12, type: 'scadenza_fattura', status: 'expired', date: '2026-10-01T09:00:00', days_pending: 27, condominio: 'Condominio Aurora',
        title: 'Fattura in scadenza', description: 'Idraulica', amount: null, context: { action_url: '/admin/gestionale/1/fatture/4' },
    },
];

function monta() {
    return mount(ActionInbox, {
        props: {
            tasks: { data: compiti, links: [], total: compiti.length },
            counts: { all: 2, urgent: 1, payments: 1, maintenance: 0 },
            activeFilter: 'all',
            condomini: [{ id: 1, nome: 'Condominio Aurora' }],
            condominioFilter: null,
        } as never,
        global: {
            mocks: { route: (globalThis as Record<string, unknown>).route },
            stubs: { AppLayout: passa, Dialog: true, DialogContent: true, DialogDescription: true, DialogFooter: true, DialogHeader: true, DialogTitle: true },
        },
    });
}

beforeEach(() => {
    chiGuarda.permessi = [];
    inertia.patch.mockClear();
});

describe('la casella delle attività — «Rifiuta» e «Completa» chiedono «Modifica scadenza in agenda» (Coda 402)', () => {
    test('con la sola consultazione si vedono i compiti, con «Registra» e «Risolvi», senza «Rifiuta» e «Completa»', () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda'];
        const pagina = monta();

        expect(pagina.text()).toContain('Verifica pagamento');
        expect(pagina.text()).toContain('Fattura in scadenza');
        expect(pagina.text()).toContain('Registra');
        expect(pagina.text()).toContain('Risolvi');
        expect(pagina.find('button[title="Rifiuta segnalazione"]').exists()).toBe(false);
        expect(pagina.find('button[title="Segna come completato"]').exists()).toBe(false);
    });

    test('con «Modifica scadenza in agenda», come i ruoli di serie, ci sono «Rifiuta» e «Completa», e «Completa» chiama la sua rotta', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'];
        const pagina = monta();

        expect(pagina.find('button[title="Rifiuta segnalazione"]').exists()).toBe(true);
        await pagina.find('button[title="Segna come completato"]').trigger('click');
        expect(inertia.patch).toHaveBeenCalledTimes(1);
        expect(inertia.patch.mock.calls[0][0]).toBe('/admin.inbox.complete');
    });
});

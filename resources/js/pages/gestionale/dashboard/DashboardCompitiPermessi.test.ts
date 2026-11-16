// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I compiti della casella nel cruscotto del gestionale.
 *
 * Il cruscotto elenca i compiti della casella delle attività. Le risposte a un compito («Fatto, con una nota», «Non serve, con il motivo», «Ricordamelo
 * il…») stanno nel menu «⋯» di ogni riga, al posto dei due pulsanti quadrati «Completa» e «Rifiuta»: la rotta `admin.inbox.risposta` chiede «Modifica
 * scadenza in agenda» (`EventoPolicy::chiudiCompito`), e chi non lo ha non vede il menu. Sul bonifico segnalato dal portale «Non serve» è il rifiuto della
 * segnalazione. La prossima azione del tipo («Registra l'incasso», «Paga la fattura», la mappa unica delle due Inbox) porta alla pagina del compito e resta. I
 * ruoli di serie che entrano nel gestionale hanno il permesso.
 *
 * La pagina è quella vera; si stubbano la cornice, la guida e i widget, come negli altri test del cruscotto.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: inertia.post, get: vi.fn(), patch: inertia.patch, reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], get permissions() { return chiGuarda.permessi; } } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;
const passa = { template: '<div><slot /></div>' };
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

const compiti = {
    total: 2,
    data: [
        { id: 11, type: 'verifica_pagamento', title: 'Verifica pagamento', titolo: 'Verifica pagamento', sotto: "Mario D'Àngelo · € 123,45", description: "Rata di Mario D'Àngelo",
            action_url: '/admin/incassi/1', status: 'scheduled', days_pending: 0, giorno: '2026-10-27', giorni: -1, gruppo: 'bonifici', priorita: null,
            azione: { testo: 'Registra l\'incasso', url: '/admin/incassi/1' }, context: { anagrafica_nome: "Mario D'Àngelo" }, risposta: null, promemoria_a_parte: false },
        { id: 12, type: 'pagamento_fornitore', title: 'Fattura in scadenza', titolo: 'Fattura in scadenza', sotto: 'Fattura n. 4 · € 450,00', description: 'Idraulica',
            action_url: '/admin/fatture/4', status: 'expired', days_pending: 27, giorno: '2026-10-01', giorni: -27, gruppo: 'scaduti', priorita: null,
            azione: { testo: 'Paga la fattura', url: '/admin/fatture/4' }, context: { anagrafica_nome: null },
            risposta: { tornato: 'È arrivato un commento nuovo dopo il tuo «Ricordamelo»: il compito è tornato.', registro: [] }, promemoria_a_parte: true },
    ],
};

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

beforeEach(() => {
    chiGuarda.permessi = [];
    inertia.patch.mockClear();
    inertia.post.mockClear();
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il cruscotto del gestionale — le risposte ai compiti chiedono «Modifica scadenza in agenda»', () => {
    test('senza il permesso, anche con la consultazione dell\'agenda, si vedono i compiti, con la prossima azione del tipo, senza il menu delle risposte', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda'];
        await monta();

        const t = testo();
        expect(t).toContain('Verifica pagamento');
        expect(t).toContain('Registra l\'incasso');
        expect(t).toContain('Paga la fattura');
        expect(t).not.toContain('Risolvi');
        expect(document.body.querySelector('[data-testid="menu-del-compito"]')).toBeNull();
        expect(document.body.querySelector('button[title="Rifiuta segnalazione"]')).toBeNull();
        expect(document.body.querySelector('button[title="Segna come completato"]')).toBeNull();
    });

    test('con «Modifica scadenza in agenda», come i ruoli di serie, ogni compito ha il menu «⋯» quadrato al posto dei due pulsanti; sul bonifico «Non serve» rifiuta la segnalazione', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'];
        await monta();

        const menu = [...document.body.querySelectorAll('[data-testid="menu-del-compito"]')];
        expect(menu).toHaveLength(2);
        expect(menu.every((m) => m.classList.contains('w-7') && m.classList.contains('h-7'))).toBe(true);
        expect(document.body.querySelector('button[title="Rifiuta segnalazione"]')).toBeNull();
        expect(document.body.querySelector('button[title="Segna come completato"]')).toBeNull();
        expect([...document.body.querySelectorAll('[data-testid^="voce-"]')].map((v) => (v.textContent ?? '').trim())).toEqual([
            'Fatto, con una nota', 'Non serve: rifiuta la segnalazione', 'Ricordamelo il…', 'Fatto, con una nota', 'Non serve, con il motivo', 'Ricordamelo il…']);
        expect(testo()).toContain('È arrivato un commento nuovo dopo il tuo «Ricordamelo»: il compito è tornato.');
    });

    test('«Ricordamelo il…» sul pagamento di una fattura apre la finestra con il giorno da domani e la frase del promemoria a parte; la risposta va alla sua rotta', async () => {
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza scadenze in agenda', 'Modifica scadenza in agenda'];
        await monta();
        document.body.querySelectorAll('[data-testid="voce-ricordamelo"]')[1].dispatchEvent(new Event('select'));
        await flushPromises();

        expect(testo()).toContain('Il giorno va nell\'agenda.');
        expect(testo()).toContain('Il compito resta nell\'agenda al suo giorno (la scadenza): il promemoria è un evento a parte.');
        expect((document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement).min).toBe('2026-10-29');
        (document.body.querySelector('[data-testid="conferma"]') as HTMLButtonElement).click();
        expect(inertia.post).toHaveBeenCalledWith('/admin.inbox.risposta', { tipo: 'ricordamelo', giorno: '2026-10-29', nota: '' }, expect.anything());
        expect(inertia.patch).not.toHaveBeenCalled();
    });
});

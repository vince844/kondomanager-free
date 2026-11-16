// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Nella dashboard del condominio la card «Copertura bilancio» non ha scritte sotto gli 11 pixel, in nessuno dei suoi stati (11 per le etichette piccole,
 * 12 per il resto), e l'etichetta «Fondi/Extra» non si tronca: intera, su due righe se non ci sta, con la cifra che va sotto quando la card è stretta.
 * L'Inbox operativa non ha più il cartellino rosso in alto a destra: diceva lo stesso numero della scheda «Scadute», che ora sta nella testata accanto al
 * titolo, rossa quando ce n'è e grigia a zero. I numeri delle schede sono quelli di tutte le schede, non della scheda scelta.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

const base = { spese: 120000, entrate: 0, da_ripartire: 120000, pianificato: 100000, virtuale: 5000, addebiti_personali: 3000, uscite_totali: 50000, scoperto: 0, delta: 0,
    percentuale: 90, is_completo: false, has_sforo: true, has_scoperti_documentati: false, orfani: [], scoperto_count: 0 };
const orfano = (strategia: string) => ({ id: 1, nome: 'Pulizie', importo: 15000, strategia, gestione: 'Ordinaria 2026' });

/** Gli stati della card, con le cifre che li fanno scattare. */
const STATI: Record<string, Record<string, unknown>> = {
    disallineato: { copertura: base, pianiDisallineati: [{ id: 3, nome: 'Preventivo 2026', gestione: 'Ordinaria 2026', delta: 2500 }] },
    scoperto: { copertura: { ...base, orfani: [orfano('rata')], scoperto_count: 1 } },
    documentato: { copertura: { ...base, orfani: [orfano('rata')], has_scoperti_documentati: true } },
    integrato: { copertura: { ...base, orfani: [orfano('conguaglio')] } },
    eccedenza: { copertura: { ...base, delta: -10000 } },
    allineato: { copertura: base },
};

const riga = (id: number, gruppo: string) => ({ id, type: 'scadenza', title: `Compito ${id}`, titolo: `Compito ${id}`, sotto: '', description: '', action_url: null,
    status: gruppo === 'scaduti' ? 'expired' : 'scheduled', days_pending: 0, giorno: '2026-11-15', giorni: gruppo === 'scaduti' ? -2 : 3, gruppo,
    azione: { testo: 'Segna come fatto', url: null }, context: { anagrafica_nome: null }, risposta: null, promemoria_a_parte: false, priorita: null });

async function monta(altro: Record<string, unknown>) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 7, nome: 'Via Verdi 12' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [], copertura: null,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets: {}, oggi: '2026-11-15', ...altro,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                SeiMesiPerAgireWidget: true, SeiMesiRisposte: true, RisposteDelCompito: true, DashboardCondominioGuide: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
            },
        },
    });
    await flushPromises();
}
const cartellino = () => document.body.querySelector('[data-testid="attivita-inbox"]') as HTMLElement | null;
const testata = () => document.body.querySelector('[data-testid="testata-inbox"]') as HTMLElement | null;
const schede = () => [...(testata()?.querySelectorAll('[data-testid^="scheda-inbox-"]') ?? [])].map((b) => pulito(b.textContent));
const numeroScadute = () => testata()?.querySelector('[data-testid="scheda-inbox-scaduti"] span') as HTMLElement | null;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

describe('la card «Copertura bilancio»', () => {
    test.each(Object.keys(STATI))('nello stato «%s» nessuna scritta sotto gli 11 pixel', async (stato) => {
        await monta(STATI[stato]);
        const card = document.body.querySelector('[data-testid="copertura-bilancio"]') as HTMLElement;
        expect(card).not.toBeNull();
        const piccole = [card, ...card.querySelectorAll('*')].filter((el) => /(^|\s)text-\[(\d|10)px\]/.test(el.getAttribute('class') ?? ''))
            .map((el) => `${el.tagName}: ${pulito(el.textContent).slice(0, 40)}`);
        expect(piccole).toEqual([]);
    });
});

describe('l\'etichetta «Fondi/Extra»', () => {
    test('intera, senza puntini: va su due righe dopo la barra, e la cifra va sotto quando la card è stretta', async () => {
        await monta({ copertura: base });
        const etichetta = document.body.querySelector('[data-testid="etichetta-fondi-extra"]') as HTMLElement;
        expect(etichetta).not.toBeNull();
        expect(etichetta.childNodes[0].textContent).toBe('Fondi/');
        expect(etichetta.className).not.toContain('truncate');
        expect(etichetta.childNodes[1].nodeName).toBe('WBR');
        // «Extra» e la «i» restano insieme: la «i» non va da sola su una riga.
        const insieme = etichetta.querySelector('.whitespace-nowrap') as HTMLElement;
        expect(insieme.querySelector('[data-testid="parola-extra"]')?.textContent).toBe('Extra');
        expect(insieme.querySelector('svg')).not.toBeNull();
        const riga = etichetta.parentElement as HTMLElement;
        expect(riga.className).toContain('flex-wrap');
        const cifra = riga.querySelector('[data-testid="cifra-fondi-extra"]') as HTMLElement;
        expect(cifra.className).toContain('ml-auto');
        expect(cifra.className).toContain('whitespace-nowrap');
    });

    test('nel sorgente nessun «truncate» sull\'etichetta', () => {
        const sorgente = readFileSync(resolve(__dirname, 'Dashboard.vue'), 'utf8');
        const riga = sorgente.split('\n').find((r) => r.includes('data-testid="etichetta-fondi-extra"')) ?? '';
        expect(riga).not.toBe('');
        expect(riga).not.toContain('truncate');
    });
});

describe('le schede nella testata, senza il cartellino', () => {
    const passato = { chiave: 'sei-mesi-25', argomento: 'sei_mesi', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-11-01', giorni: -14, colore: 'passata',
        tornato: null, ancora: 'sei-mesi-25', tutte: [] };

    test('tutti scaduti: nessun cartellino; le schede nella testata, «Scadute 3» rosso, lo zero grigio', async () => {
        await monta({ inboxTasks: { total: 3, data: [riga(1, 'scaduti'), riga(2, 'scaduti'), riga(3, 'scaduti')] }, inboxScaduti: 3, inboxSchede: { tutti: 3, scaduti: 3, in_arrivo: 0 } });
        expect(cartellino()).toBeNull();
        expect(schede()).toEqual(['Tutte 3', 'Scadute 3', 'In arrivo 0']);
        expect(numeroScadute()?.className).toContain('text-red-600');
        expect((testata()?.querySelector('[data-testid="scheda-inbox-in_arrivo"] span') as HTMLElement).className).toContain('text-slate-400');
    });

    test('nessuno scaduto: «Scadute 0» in grigio, mai rosso', async () => {
        await monta({ inboxTasks: { total: 3, data: [riga(1, 'avanti'), riga(2, 'avanti'), riga(3, 'avanti')] }, inboxScaduti: 0, inboxSchede: { tutti: 3, scaduti: 0, in_arrivo: 3 } });
        expect(cartellino()).toBeNull();
        expect(schede()).toEqual(['Tutte 3', 'Scadute 0', 'In arrivo 3']);
        expect(numeroScadute()?.className).toContain('text-slate-400');
        expect(numeroScadute()?.className).not.toContain('text-red');
    });

    test('un compito dei sei mesi con la data passata conta fra le scadute', async () => {
        await monta({ inboxTasks: { total: 1, data: [riga(1, 'avanti')] }, inboxScaduti: 0, inboxSchede: { tutti: 1, scaduti: 0, in_arrivo: 1 }, compitiSeiMesi: [passato] });
        expect(schede()).toEqual(['Tutte 2', 'Scadute 1', 'In arrivo 1']);
        expect(numeroScadute()?.className).toContain('text-red-600');
    });

    test('con la scheda «Scadute» scelta i numeri contano ancora tutte le attività, non la pagina caricata', async () => {
        await monta({ inboxTasks: { total: 12, data: [riga(1, 'scaduti')] }, inboxScaduti: 12, inboxSchede: { tutti: 20, scaduti: 12, in_arrivo: 8 }, inboxScheda: 'scaduti' });
        expect(schede()).toEqual(['Tutte 20', 'Scadute 12', 'In arrivo 8']);
        expect(cartellino()).toBeNull();
    });
});

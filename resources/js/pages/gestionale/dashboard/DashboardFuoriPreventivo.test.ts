// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 693 (f), decisione 532: fra gli «Sfori del preventivo» della finestra delle voci non coperte, la Dashboard riconosce le voci nate dal pulsante «Fuori
 * preventivo» dal segno tecnico che il server manda (`fuori_preventivo`), non dal nome «Imprevisto -». Quelle voci stanno già fra le «Fatture in sospeso»:
 * una voce rinominata non deve comparire due volte, né avere «Gestisci sforo»; una voce del preventivo che l'amministratore ha chiamato «Imprevisto - …» è
 * uno sforo come gli altri.
 *
 * Le voci scoperte sono scritte a mano come le manda il server; `FuoriPreventivoFinestraTest` (D ter) prova che il server manda il segno così.
 *
 * Cosa resta scoperto: il server (il test Pest citato); il riquadro della copertura e la percentuale, che contano ancora tutte le voci scoperte.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

// La voce nata dal pulsante e rinominata; la voce del preventivo chiamata «Imprevisto - Tetto»; una voce scoperta qualunque.
const orfani = [
    { id: 11, nome: 'Riparazione colonna', importo: 122000, gestione: 'Gestione ordinaria 2026', gestione_id: 2, is_sforo: true, strategia: 'rata_integrativa', fuori_preventivo: true },
    { id: 12, nome: 'Imprevisto - Tetto', importo: 14400, gestione: 'Gestione ordinaria 2026', gestione_id: 2, is_sforo: true, strategia: 'nessuna', fuori_preventivo: false },
    { id: 13, nome: 'Spese generali', importo: 50000, gestione: 'Gestione ordinaria 2026', gestione_id: 2, is_sforo: false, strategia: 'nessuna', fuori_preventivo: false },
];

let pagina: VueWrapper | null = null;

async function monta() {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { spese: 186400, entrate: 0, da_ripartire: 186400, pianificato: 0, virtuale: 0, addebiti_personali: 0, uscite_totali: 0, delta: 186400, scoperto: 186400,
                percentuale: 0, is_completo: false, has_sforo: true, orfani, scoperto_count: 3 } as never,
            pianiDisallineati: [], inboxTasks: { total: 0, data: [] }, widgets: {},
            fattureScoperte: [{ id: 5, numero: 'FT-ROB', fornitore: 'Idraulica Srl', totale_scoperto: 122000, gestione_id: 2, strategia: 'rata_integrativa',
                righe: [{ id: 'r_1', tipo: 'comune', importo: 122000, descrizione: 'Parte comune (millesimale)', dettaglio: 'Voce: Riparazione colonna' }] }],
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
                Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
    const analizza = [...document.body.querySelectorAll('button')].find((b) => normalizza(b.textContent).startsWith('Analizza voci')) as HTMLButtonElement | undefined;
    expect(analizza, 'il pulsante «Analizza voci» non c\'è').toBeDefined();
    analizza!.click();
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

/** I nomi delle schede fra gli «Sfori del preventivo». */
function sfori(): string[] {
    const titolo = [...document.body.querySelectorAll('h4')].find((h) => normalizza(h.textContent).startsWith('Sfori del preventivo'));
    if (!titolo) return [];
    const elenco = titolo.parentElement!;

    return [...elenco.querySelectorAll('div.text-sm.font-bold')].map((d) => normalizza(d.textContent));
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('693 (f) — le voci nate da «Fuori preventivo» si riconoscono dal segno, non dal nome', () => {
    test('la voce rinominata «Riparazione colonna» non compare fra gli sfori (sta fra le fatture in sospeso), e non ha «Gestisci sforo»', async () => {
        await monta();

        expect(sfori()).not.toContain('Riparazione colonna');
        const gestisci = [...document.body.querySelectorAll('a[href*="conto_id=11"]')];
        expect(gestisci).toHaveLength(0);
    });

    test('la voce del preventivo chiamata «Imprevisto - Tetto» è uno sforo come gli altri, con «Gestisci sforo»', async () => {
        await monta();

        expect(sfori()).toEqual(['Imprevisto - Tetto', 'Spese generali']);
        expect(document.body.querySelector('a[href*="conto_id=12"]')).not.toBeNull();
    });
});

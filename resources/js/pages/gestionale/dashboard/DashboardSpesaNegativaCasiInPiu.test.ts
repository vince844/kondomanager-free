// @vitest-environment jsdom

/**
 * Il badge della scheda di una voce di spesa scritta con il meno, fra le voci non coperte del cruscotto.
 *
 * La voce non è uno sforo: è un importo scritto con il meno da una versione vecchia, che il calcolo delle quote addebita in positivo e che la creazione
 * del piano non propone. La scheda porta il badge «Importo negativo», lo stesso nome del badge del selettore della creazione, e non «Sforo Aperto»,
 * che resta per le voci scoperte di sempre.
 *
 * La pagina riceve la voce fra le voci scoperte con `spesa_negativa: true` e `nota`: qui sono scritti a mano, e `Beta53SpesaNegativaCopertureTest`
 * (T297.10-bis) e `Beta53SpesaNegativaCasiInPiuTest` (T297.22) provano che il server li manda così.
 *
 * Si monta la pagina vera, come in `DashboardSpesaNegativa.test.ts` (cornice, guida, widget e suggerimenti sostituiti); la finestra si apre con
 * «Analizza voci».
 *
 * Cosa NON copre: la nota e il collegamento a «Gestisci sforo» (`DashboardSpesaNegativa.test.ts`); il colore del badge; i badge delle strategie
 * «Fondo Riserva», «A Consuntivo» e «Rata Integrativa», che vengono prima nella catena e non cambiano; la larghezza a 375 px.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

const NOTA = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 300,00) ed è nel piano «Ordinario 2026», che la addebita in positivo: la pagina del piano dice che cosa fare.';

// Le voci scoperte come le manda il cruscotto (T297.22): la spesa negativa in un piano, scoperta per € 60,00, e una voce scoperta di sempre.
const orfani = [
    { id: 1, nome: 'Spese generali', importo: 120000, gestione: 'Gestione ordinaria', gestione_id: 2, is_sforo: false, strategia: 'nessuna', spesa_negativa: false },
    { id: 3, nome: 'Rimborso vecchio', importo: 6000, gestione: 'Gestione ordinaria', gestione_id: 2, is_sforo: false, strategia: 'nessuna', spesa_negativa: true, nota: NOTA },
];

let pagina: VueWrapper | null = null;

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

async function monta() {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { spese: 150000, entrate: 24000, da_ripartire: 126000, pianificato: 0, virtuale: 0, addebiti_personali: 0, uscite_totali: 0, delta: 126000, scoperto: 126000,
                percentuale: 0, is_completo: false, has_sforo: false, orfani, scoperto_count: 2 } as never,
            pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets: {},
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

/** I badge della scheda di una voce: i testi delle etichette accanto al nome della gestione. */
function badge(nome: string): string[] {
    const titolo = [...document.body.querySelectorAll('div.text-sm.font-bold')].find((d) => normalizza(d.textContent) === nome) as HTMLElement | undefined;
    expect(titolo, `la scheda di «${nome}» non c'è`).toBeDefined();
    const riga = titolo!.nextElementSibling as HTMLElement;

    return [...riga.querySelectorAll('span.rounded')].map((s) => normalizza(s.textContent));
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il badge della voce di spesa negativa fra le voci non coperte', () => {
    test('la scheda della spesa negativa ha il badge «Importo negativo», non «Sforo Aperto»', async () => {
        await monta();

        expect(badge('Rimborso vecchio')).toEqual(['Importo negativo']);
    });

    test('controprova — la voce scoperta di sempre ha ancora il badge «Sforo Aperto»', async () => {
        await monta();

        expect(badge('Spese generali')).toEqual(['Sforo Aperto']);
    });
});

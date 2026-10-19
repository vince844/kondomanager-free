// @vitest-environment jsdom

/**
 * La finestra delle voci non coperte del cruscotto, con una voce di spesa scritta con il meno.
 *
 * Senza piano la voce è scoperta per € 240,00: il calcolo delle quote la addebita in positivo. Ma la creazione del piano non la propone (è spenta nel
 * selettore), quindi la scheda non dice «Voce senza copertura attiva. Delibera un piano rate…» e non porta alla creazione con «Gestisci sforo»: dice
 * la nota che manda il server, cioè di correggerla nel piano dei conti.
 *
 * La pagina riceve la voce fra le voci scoperte con `spesa_negativa: true` e `nota`: qui sono scritti a mano, e `Beta53SpesaNegativaCopertureTest`
 * (T297.10-bis) prova che il server li manda così.
 *
 * La pagina è quella vera. Si stubbano la cornice, la guida, i widget e i suggerimenti a comparsa; la finestra si apre con «Analizza voci».
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (il test Pest citato); il riquadro della copertura e la percentuale (`DashboardBeta52.test.ts`); il colore e la larghezza
 * a 375 px.
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

const NOTA = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

// Le voci scoperte come le manda il cruscotto senza piano (T297.10-bis): la spesa negativa con la sua nota, le altre come sempre.
const orfani = [
    { id: 1, nome: 'Spese generali', importo: 120000, gestione: 'Gestione ordinaria', gestione_id: 2, is_sforo: false, strategia: 'nessuna', spesa_negativa: false },
    { id: 3, nome: 'Rimborso vecchio', importo: 24000, gestione: 'Gestione ordinaria', gestione_id: 2, is_sforo: false, strategia: 'nessuna', spesa_negativa: true, nota: NOTA },
];

let pagina: VueWrapper | null = null;

async function monta() {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [],
            copertura: { spese: 144000, entrate: 24000, da_ripartire: 120000, pianificato: 0, virtuale: 0, addebiti_personali: 0, uscite_totali: 0, delta: 120000, scoperto: 120000,
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

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

/** La scheda di una voce nella finestra delle voci non coperte. */
function scheda(nome: string): HTMLElement {
    const titolo = [...document.body.querySelectorAll('div.text-sm.font-bold')].find((d) => normalizza(d.textContent) === nome) as HTMLElement | undefined;
    expect(titolo, `la scheda di «${nome}» non c'è`).toBeDefined();

    return titolo!.closest('div.rounded-xl') as HTMLElement;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la voce di spesa negativa fra le voci non coperte', () => {
    test('la scheda dice la nota del server, non «Voce senza copertura attiva», e non porta alla creazione del piano', async () => {
        await monta();

        const s = scheda('Rimborso vecchio');

        expect(normalizza(s.textContent)).toContain(NOTA);
        expect(normalizza(s.textContent)).not.toContain('Voce senza copertura attiva');
        expect(normalizza(s.textContent)).not.toContain('Gestisci sforo');
        expect(s.querySelector('a[href*="piani-rate/create"]')).toBeNull();
    });

    test('controprova — la voce scoperta di sempre dice ancora «Voce senza copertura attiva» e porta alla creazione con «Gestisci sforo»', async () => {
        await monta();

        const s = scheda('Spese generali');

        expect(normalizza(s.textContent)).toContain('Voce senza copertura attiva. Delibera un piano rate per finanziare questo importo.');
        expect(normalizza(s.textContent)).toContain('Gestisci sforo');
        expect(s.querySelector('a[href*="piani-rate/create"]')).not.toBeNull();
    });
});

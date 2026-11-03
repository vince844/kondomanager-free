// @vitest-environment jsdom

/**
 * Il selettore delle voci nella creazione del piano, con una voce di spesa scritta con il meno.
 *
 * Il server manda la voce spenta, senza sforo, con `spesa_negativa: true` e la nota che dice la strada: correggerla nel piano dei conti se nessun
 * piano la tiene, altrimenti la pagina del piano che la addebita già (`Beta53SpesaNegativaTestiTest`, T297.16 e T297.16-bis: questi campi la pagina qui
 * li riceve scritti a mano, e quel test prova che il server li manda così). La voce si mostra con il badge «Importo negativo», con la nota come
 * suggerimento: prima la stessa voce diceva «Esaurito» senza piano (falso: non è stata finanziata) e «Sforo: € 240,00» con il piano che la addebita già.
 *
 * Si monta la pagina vera (con la cornice, la guida e i moduli di contorno sostituiti); il selettore è una risposta di `axios` e si apre il menu.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (i test Pest citati); la voce preselezionata dal cruscotto (una voce spenta non si preseleziona, già da prima); il
 * rifiuto sul campo (`PianiRateNewErroriCapitoli.test.ts`); il tema scuro e la larghezza a 375 px.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: (_u: string, _d: unknown, o: any) => o.onSuccess({ props: { saldoInfo: { applicabile: false } } }), post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true,
    ScopertoWarning: true,
    DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
};

const NOTA_FUORI_DAI_PIANI = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
const NOTA_NEL_PIANO = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nel piano «Ordinario 2026», che la addebita in positivo: la pagina del piano dice che cosa fare.';

// Le voci come le manda `FetchCapitoliPerGestioneController` (T297.16): la spesa negativa spenta, con il budget del calcolo (€ 240,00).
const rimborso = (note: string) => ({ id: 3, nome: '[CAPITOLO] Rimborso vecchio', tipo: 'spesa', importo_totale: 240, speso_reale: 0, residuo: 0, disabled: true, is_sforo: false,
    da_sposta_spesa: false, spesa_negativa: true, note, is_radice: true, parent_id: null });
const esaurita = { id: 1, nome: '[CAPITOLO] Spese generali', tipo: 'spesa', importo_totale: 1200, speso_reale: 0, residuo: 0, disabled: true, is_sforo: false,
    da_sposta_spesa: false, note: 'Interamente finanziato/Esaurito', is_radice: true, parent_id: null };
const disponibile = { id: 2, nome: '[CAPITOLO] Pulizia scale', tipo: 'spesa', importo_totale: 300, speso_reale: 0, residuo: 300, disabled: false, is_sforo: false,
    da_sposta_spesa: false, note: 'Disp: € 300,00', is_radice: true, parent_id: null };

let pagina: VueWrapper | null = null;

async function monta(selettore: unknown[]) {
    axios.get.mockImplementation(async (url?: string) => ({ data: url?.includes('fetch-capitoli-gestione') ? selettore : [] }));
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' }] as never,
            saldoInfo: { saldo: 0, applicabile: false, motivo: '' }, anagraficheDisponibili: [],
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
    (pagina.vm as any).form.gestione_id = 35;
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

/** Apre il menu delle voci e dà le righe del menu, come le vede chi sceglie. */
async function voci(): Promise<HTMLElement[]> {
    // Il primo menu è quello della gestione; quello delle voci è a scelta multipla.
    const menu = document.body.querySelector('.v-select.vs--multiple') as HTMLElement | null;
    expect(menu, 'il menu delle voci non c\'è').not.toBeNull();
    (menu!.querySelector('.vs__dropdown-toggle') as HTMLElement).dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    await flushPromises();

    return [...document.body.querySelectorAll('li.vs__dropdown-option')] as HTMLElement[];
}

const riga = (righe: HTMLElement[], nome: string) => righe.find((r) => normalizza(r.textContent).includes(nome)) as HTMLElement;
const badge = (r: HTMLElement, testo: string) => [...r.querySelectorAll('span')].find((s) => normalizza(s.textContent) === testo) as HTMLElement | undefined;

beforeEach(() => axios.get.mockReset());

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la voce di spesa con un importo negativo nel menu delle voci', () => {
    test('fuori da ogni piano: il badge «Importo negativo», con la nota che dice di correggerla; né «Esaurito», né «Sforo»', async () => {
        await monta([rimborso(NOTA_FUORI_DAI_PIANI), disponibile]);

        const r = riga(await voci(), 'Rimborso vecchio');

        expect(r).toBeDefined();
        expect(badge(r, 'Importo negativo'), 'il badge «Importo negativo» non c\'è').toBeDefined();
        expect(normalizza(badge(r, 'Importo negativo')!.getAttribute('title'))).toBe(NOTA_FUORI_DAI_PIANI);
        expect(normalizza(r.textContent)).not.toContain('Esaurito');
        expect(normalizza(r.textContent)).not.toContain('Sforo');
        expect(normalizza(r.textContent)).toContain('Budget: € 240,00');
    });

    test('già in un piano: lo stesso badge, e la nota nomina il piano invece di proporre la correzione', async () => {
        await monta([rimborso(NOTA_NEL_PIANO), disponibile]);

        const r = riga(await voci(), 'Rimborso vecchio');

        expect(badge(r, 'Importo negativo'), 'il badge «Importo negativo» non c\'è').toBeDefined();
        expect(normalizza(badge(r, 'Importo negativo')!.getAttribute('title'))).toBe(NOTA_NEL_PIANO);
        expect(normalizza(r.textContent)).not.toContain('Sforo');
    });

    test('controprova — la voce spenta non si sceglie: il clic non la mette fra le voci del piano', async () => {
        await monta([rimborso(NOTA_FUORI_DAI_PIANI), disponibile]);

        const r = riga(await voci(), 'Rimborso vecchio');
        r.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        r.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect((pagina!.vm as any).form.capitoli_ids).toEqual([]);
    });

    test('controprova — una voce finanziata per intero dice ancora «Esaurito», e una disponibile «Disp:», senza il badge nuovo', async () => {
        await monta([esaurita, disponibile]);

        const righe = await voci();

        expect(badge(riga(righe, 'Spese generali'), 'Esaurito')).toBeDefined();
        expect(normalizza(riga(righe, 'Pulizia scale').textContent)).toContain('Disp: € 300,00');
        expect(normalizza(document.body.textContent)).not.toContain('Importo negativo');
    });
});

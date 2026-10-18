// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il resto della schermata di creazione (le fatture, i saldi, la ricorrenza, le competenze), provato a video; il server (il
 * totale che manda al piano lo prova `Beta52TotaliBadgeTest`); il disegno a 375 px.
 *
 * Il totale delle voci scelte nella creazione del piano, dalla pagina vera.
 *
 * La pagina conta le voci scelte con il segno del tipo (le entrate si sottraggono) e lo dice nel riquadro «Ripartizione manuale voci»: «Da ripartire:
 * € 960,00 (spese € 1.200,00, entrate € 240,00)», o «Totale richiesto» se non ci sono entrate. I campi sono in euro e la conversione in centesimi
 * avviene una volta sola, in `centesimiDaImporto` (che `totaliDaImportiInEuro` usa e che `parseMoney` della pagina richiama): il totale si formatta
 * dichiarando i centesimi, e un `euro(centesimi)` senza dichiararli darebbe € 96.000,00 (il ×100 della beta.32). Il tipo di ogni voce arriva dal
 * selettore e deve passare alla riga scelta: senza, il totale non si può calcolare. Le due notazioni del campo («1.250,50» digitata, «1250.50» iniziale)
 * danno lo stesso importo ovunque nella pagina: nel totale, nel residuo e nell'avviso di superamento.
 *
 * Si montano la pagina vera (con la cornice, la guida e i moduli di contorno sostituiti) e un `MoneyInput` finto; il selettore è una risposta di `axios`.
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

const spesa = { id: 1, nome: 'Spese generali', tipo: 'spesa', importo_totale: 1200, speso_reale: 0, residuo: 1200, disabled: false, is_radice: true, parent_id: null };
const entrata = { id: 2, nome: 'Proventi antenna', tipo: 'entrata', importo_totale: 240, speso_reale: 0, residuo: 240, disabled: false, is_radice: true, parent_id: null };

let pagina: VueWrapper | null = null;

/** Monta la pagina con le voci date nel selettore e sceglie quelle indicate (`ids`); gli errori di render finiscono in `errori` invece di fermare la prova. */
async function monta(selettore: unknown[], ids: number[], errori: unknown[] = []) {
    axios.get.mockImplementation(async (url?: string) => ({ data: url?.includes('fetch-capitoli-gestione') ? selettore : [] }));
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' }] as never,
            saldoInfo: { saldo: 0, applicabile: false, motivo: '' }, anagraficheDisponibili: [],
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` }, config: { errorHandler: (errore: unknown) => { errori.push(errore); } } },
    });
    await flushPromises();
    const vm = pagina.vm as any;
    vm.form.gestione_id = 35;
    await flushPromises();
    vm.form.capitoli_ids = ids;
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ');
/** L'importo che il campo di una voce tiene, come lo scrive l'utente o come lo lascia la pagina. */
const scrivi = async (indice: number, valore: string) => {
    (pagina!.vm as any).capitoliDettaglio[indice].importo_da_usare = valore;
    await flushPromises();
};

beforeEach(() => axios.get.mockReset());

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il riquadro «Ripartizione manuale voci»', () => {
    test('una spesa di € 1.200,00 e un\'entrata di € 240,00 scelte: «Da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)», non € 96.000,00', async () => {
        await monta([spesa, entrata], [1, 2]);

        expect(testo()).toContain('Da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)');
        expect(testo()).not.toContain('96.000,00');
        expect(testo()).not.toContain('NaN');
    });

    test('controprova: la sola spesa dice «Totale richiesto: € 1.200,00», come prima', async () => {
        await monta([spesa, entrata], [1]);

        expect(testo()).toContain('Totale richiesto: € 1.200,00');
        expect(testo()).not.toContain('Da ripartire:');
    });

    test('la sola entrata dà un netto negativo: «Da ripartire: € -240,00 (spese € 0,00, entrate € 240,00)»', async () => {
        await monta([spesa, entrata], [2]);

        expect(testo()).toContain('Da ripartire: € -240,00 (spese € 0,00, entrate € 240,00)');
    });

    test('il tipo della voce passa dal selettore alla riga scelta: una voce che non lo porta non si somma a caso, la pagina lo dice invece di mostrare una cifra', async () => {
        const errori: unknown[] = [];
        const senzaTipo = { ...spesa, tipo: undefined };

        await monta([senzaTipo, entrata], [1, 2], errori);

        expect(errori.map((e) => (e as Error).message)).toContain('segnoDelTipo: la voce non porta il tipo, e il segno non si indovina');
        expect(testo()).not.toContain('Da ripartire: € 960,00');
        expect(testo()).not.toContain('Totale richiesto: € 1.200,00');
    });

    test('controprova: con il tipo su tutte le voci scelte la pagina non solleva nessun errore', async () => {
        const errori: unknown[] = [];

        await monta([spesa, entrata], [1, 2], errori);

        expect(errori).toEqual([]);
    });
});

describe('le due notazioni del campo danno lo stesso importo ovunque nella pagina', () => {
    test('«1.250,50» digitata e «1250.50» iniziale: stesso totale, stesso residuo, e l\'avviso di superamento solo sopra il residuo', async () => {
        await monta([{ ...spesa, importo_totale: 2000, residuo: 2000 }], [1]);

        await scrivi(0, '1.250,50');
        expect(testo()).toContain('Totale richiesto: € 1.250,50');
        expect(testo()).toContain('Residuo: € 749,50');
        expect(testo()).not.toContain('supera il residuo disponibile');

        await scrivi(0, '1250.50');
        expect(testo()).toContain('Totale richiesto: € 1.250,50');
        expect(testo()).toContain('Residuo: € 749,50');

        await scrivi(0, '2.000,01');
        expect(testo()).toContain('supera il residuo disponibile');
        expect(testo()).toContain('Residuo: € -0,01');
    });

    test('un campo vuoto o che non è un numero vale zero, senza «NaN»', async () => {
        await monta([spesa], [1]);

        await scrivi(0, '');
        expect(testo()).toContain('Totale richiesto: € 0,00');
        await scrivi(0, 'abc');
        expect(testo()).toContain('Totale richiesto: € 0,00');
        expect(testo()).not.toContain('NaN');
    });
});

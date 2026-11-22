// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 736: «Da portare in assemblea» nella pagina «Rate scadute».
 *
 * - In alto, accanto a «Dispense dei sei mesi»: la finestra del memo con i sei mesi dell'esercizio che il server indica (la data più vicina, o quella del
 *   filtro «I sei mesi del …»), cioè il punto della dispensa, senza nomi.
 * - Nel menu «⋯» della riga: la stessa finestra con i sei mesi della data più vicina di quella persona (l'esercizio della sua rata con quella data). Il
 *   punto resta senza nomi: «Situazione di …» con il nome andrebbe nell'avviso di convocazione, che stampa il testo del punto.
 * - Chi non gestisce le assemblee non vede né l'uno né l'altro; senza un esercizio dei sei mesi il pulsante in alto non c'è, e la voce della riga non c'è
 *   per una persona senza la data dei sei mesi.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

let gestisce = true;
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}`, hasPermission: () => gestisce }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RateScadute.vue')).default;

// Due rate: la più vicina dei sei mesi è il 30/06/2026, dell'esercizio 25; l'altra il 30/06/2027, dell'esercizio 26.
const sei = (data: string, esercizio: number) => ({ dalla_scadenza: { data, esercizio: null, dispensa: null }, dalla_competenza: { data, esercizio: null, dispensa: null },
    coincidono: true, piu_vicina: data, piu_vicina_dispensa: null, piu_vicina_esercizio: { id: esercizio, nome: `Esercizio ${esercizio === 25 ? 2025 : 2026}` } });
const rata = (id: number, data: string, esercizio: number) => ({ rata_id: id, numero_rata: id, piano: 'Preventivo 2026', unita: 'Interno 1', scadenza: '2026-03-05',
    residuo: 5000, giorni_ritardo: 43, pregresso: null, pregresso_nella_rata: false, sei_mesi: sei(data, esercizio) });
const riga = { anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 0, fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 },
    ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2026-06-30', dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false,
    rate: [rata(2, '2027-06-30', 26), rata(1, '2026-06-30', 25)], accordi: [], ultima_lettera: null, lettera_in_sospeso: null };
const proprieta = (altro: Record<string, unknown> = {}, r: Record<string, unknown> = riga) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [r], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } },
    totale: 15000, persone_totali: 1,
    filtri: { alla: '2026-05-20', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false },
    oggi: '2026-05-20', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»', frase_festivo: '', lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0,
    dispensa_in_assemblea: { esercizio_id: 25, data: '2026-06-30' }, ...altro,
});

const FinestraDelMemo = { name: 'FinestraDelMemo', props: ['open', 'condominioId', 'origineTipo', 'origineId', 'testo'], template: '<div data-testid="finestra-memo" />' };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div data-testid="intestazione"><slot name="actions" /></div>' },
    LettereDialog: true, AccordoRientroDialog: true, RateScaduteGuide: true, FinestraDelMemo,
};
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; gestisce = true; document.body.innerHTML = ''; });
const monta = (altro: Record<string, unknown> = {}, r: Record<string, unknown> = riga) => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(altro, r) as any, global: { stubs } });

    return w;
};
const apriMenu = async () => {
    // Come da tastiera: in jsdom il clic del mouse non apre il menu di reka-ui, Invio sì.
    await w!.get('[data-testid="menu-7"]').trigger('keydown', { key: 'Enter' });
    await flushPromises();
};
const voci = () => Array.from(document.body.querySelectorAll('[role="menuitem"]')).map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim());
const finestre = () => w!.findAllComponents(FinestraDelMemo);

describe('«Da portare in assemblea» nelle rate scadute', () => {
    test('in alto, accanto a «Dispense dei sei mesi»: il memo della dispensa con i sei mesi dell\'esercizio indicato dal server', async () => {
        monta();
        const intestazione = w!.get('[data-testid="intestazione"]');
        expect(intestazione.text()).toContain('Dispense dei sei mesi');
        const pulsante = intestazione.get('[data-testid="da-portare-in-assemblea"]');
        expect(pulsante.text()).toBe('Da portare in assemblea');
        const memo = finestre().find((f) => f.props('origineTipo') === 'sei_mesi' && f.props('origineId') === 25)!;
        expect(memo.props('condominioId')).toBe(3);
        await pulsante.trigger('click');
        expect(memo.props('open')).toBe(true);
    });

    test('nel menu «⋯» della riga: il memo della dispensa con i sei mesi più vicini della persona, senza il nome; senza la data dei sei mesi la voce non c\'è', async () => {
        monta({ dispensa_in_assemblea: { esercizio_id: 26, data: '2027-06-30' } });
        await apriMenu();
        expect(voci()).toEqual(['Prepara una lettera', 'Registra un accordo di rientro', 'Da portare in assemblea']);
        (document.body.querySelector('[data-testid="memo-7"]') as HTMLElement).click();
        await flushPromises();
        const memo = finestre().find((f) => f.props('origineTipo') === 'sei_mesi' && f.props('origineId') === 25)!;
        expect(memo.props('open')).toBe(true);
        expect(finestre().some((f) => f.props('origineTipo') === 'anagrafica')).toBe(false);
        w!.unmount();
        document.body.innerHTML = '';
        monta({}, { ...riga, sei_mesi: { data: null, dispensa: null, mancano: 2 }, rate: [] });
        await apriMenu();
        expect(voci()).not.toContain('Da portare in assemblea');
    });

    test('chi non gestisce le assemblee non vede né il pulsante né la voce; senza l\'esercizio dei sei mesi il pulsante in alto non c\'è', async () => {
        gestisce = false;
        monta();
        expect(w!.find('[data-testid="da-portare-in-assemblea"]').exists()).toBe(false);
        await apriMenu();
        expect(voci()).not.toContain('Da portare in assemblea');
        w!.unmount();
        document.body.innerHTML = '';
        gestisce = true;
        monta({ dispensa_in_assemblea: null });
        expect(w!.find('[data-testid="intestazione"] [data-testid="da-portare-in-assemblea"]').exists()).toBe(false);
        // La voce della persona non dipende dai sei mesi: c'è lo stesso.
        await apriMenu();
        expect(voci()).toContain('Da portare in assemblea');
    });
});

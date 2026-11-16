// @vitest-environment jsdom

/**
 * La pagina «Rate scadute» con le regole del recupero: i suggerimenti (chi è oltre la tolleranza senza una lettera, gli interessi da proporre) con la loro
 * porta, che sceglie le persone o apre la proposta per loro e non scrive niente; «Proponi gli interessi» una volta sola, nel suggerimento; «Spese del
 * ritardo» per le scelte, o per tutte quelle dei filtri. I pulsanti della fila stanno su una riga: le dispense dei sei mesi sono un collegamento
 * nell'intestazione.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RateScadute.vue')).default;

const persona = (id: number, nome: string) => ({
    anagrafica_id: id, nome, unita: ['U1'], importo: 30000, credito: 0, fasce: { '1_30': 0, '31_90': 0, oltre_90: 30000 }, ritardo_testo: 'in ritardo di 90 giorni',
    sei_mesi: { data: null, dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false, ultima_lettera: null, lettera_in_sospeso: null, accordi: [], rate: [],
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [persona(1, 'Neri'), persona(2, 'Bianchi')], meta: { current_page: 1, last_page: 1, per_page: 20, total: 2, from: 1, to: 2 } },
    totale: 60000, persone_totali: 2, oggi: '2026-06-29', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»', frase_festivo: '',
    lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0, regole_del_recupero: '1,60 % annuo, tasso legale',
    filtri: { alla: '2026-06-29', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false },
    suggerimenti: [
        { chiave: 'oltre_tolleranza', frase: '1 persona oltre i 15 giorni di tolleranza senza una lettera.', persone: [1], porta: 'seleziona' },
        { chiave: 'interessi_da_proporre', frase: 'Interessi di mora da proporre per 2 persone.', persone: [1, 2], porta: 'proponi_interessi' },
    ],
    ...extra,
});
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true, AccordoRientroDialog: true,
    ProponiInteressiDialog: { props: ['open', 'persone'], template: '<div data-testid="finestra-interessi" :data-aperta="String(open)" :data-persone="JSON.stringify(persone)" />' },
    AddebitaSpesaDialog: { props: ['open', 'persone'], template: '<div data-testid="finestra-spesa" :data-aperta="String(open)" :data-persone="JSON.stringify(persone)" />' } };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); document.body.innerHTML = ''; });
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(extra) as any, global: { stubs } });

    return w;
};

describe('le regole del recupero nell\'elenco', () => {
    test('i suggerimenti con la loro porta: «Scegli queste persone» sceglie, «Proponi gli interessi» apre la proposta per loro; niente si scrive', async () => {
        const pagina = monta();
        expect(pagina.get('[data-testid="suggerimento-oltre_tolleranza"]').text()).toContain('1 persona oltre i 15 giorni di tolleranza senza una lettera.');
        expect(pagina.get('[data-testid="suggerimenti"]').text()).toContain('tasso di oggi: 1,60 % annuo, tasso legale');

        await pagina.get('[data-testid="suggerimento-oltre_tolleranza"] button').trigger('click');
        expect(pagina.get('[data-testid="selezionate"]').text()).toBe('Selezionate: 1.');

        await pagina.get('[data-testid="suggerimento-interessi_da_proporre"] button').trigger('click');
        const finestra = pagina.get('[data-testid="finestra-interessi"]');
        expect(finestra.attributes('data-aperta')).toBe('true');
        expect(JSON.parse(finestra.attributes('data-persone')!)).toEqual([1, 2]);
        expect(axiosPost).not.toHaveBeenCalled();
    });

    test('«Proponi gli interessi» c\'è una volta sola, nel suggerimento; «Spese del ritardo»: le scelte, o tutte quelle dei filtri; senza suggerimenti niente riquadro', async () => {
        const conSuggerimenti = monta();
        expect(conSuggerimenti.findAll('button').filter((b) => b.text() === 'Proponi gli interessi')).toHaveLength(1);
        expect(conSuggerimenti.find('[data-testid="proponi-interessi"]').exists()).toBe(false);
        conSuggerimenti.unmount();

        const pagina = monta({ suggerimenti: [] });
        expect(pagina.find('[data-testid="suggerimenti"]').exists()).toBe(false);
        expect(pagina.findAll('button').filter((b) => b.text().startsWith('Proponi gli interessi'))).toHaveLength(0);
        const spese = pagina.get('[data-testid="spese-del-ritardo"]');
        expect(spese.text()).toBe('Spese del ritardo');
        await spese.trigger('click');
        expect(pagina.get('[data-testid="finestra-spesa"]').attributes('data-aperta')).toBe('true');
        expect(pagina.get('[data-testid="finestra-spesa"]').attributes('data-persone')).toBe('null');
    });

    test('le dispense dei sei mesi sono un collegamento nell\'intestazione, non un pulsante della fila', () => {
        w = mount(Pagina, { attachTo: document.body, props: proprieta() as any,
            global: { stubs: { ...stubs, PageHeaderGuide: { template: '<div data-testid="intestazione"><slot name="actions" /></div>' } } } });
        const collegamento = w.get('[data-testid="intestazione"] [data-testid="dispense-sei-mesi"]');
        expect(collegamento.text()).toBe('Dispense dei sei mesi');
        expect(collegamento.attributes('href')).toBe('/gestionale.dispense.index');
        expect(w.get('[data-testid="prepara-lettere"]').element.parentElement!.querySelector('[data-testid="dispense-sei-mesi"]')).toBeNull();
    });
});

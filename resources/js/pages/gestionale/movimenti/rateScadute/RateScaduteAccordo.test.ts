// @vitest-environment jsdom

/**
 * La pagina «Rate scadute» con l'accordo di rientro: il segno con le due cifre sulla riga, la parte in accordo sulla rata aperta, il filtro
 * «Con accordo di rientro» nell'indirizzo, e la finestra dell'accordo che si apre dalla riga. Le cifre sono quelle di Giulia al 04/11/2026:
 * € 1.744,10 scaduti, € 994,10 in accordo dei € 1.500,00 riconosciuti il 26/06.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const routerGet = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...a: unknown[]) => routerGet(...a), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).flatMap(([k, v]) => (v === undefined ? [] : [[k, String(v)]]))).toString()}`;

const Pagina = (await import('./RateScadute.vue')).default;

const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 99410, invariato: false, fino_al_passato: false, versati_oltre: 0,
    autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null };
const rata = (n: number, residuo: number, parte: Record<string, unknown> | null) => ({ rata_id: n, immobile_id: 1, numero_rata: n, piano: 'Preventivo 2026', unita: 'Interno 7',
    scadenza: '2026-01-31', residuo, giorni_ritardo: 277, pregresso: null, pregresso_nella_rata: false, sei_mesi: null, accordo: parte });
const giulia = {
    anagrafica_id: 1, nome: 'Giulia', unita: ['Interno 7'], importo: 174410, credito: 0, fasce: { '1_30': 75000, '31_90': 0, oltre_90: 99410 }, ritardo_testo: 'in ritardo di 277 giorni',
    sei_mesi: { data: null, dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false, ultima_lettera: null, lettera_in_sospeso: null,
    accordi: [accordo],
    rate: [rata(1, 24410, { riconosciuto: 75000, in_accordo: 24410, data_accordo: '2026-06-26', fuori_dalle_lettere: true }),
        rata(2, 75000, { riconosciuto: 75000, in_accordo: 75000, data_accordo: '2026-06-26', fuori_dalle_lettere: true }), rata(4, 75000, null)],
};
const filtri = (extra: Record<string, unknown> = {}) => ({
    alla: '2026-11-04', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false,
    ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false, ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [giulia], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } },
    totale: 174410, persone_totali: 1, filtri: filtri(), oggi: '2026-11-04', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»',
    frase_festivo: '', lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0, ...extra,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true,
    AccordoRientroDialog: { props: ['open', 'persona'], template: '<div data-testid="finestra-accordo" :data-aperta="String(open)">{{ persona?.nome }}</div>' } };
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(extra) as any, global: { stubs } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; routerGet.mockReset(); document.body.innerHTML = ''; vi.useRealTimers(); });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('l\'accordo di rientro nell\'elenco', () => {
    test('la riga ha il segno con le due cifre; la rata aperta la sua parte in accordo; la rata fuori dall\'accordo niente', async () => {
        monta();
        expect(pulito(w!.get('[data-testid="segno-accordo"]').text()))
            .toBe('Accordo di rientro del 26/06/2026, fino al 15/12/2026: riconosciuti € 1.500,00, oggi € 994,10.');
        await w!.get('button[aria-label="Apri le rate"]').trigger('click');
        const parti = w!.findAll('[data-testid="parte-in-accordo"]').map((p) => pulito(p.text()));
        expect(parti).toEqual([
            'Nell\'accordo del 26/06/2026: € 244,10 (riconosciuti € 750,00); non nelle lettere proposte.',
            'Nell\'accordo del 26/06/2026: € 750,00 (riconosciuti € 750,00); non nelle lettere proposte.',
        ]);
        // Le rate in accordo restano fra le scadute, con il loro residuo: lo scaduto della riga è lordo.
        expect(pulito(w!.text())).toContain('€ 1.744,10');
    });

    test('il filtro «Con accordo di rientro» va nell\'indirizzo', async () => {
        vi.useFakeTimers();
        monta();
        await w!.get('[data-testid="filtro-con-accordo"] button').trigger('click');
        vi.advanceTimersByTime(400);
        expect(String(routerGet.mock.calls.at(-1)?.[0])).toContain('con_accordo=1');
    });

    test('la finestra dell\'accordo si apre dalla riga, per quella persona', async () => {
        monta();
        expect(w!.get('[data-testid="finestra-accordo"]').attributes('data-aperta')).toBe('false');
        await w!.get('[data-testid="accordo-1"]').trigger('click');
        expect(w!.get('[data-testid="finestra-accordo"]').attributes('data-aperta')).toBe('true');
        expect(w!.get('[data-testid="finestra-accordo"]').text()).toBe('Giulia');
    });
});

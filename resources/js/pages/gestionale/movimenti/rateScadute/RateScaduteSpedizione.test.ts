// @vitest-environment jsdom

/**
 * La pagina «Rate scadute» dopo le lettere: il filtro «Stampate, spedizione da registrare» e la spedizione in blocco delle persone scelte, con il
 * numero di ognuna, dalla stessa rotta del registro; «Rifalle con le cifre di oggi» che dice quante restano; il singolare con una persona sola
 * e con una lettera sola.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const routerGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
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

const stampata = (invio: number) => ({ tipo: 'diffida', data: '2026-03-20', canale: 'stampa', stato: 'stampata', destinatari: [], invii: [invio] });
const riga = (id: number, nome: string, extra: Record<string, unknown> = {}) => ({
    anagrafica_id: id, nome, unita: ['Interno 1'], importo: 15000, credito: 0,
    fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 }, ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2027-06-30', dispensa: null, mancano: 0 },
    con_pregresso: false, origine_non_dichiarata: false, rate: [], ultima_lettera: stampata(100 + id), lettera_in_sospeso: null,
    ...extra,
});
const filtri = (extra: Record<string, unknown> = {}) => ({
    alla: '2026-03-21', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false,
    ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga(1, 'Ugo'), riga(2, 'Bice')], meta: { current_page: 1, last_page: 1, per_page: 20, total: 2, from: 1, to: 2 } },
    totale: 30000, persone_totali: 2, filtri: filtri(), oggi: '2026-03-21', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»',
    frase_festivo: '', lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0,
    ...extra,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true };
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(extra) as any, global: { stubs } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; routerGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; vi.useRealTimers(); });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('la spedizione in blocco dall\'elenco', () => {
    test('il filtro «Stampate, spedizione da registrare» va nell\'indirizzo', async () => {
        vi.useFakeTimers();
        monta();
        await w!.get('[data-testid="filtro-da-spedire"] button').trigger('click');
        vi.advanceTimersByTime(400);
        expect(String(routerGet.mock.calls.at(-1)?.[0])).toContain('da_spedire=1');
    });

    test('con il filtro: «Registra la spedizione» delle persone scelte, con lo stesso canale e la stessa data, e il numero di ognuna', async () => {
        monta({ filtri: filtri({ da_spedire: true }) });
        await w!.findAll('tbody input[type="checkbox"], tbody button[role="checkbox"]')[0].trigger('click');
        await w!.get('[data-testid="spedizione-in-blocco"]').trigger('click');
        const pannello = w!.get('[data-testid="pannello-spedizione"]');
        expect(pulito(pannello.text())).toContain('Ugo');
        expect(pulito(pannello.text())).not.toContain('Bice');
        await pannello.get('select').setValue('raccomandata');
        await pannello.get('[data-testid="numero-101"]').setValue('RA1');
        axiosPost.mockResolvedValueOnce({ data: { spedite: 1 } });
        await pannello.get('[data-testid="registra-spedizione-blocco"]').trigger('click');
        await flushPromises();
        const [indirizzo, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
        expect(indirizzo).toContain('gestionale.lettere.spedizione');
        expect(corpo).toMatchObject({ invii: [101], canale: 'raccomandata', data: '2026-03-21', numeri: { 101: 'RA1' } });
        expect(pulito(w!.text())).toContain('Spedizione registrata per 1 lettera.');
    });

    test('senza il filtro il pulsante non c\'è', () => {
        monta();
        expect(w!.find('[data-testid="spedizione-in-blocco"]').exists()).toBe(false);
    });
});

describe('le parole con una persona o una lettera', () => {
    test('una persona sola: «Prepara la lettera», senza «tutte le 1»; una lettera da rifare: «Rifalla»', () => {
        monta({ righe: { data: [riga(1, 'Ugo')], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } }, persone_totali: 1, lettere_da_riprovare: 1 });
        const t = pulito(w!.text());
        expect(t).not.toContain('tutte le 1');
        expect(t).toContain('Prepara la lettera');
        expect(t).toContain('Rifalla con le cifre di oggi');
    });

    test('«Rifalle» dice quante restano quando ne partono al massimo cinquanta per volta', async () => {
        monta({ lettere_da_riprovare: 60 });
        axiosPost.mockResolvedValueOnce({ data: { persone: 50, messaggi: 50, lettere: 50, inviate: 0, in_coda: 50, non_partite: 0, errori: [], saldate: 0, promemoria_scaduti: 0,
            limite_altro_indirizzo: 0, tetto_messaggi: 50, restanti: { lettere: 10, messaggi: 10 } } });
        await w!.get('[data-testid="lettere-da-riprovare"] button').trigger('click');
        await flushPromises();
        expect(pulito(w!.text())).toContain('50 lettere rifatte con le cifre di oggi. Ne restano 10 da rifare: ne partono al massimo 50 messaggi per volta, premi di nuovo per rifarle.');
    });
});

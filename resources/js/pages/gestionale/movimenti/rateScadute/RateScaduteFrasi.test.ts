// @vitest-environment jsdom

/**
 * Le frasi della pagina «Rate scadute» che dicono quello che il programma sa e che decide l'amministratore: a chi si mostra l'elenco (non più
 * stretto della legge), «Mai scritto» con che cosa conta, la regola del «più sei mesi» accanto a quella del festivo, «dichiarala dai saldi» che
 * apre i saldi della persona, il piano straordinario senza la data della delibera con dove si scrive, le rate non emesse e i saldi.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Pagina = (await import('./RateScadute.vue')).default;
const { FRASE_RISERVATEZZA, testoData, testoNonEmesse } = await import('@/lib/gestionale/debiti/rateScadute');
const { FRASE_CONSERVATO, testoRiprova } = await import('@/lib/gestionale/lettere/lettere');

const riga = (extra: Record<string, unknown> = {}) => ({
    anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 0, fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 },
    ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2027-06-30', dispensa: null, mancano: 0 }, con_pregresso: true, origine_non_dichiarata: true, rate: [], ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } }, totale: 15000, persone_totali: 1,
    filtri: { alla: '2027-01-17', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false, ordine: 'scaduto' },
    oggi: '2027-01-17', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«Salvo che…»',
    frase_festivo: 'Se la data cade in un giorno festivo il programma non la sposta.',
    frase_sei_mesi: 'Più sei mesi: lo stesso giorno del sesto mese, o l\'ultimo di quel mese se il giorno manca (31/08 → 28/02 o 29/02). È una scelta prudente del programma: la data più presto fra i modi di contare.',
    ...extra,
});

let pagina: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    pagina = mount(Pagina, { props: proprieta(extra) as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } } });

    return pagina;
};
afterEach(() => { pagina?.unmount(); pagina = null; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le frasi della pagina', () => {
    test('a chi si mostra l\'elenco: all\'assemblea e a chi ha diritto di conoscerlo; non in bacheca', () => {
        expect(FRASE_RISERVATEZZA).toBe('Contiene dati personali: si mostra all\'assemblea e a chi ha diritto di conoscerlo, come il condòmino o il creditore non ancora pagato '
            + 'che lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.');
        expect(pulito(monta().text())).toContain(FRASE_RISERVATEZZA);
    });

    test('«Mai scritto» dice che cosa conta, e la regola del «più sei mesi» sta accanto a quella del festivo', () => {
        const t = pulito(monta().text());
        expect(t).toContain('Mai scritto (contano le lettere partite o spedite)');
        expect(t).toContain('Più sei mesi: lo stesso giorno del sesto mese, o l\'ultimo di quel mese se il giorno manca');
    });

    test('«dichiarala dai saldi» apre i saldi con la persona', () => {
        const link = monta().findAll('a').find((a) => a.text() === 'dichiarala dai saldi');
        expect(link?.attributes('href')).toBe('/gestionale.saldi.index?condominio=3&origine_di=7');
    });

    test('le frasi delle date, delle rate non emesse e delle lettere', () => {
        expect(testoData({ data: null, esercizio: null, stato: 'senza_delibera' } as any))
            .toBe('Il piano straordinario non ha la data della delibera: la data dalla competenza non si calcola. Scrivila nel piano rate.');
        expect(testoNonEmesse({ persone: 2, importo: 60000, saldi: 5000 })).toBe('2 persone hanno € 600,00 su rate con la scadenza passata e non ancora emesse (il pregresso '
            + 'compreso), e € 50,00 di saldi non ancora in un piano approvato: non sono in questo elenco. Le rate si emettono dal piano rate; i saldi si chiedono mettendoli in un piano.');
        expect(testoNonEmesse({ persone: 2, importo: 60000, saldi: 0 })).toBe('2 persone hanno € 600,00 su rate con la scadenza passata e non ancora emesse (il pregresso '
            + 'compreso): non sono in questo elenco. Si emettono dal piano rate.');
        expect(FRASE_CONSERVATO).toBe('Del sollecito, della diffida e della messa in mora il programma conserva il PDF come è stato emesso, con la sua impronta.');
        expect(testoRiprova({ messaggi: 1, lettere: 1, saldate: 1, promemoria_scaduti: 1 }))
            .toBe('1 lettera rifatta con le cifre di oggi; 1 promemoria non serve più: le rate sono scadute. Per quella persona prepara un sollecito dall\'elenco.');
    });
});

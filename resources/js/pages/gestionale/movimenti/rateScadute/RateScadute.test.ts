// @vitest-environment jsdom

/**
 * La pagina «Rate scadute», montata con le proprietà come le manda `RateScaduteController`: il totale dice «senza togliere il credito» e la riga
 * sotto il titolo spiega la Tesoreria; la vista «In scadenza nei prossimi N giorni»; la riga delle rate non emesse; a una data passata la
 * colonna del credito non c'è e la pagina dice perché; la cella dell'esercizio che manca porta all'elenco degli esercizi, non a «crea»; la
 * stampa porta i filtri, o le persone spuntate.
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
    `/${nome}?${new URLSearchParams(Object.entries(parametri).flatMap(([k, v]) => (Array.isArray(v) ? v.map((x) => [`${k}[]`, String(x)]) : v === undefined ? [] : [[k, String(v)]]))).toString()}`;

const Pagina = (await import('./RateScadute.vue')).default;

const sei = (data: string | null, stato = 'ok', extra: Record<string, unknown> = {}) => ({
    data, esercizio: data ? { id: 1, nome: 'Esercizio 2026', data_fine: '2026-12-31' } : null, stato, doppio: false, dedotto: false, prima_della_scadenza: false,
    data_cercata: null, esercizio_da_chiudere: null, dispensa: null, ...extra,
});
const riga = (extra: Record<string, unknown> = {}) => ({
    anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 2000,
    fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 }, ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2027-06-30', dispensa: null, mancano: 1 },
    con_pregresso: false, origine_non_dichiarata: false,
    rate: [
        { rata_id: 1, numero_rata: 1, piano: 'Preventivo 2026', unita: 'Interno 1', scadenza: '2026-12-05', residuo: 10000, giorni_ritardo: 43, pregresso: null, pregresso_nella_rata: false,
            sei_mesi: { dalla_scadenza: sei('2027-06-30'), dalla_competenza: sei('2027-06-30'), coincidono: false, piu_vicina: '2027-06-30', piu_vicina_dispensa: null } },
        { rata_id: 2, numero_rata: 2, piano: 'Preventivo 2026', unita: 'Interno 1', scadenza: '2027-01-05', residuo: 5000, giorni_ritardo: 12, pregresso: null, pregresso_nella_rata: false,
            sei_mesi: { dalla_scadenza: sei(null, 'manca_esercizio', { data_cercata: '2027-01-05', esercizio_da_chiudere: 'Esercizio 2026' }), dalla_competenza: sei('2027-06-30'), coincidono: false, piu_vicina: '2027-06-30', piu_vicina_dispensa: null } },
    ],
    ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } },
    totale: 15000, persone_totali: 1,
    filtri: { alla: '2027-01-17', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false, ordine: 'scaduto' },
    oggi: '2027-01-17', non_emesse: { persone: 2, importo: 60000, saldi: 5000 }, gestioni: [], persona_della_porta: null, testo_norma: '«Salvo che…»',
    frase_festivo: 'Se la data cade in un giorno festivo il programma non la sposta: è una scelta prudente, la data più presto. Che la legge la sposti al giorno dopo non è accertato.',
    ...extra,
});

let pagina: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    pagina?.unmount();
    pagina = mount(Pagina, {
        props: proprieta(extra) as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    return pagina;
};
afterEach(() => { pagina?.unmount(); pagina = null; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('il totale e la Tesoreria', () => {
    test('il totale dice «senza togliere il credito», e la riga sotto il titolo spiega perché la Tesoreria dà una cifra più bassa', () => {
        const w = monta();
        expect(pulito(w.get('[data-testid="totale"]').text())).toBe('1 persona con questi filtri: scaduto € 150,00, senza togliere il credito.');
        expect(w.get('[data-testid="riga-tesoreria"]').text()).toContain('Tesoreria');
        expect(w.get('[data-testid="riga-tesoreria"]').text()).toContain('cifra più bassa');
    });

    test('la vista dei promemoria si chiama «In scadenza nei prossimi N giorni»', () => {
        const w = monta({ filtri: { ...proprieta().filtri, vista: 'in_scadenza', giorni: 15 }, non_emesse: null,
            righe: { data: [riga({ prima_scadenza: '2027-01-20', fasce: undefined, sei_mesi: undefined })], meta: proprieta().righe.meta } });
        expect(w.get('[data-testid="vista-in-scadenza"]').text()).toBe('In scadenza nei prossimi 15 giorni');
        expect(pulito(w.get('[data-testid="totale"]').text())).toContain('in scadenza € 150,00, senza togliere il credito');
        expect(w.find('[data-testid="riga-non-emesse"]').exists()).toBe(false);
    });
});

describe('le righe che dicono che cosa manca', () => {
    test('le rate non emesse e i saldi non in un piano, con il collegamento ai piani rate', () => {
        const w = monta();
        const r = w.get('[data-testid="riga-non-emesse"]');
        expect(pulito(r.text())).toContain('2 persone hanno € 600,00 su rate con la scadenza passata e non ancora emesse (il pregresso compreso), e € 50,00 di saldi non ancora in un piano approvato');
        expect(r.find('a').attributes('href')).toContain('gestionale.esercizi.piani-rate.index');
    });

    test('a una data passata la colonna del credito non c\'è, e la pagina dice perché', () => {
        const oggi = monta();
        expect(oggi.text()).toContain('Credito');
        expect(oggi.text()).toContain('€ 20,00');
        const passata = monta({ filtri: { ...proprieta().filtri, alla: '2026-12-31' }, righe: { data: [riga({ credito: null })], meta: proprieta().righe.meta } });
        expect(passata.find('[data-testid="frase-data-passata"]').exists()).toBe(true);
        expect(passata.findAll('th').map((t) => t.text())).not.toContain('Credito');
    });

    test('aperta la riga, l\'esercizio che manca porta all\'elenco degli esercizi, non a «crea»', async () => {
        const w = monta();
        await w.get('button[aria-label="Apri le rate"]').trigger('click');
        expect(w.text()).toContain('L\'esercizio che contiene il 05/01/2027 non c\'è ancora: la data si calcola quando chiudi l\'esercizio 2026 e apri il successivo.');
        const link = w.get('[data-testid="collegamento-esercizi"]');
        expect(link.attributes('href')).toContain('gestionale.esercizi.index');
        expect(link.attributes('href')).not.toContain('create');
    });
});

describe('la stampa', () => {
    test('senza scelta porta «tutte» con i filtri; con le righe spuntate porta le persone', async () => {
        const aperto: string[] = [];
        const open = vi.spyOn(window, 'open').mockImplementation(((url: string) => { aperto.push(url); return null; }) as any);
        const w = monta();
        await w.get('[data-testid="stampa"]').trigger('click');
        expect(aperto[0]).toContain('gestionale.rate-scadute.stampa');
        expect(aperto[0]).toContain('tutte=1');
        expect(aperto[0]).toContain('vista=scadute');
        open.mockRestore();
    });
});

describe('i filtri non si ricordano', () => {
    test('la pagina non dice mai «Sono i filtri che avevi scelto l\'ultima volta», neanche se il server lo mandasse', () => {
        const w = monta({ filtri_ricordati: true, filtri: { ...proprieta().filtri, fascia: '31_90' } });
        expect(w.find('[data-testid="filtri-ricordati"]').exists()).toBe(false);
        expect(w.text()).not.toContain('l\'ultima volta');
        expect(w.text()).not.toContain('Togli i filtri');
    });
});

describe('il giorno festivo', () => {
    test('le due date dicono la frase del server sul festivo, e non una regola («il programma mostra la più presto»)', () => {
        const w = monta();
        expect(pulito(w.text())).toContain('Se la data cade in un giorno festivo il programma non la sposta: è una scelta prudente, la data più presto. Che la legge la sposti al giorno dopo non è accertato.');
        expect(w.text()).not.toContain('Il giorno festivo non sposta la data');
    });
});

// @vitest-environment jsdom

/**
 * I filtri della pagina «Rate scadute»: ogni filtro ha la sua spiegazione accanto al nome; la persona si sceglie da un elenco con la ricerca,
 * che con un condominio grande ne mostra al massimo 50 e dice quante altre ce ne sono; la persona scelta, la fascia, la gestione e l'ordine
 * vanno nell'indirizzo come prima; una persona arrivata dall'indirizzo (l'estratto conto) è già scelta.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { PERSONE_NEL_MENU, SPIEGAZIONI_DEI_FILTRI, personeDelMenu } from '@/lib/gestionale/debiti/rateScadute';

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

const persone = (n: number) => Array.from({ length: n }, (_, i) => ({ id: i + 1, nome: `Persona ${String(i + 1).padStart(4, '0')}` }));
const proprieta = (extra: Record<string, unknown> = {}, filtri: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } },
    totale: 0, persone_totali: 0,
    filtri: { alla: '2026-03-20', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false, ...filtri },
    oggi: '2026-03-20', non_emesse: null, gestioni: [{ id: 11, nome: 'Ordinaria 2026' }, { id: 12, nome: 'Facciata' }],
    persone: [{ id: 7, nome: 'Ugo Bianchi' }, { id: 8, nome: 'Bice Rossi' }], persona_della_porta: null, testo_norma: '«…»',
    ...extra,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true };
const monta = (extra: Record<string, unknown> = {}, filtri: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(extra, filtri) as any, global: { stubs } });

    return w;
};
const ultimoIndirizzo = () => routerGet.mock.calls.at(-1)![0] as string;
/** Apre il menu di un `v-select` dentro il filtro e sceglie l'opzione con quel testo. */
async function scegli(p: VueWrapper, filtro: string, testo: string) {
    await p.get(`[data-testid="${filtro}"] .vs__search`).trigger('focus');
    await flushPromises();
    const opzione = p.findAll(`[data-testid="${filtro}"] .vs__dropdown-option`).find((o) => o.text().includes(testo));
    expect(opzione, `«${testo}» fra le scelte di ${filtro}`).toBeTruthy();
    await opzione!.trigger('click');
}

afterEach(() => {
    w?.unmount();
    w = null;
    routerGet.mockReset();
    vi.useRealTimers();
});

describe('la ricerca delle persone', () => {
    test('trova il testo nel nome senza badare alle maiuscole, e ne dà al massimo 50 con il conto delle altre', () => {
        const tutte = persone(130);
        expect(personeDelMenu(tutte, '').mostrate).toHaveLength(PERSONE_NEL_MENU);
        expect(personeDelMenu(tutte, '').altre).toBe(80);
        // «Persona 0100» … «Persona 0130»: 31 nomi, sotto il tetto; gli spazi intorno al testo non contano.
        expect(personeDelMenu(tutte, ' persona 01').mostrate.map((p) => p.id)).toEqual(Array.from({ length: 31 }, (_, i) => 100 + i));
        expect(personeDelMenu(tutte, ' persona 01').altre).toBe(0);
        const una = personeDelMenu(tutte, 'PERSONA 0042');
        expect(una.mostrate.map((p) => p.id)).toEqual([42]);
        expect(una.altre).toBe(0);
        expect(personeDelMenu([{ id: 1, nome: 'Niccolò Àlvaro' }], 'àlva').mostrate).toHaveLength(1);
        expect(personeDelMenu(tutte, 'nessuno').mostrate).toEqual([]);
    });
});

describe('i filtri', () => {
    test('ogni filtro ha la sua spiegazione accanto al nome', () => {
        const p = monta();
        const spiegazioni = p.findAll('[data-testid="spiegazione-filtro"]').map((s) => s.attributes('aria-label'));
        expect(spiegazioni).toHaveLength(Object.keys(SPIEGAZIONI_DEI_FILTRI).length);
        expect(new Set(spiegazioni)).toEqual(new Set(Object.values(SPIEGAZIONI_DEI_FILTRI)));
    });

    test('nella vista «in scadenza» restano le spiegazioni dei filtri che ci sono', () => {
        const p = monta({}, { vista: 'in_scadenza' });
        const spiegazioni = p.findAll('[data-testid="spiegazione-filtro"]').map((s) => s.attributes('aria-label'));
        expect(spiegazioni).not.toContain(SPIEGAZIONI_DEI_FILTRI.fascia);
        expect(spiegazioni).not.toContain(SPIEGAZIONI_DEI_FILTRI.sei_mesi_entro);
        expect(spiegazioni).toContain(SPIEGAZIONI_DEI_FILTRI.persona);
    });

    test('la persona scelta dall\'elenco va nell\'indirizzo, e il vecchio «cerca» no', async () => {
        vi.useFakeTimers();
        const p = monta();
        await scegli(p, 'filtro-persona', 'Bice Rossi');
        vi.advanceTimersByTime(400);
        expect(ultimoIndirizzo()).toContain('anagrafica_id=8');
        expect(ultimoIndirizzo()).not.toContain('cerca=');
    });

    test('la fascia, la gestione e l\'ordine vanno nell\'indirizzo come prima', async () => {
        vi.useFakeTimers();
        const p = monta();
        await scegli(p, 'filtro-fascia', 'oltre 90 giorni');
        await scegli(p, 'filtro-gestione', 'Facciata');
        await scegli(p, 'filtro-ordine', 'Per data dei sei mesi');
        vi.advanceTimersByTime(400);
        const indirizzo = ultimoIndirizzo();
        expect(indirizzo).toContain('fascia=oltre_90');
        expect(indirizzo).toContain('gestione_id=12');
        expect(indirizzo).toContain('ordine=sei_mesi');
    });

    test('la persona arrivata dall\'estratto conto è già scelta, e si toglie con la ×', async () => {
        vi.useFakeTimers();
        const p = monta({ persona_della_porta: 'Ugo Bianchi' }, { anagrafica_id: 7 });
        expect(p.get('[data-testid="filtro-persona"] .vs__selected').text()).toBe('Ugo Bianchi');
        await p.get('[data-testid="filtro-persona"] .vs__clear').trigger('click');
        vi.advanceTimersByTime(400);
        expect(ultimoIndirizzo()).not.toContain('anagrafica_id=');
    });

    test('con un condominio grande il menu mostra 50 persone e dice quante altre ce ne sono', async () => {
        const p = monta({ persone: persone(130) });
        await p.get('[data-testid="filtro-persona"] .vs__search').trigger('focus');
        await flushPromises();
        expect(p.findAll('[data-testid="filtro-persona"] .vs__dropdown-option')).toHaveLength(PERSONE_NEL_MENU);
        expect(p.get('[data-testid="persone-altre"]').text()).toContain('le altre 80');
        await p.get('[data-testid="filtro-persona"] .vs__search').setValue('0129');
        await flushPromises();
        const trovate = p.findAll('[data-testid="filtro-persona"] .vs__dropdown-option');
        expect(trovate.map((o) => o.text())).toEqual(['Persona 0129']);
        expect(p.find('[data-testid="persone-altre"]').exists()).toBe(false);
    });


    test('la scelta delle righe sta in testa alla fila dei pulsanti, prima delle azioni, e si toglie con un pulsante accanto', async () => {
        const p = monta({ persone_totali: 2, totale: 149000 });
        const fila = () => p.findAll('[data-testid="seleziona-tutte"], [data-testid="togli-scelta"], [data-testid="prepara-lettere"], [data-testid="stampa"], [data-testid="dispense-sei-mesi"]')
            .map((b) => b.attributes('data-testid'));
        expect(fila()).toEqual(['seleziona-tutte', 'prepara-lettere', 'stampa', 'dispense-sei-mesi']);
        expect(p.get('[data-testid="seleziona-tutte"]').text()).toBe('Seleziona tutte le 2 dei filtri');
        await p.get('[data-testid="seleziona-tutte"]').trigger('click');
        expect(p.get('[data-testid="selezionate"]').text()).toBe('Selezionate: 2.');
        expect(fila()).toEqual(['togli-scelta', 'prepara-lettere', 'stampa', 'dispense-sei-mesi']);
        await p.get('[data-testid="togli-scelta"]').trigger('click');
        expect(p.find('[data-testid="selezionate"]').exists()).toBe(false);
        expect(fila()[0]).toBe('seleziona-tutte');
    });

    test('un indirizzo vecchio con «cerca»: la pagina lo dice con la ×, e la stampa e le lettere lo portano, come l\'elenco', async () => {
        vi.useFakeTimers();
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const p = monta({ persone_totali: 2, totale: 149000 }, { cerca: 'Ros' });
        expect(p.get('[data-testid="filtro-cerca"]').text()).toContain('Solo i nomi con «Ros».');
        await p.get('[data-testid="stampa"]').trigger('click');
        expect(apri.mock.calls.at(-1)![0]).toContain('cerca=Ros');
        expect(p.findComponent({ name: 'LettereDialog' }).props('filtri')).toMatchObject({ cerca: 'Ros' });
        // Un altro filtro non lo toglie.
        await p.get('[data-testid="filtro-senza-lettere"]').setValue('30');
        vi.advanceTimersByTime(400);
        expect(ultimoIndirizzo()).toContain('cerca=Ros');
        // La × sì.
        await p.get('[data-testid="togli-cerca"]').trigger('click');
        expect(ultimoIndirizzo()).not.toContain('cerca=');
        expect(p.find('[data-testid="filtro-cerca"]').exists()).toBe(false);
        apri.mockRestore();
    });

    test('senza «cerca» nell\'indirizzo la riga non c\'è e la stampa non lo porta', async () => {
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const p = monta({ persone_totali: 2, totale: 149000 });
        expect(p.find('[data-testid="filtro-cerca"]').exists()).toBe(false);
        await p.get('[data-testid="stampa"]').trigger('click');
        expect(apri.mock.calls.at(-1)![0]).not.toContain('cerca=');
        apri.mockRestore();
    });

    test('il pulsante «Guida» dell\'intestazione apre la guida di lato, con il testo della norma', async () => {
        const p = monta();
        const intestazione = p.findComponent({ name: 'PageHeaderGuide' });
        expect(intestazione.props('hasTextGuide')).toBe(true);
        expect(intestazione.props('textGuideTitle')).toBe('Guida');
        const guida = () => p.findComponent({ name: 'RateScaduteGuide' });
        expect(guida().props('open')).toBe(false);
        intestazione.vm.$emit('open-text-guide');
        await flushPromises();
        expect(guida().props('open')).toBe(true);
        expect(guida().props('testoNorma')).toBe('«…»');
    });
});


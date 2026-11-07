// @vitest-environment jsdom

/**
 * La finestra delle lettere, dopo l'anteprima: le persone tolte non risultano «entrate dopo» (la finestra manda anche tutte quelle
 * dell'anteprima); dopo un'azione riuscita i pulsanti si spengono, e un secondo clic non rimanda le lettere; la gestione dell'elenco vale anche
 * per la lista spuntata, e l'anteprima la nomina; l'avviso del tetto conta chi si stampa e dice come fare il resto; un'anteprima arrivata dopo
 * un cambio del tipo non resta a video; chi non salva i modelli sa perché.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;
const { FRASE_MODELLI_SENZA_PERMESSO } = await import('@/lib/gestionale/lettere/modelli');

const opzioni = (extra: Record<string, unknown> = {}) => ({
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false },
        { tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true },
        { tipo: 'diffida', nome: 'Diffida', solo_carta: true, conservato: true },
        { tipo: 'messa_in_mora', nome: 'Messa in mora', solo_carta: true, conservato: true },
    ],
    testi: { promemoria: 'Testo del promemoria', sollecito: 'Testo del sollecito, [nome]', diffida: '', messa_in_mora: '' },
    conti: [{ id: 4, nome: 'Banca', iban: 'IT60X0542811101000000123456', intestatario: 'Condominio' }],
    scelta_ricordata: { termini: { sollecito: { forma: 'giorni', giorni: 15 } }, cassa_id: 4, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: true,
    ...extra,
});
const persona = (id: number, nome: string, canale = 'email') => ({
    id, nome, totale: 8000, righe: 2, credito: 0, canale, canali: ['email', 'stampa', 'zip'], indirizzi: [{ tipo: 'email', indirizzo: `${nome.toLowerCase()}@x.it` }],
    senza_email: false, solo_pec: false, senza_indirizzo_postale: false,
});
const anteprima = (extra: Record<string, unknown> = {}) => ({
    tipo: 'sollecito', alla: '2026-03-20', gestione: null, conteggi: { email: 2, stampa: 0, zip: 0 }, messaggi: 2, credito: [], senza_niente: [],
    studio_compilato: true, senza_server_posta: false, tetto_messaggi: null, tetto: 200, oltre_tetto: false,
    persone: [persona(2, 'Bice'), persona(1, 'Ugo')],
    ...extra,
});
const riepilogo = { persone: 2, messaggi: 2, inviate: 0, in_coda: 2, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null, aggiunte: 0, uscite: 0 };

let finestra: VueWrapper | null = null;
async function apri(props: Record<string, unknown> = {}, risposta = opzioni()) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: { fascia: '31_90' }, ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
async function conAnteprima(dati = anteprima(), props: Record<string, unknown> = {}) {
    await apri(props);
    axiosPost.mockResolvedValueOnce({ data: dati });
    (pulsante('anteprima') as HTMLButtonElement).click();
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const ultimaChiamata = () => axiosPost.mock.calls.at(-1) as [string, Record<string, any>];

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('il confine', () => {
    test('una persona tolta: le azioni portano le persone rimaste e, a parte, tutte quelle dell\'anteprima', async () => {
        await conAnteprima();
        (document.body.querySelector('[aria-label="Togli Ugo"]') as HTMLElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { ...riepilogo, persone: 1, messaggi: 1 } });
        (pulsante('invia') as HTMLButtonElement).click();
        await flushPromises();
        const [, corpo] = ultimaChiamata();
        expect(corpo.viste).toEqual([2]);
        expect(corpo.in_anteprima).toEqual([2, 1]);
    });

    test('con la lista spuntata la finestra manda la gestione dei filtri, e l\'anteprima dice che le altre gestioni non ci sono', async () => {
        await conAnteprima(anteprima({ gestione: { id: 7, nome: 'Ordinaria 2026' } }), { selezione: { tutte: false, ids: [1, 2] }, filtri: { gestione_id: 7, fascia: '31_90' } });
        const [, corpo] = axiosPost.mock.calls[0] as [string, Record<string, any>];
        expect(corpo).toMatchObject({ persone: [1, 2], gestione_id: 7 });
        expect(testo()).toContain('Solo la gestione «Ordinaria 2026»: le rate delle altre gestioni non sono nelle lettere.');
    });
});

describe('dopo un\'azione', () => {
    test('dopo un invio riuscito i pulsanti si spengono, la finestra dice di rifare l\'anteprima, e un secondo clic non rimanda niente', async () => {
        await conAnteprima();
        axiosPost.mockResolvedValueOnce({ data: riepilogo });
        (pulsante('invia') as HTMLButtonElement).click();
        await flushPromises();
        const invia = pulsante('invia') as HTMLButtonElement | null;
        invia?.click();
        await flushPromises();
        expect(axiosPost.mock.calls.filter((c) => String(c[0]).includes('lettere.invia'))).toHaveLength(1);
        expect(invia === null || invia.disabled).toBe(true);
        expect((pulsante('stampa') as HTMLButtonElement | null)?.disabled ?? true).toBe(true);
        expect(testo()).toContain('Fatto. Per un altro invio rifai l\'anteprima.');
    });

    test('un\'anteprima arrivata dopo un cambio del tipo non resta a video', async () => {
        await apri();
        let rispondi: (v: unknown) => void = () => {};
        axiosPost.mockReturnValueOnce(new Promise((r) => { rispondi = r; }));
        (pulsante('anteprima') as HTMLButtonElement).click();
        (document.body.querySelector('input[value="diffida"]') as HTMLInputElement).click();
        await flushPromises();
        rispondi({ data: anteprima() });
        await flushPromises();
        expect(pulsante('anteprima-persone')).toBeNull();
        expect(pulsante('prova')).toBeNull();
    });
});

describe('il tetto del PDF', () => {
    const tante = (n: number, canale: string) => Array.from({ length: n }, (_, i) => persona(i + 1, `Persona ${i + 1}`, canale));

    test('conta chi si stampa, non chi va per email', async () => {
        await conAnteprima(anteprima({ persone: [...tante(240, 'email'), ...tante(10, 'stampa').map((p) => ({ ...p, id: p.id + 1000 }))], oltre_tetto: true }));
        expect(testo()).not.toContain('in un PDF');
    });

    test('oltre il tetto dice come stampare il resto', async () => {
        await conAnteprima(anteprima({ persone: tante(201, 'stampa'), oltre_tetto: true }));
        expect(testo()).toContain('Le lettere da stampare sono 201: in un PDF ne stanno al massimo 200.');
        expect(testo()).toContain('poi passa alla pagina dopo: stampare o scaricare le lettere non cambia l\'ordine dell\'elenco.');
        // Non manda al filtro «Nessuna lettera negli ultimi 1 giorni», che riproporrebbe chi è appena stato stampato; dice da dove ripartire dopo la spedizione.
        expect(testo()).not.toContain('negli ultimi 1 giorni');
        expect(testo()).toContain('le altre persone ricominciano dalla pagina 1.');
    });
});

describe('i modelli', () => {
    test('a chi non può salvarlo la finestra dice chi lo salva', async () => {
        await apri({}, opzioni({ puo_salvare_il_testo: false }));
        expect(pulsante('salva-modello')).toBeNull();
        expect(testo()).toContain(FRASE_MODELLI_SENZA_PERMESSO);
    });
});

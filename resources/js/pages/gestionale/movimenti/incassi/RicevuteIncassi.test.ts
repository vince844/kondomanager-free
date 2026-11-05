// @vitest-environment jsdom

/**
 * La pagina delle ricevute (1.12.0-beta.2): la finestra del blocco, quella che l'amministratore legge prima di mandare o stampare a molti. Si
 * monta la pagina vera con il layout finto e le risposte del server scritte a mano come le manda `RicevuteIncassiController` e
 * `EmissioneRicevute::anteprima()`.
 *
 * - L'anteprima dice chi riceve che cosa, e una persona si toglie dall'invio: i conteggi cambiano e l'invio manda solo le altre.
 * - La nota parte vuota ogni volta che si apre la finestra, anche se era stata scritta prima.
 * - Più ricevute del tetto di un PDF si stampano una parte per volta; «Anteprima» apre il PDF senza emettere.
 * - Dopo «Invia le altre» resta l'elenco di chi era senza indirizzo nel primo invio.
 * - «Invia di nuovo» e «Riprova» chiedono una conferma che dice a chi va e con quale bollo.
 * - L'ordine scelto per la stampa vale per tutte le parti del PDF; «Non serve comunicarla» toglie l'avviso; il contrassegno su una ricevuta per
 *   email ha la sua frase; un 429 si legge in italiano.
 * - Il bollo ha la stessa forma della finestra di una ricevuta, una volta per le ricevute sopra € 77,47 e una per le altre: quattro pulsanti, uno
 *   solo acceso per gruppo, che scrivono gli stessi valori per il server; gli estremi (facoltativi) una volta sola, perché sono uno per tutte.
 * - Sul telefono le righe sono schede (le classi che lo dicono), e nessun colore fisso chiaro resta senza la sua variante scura.
 * - La stampa e l'anteprima in blocco aprono la scheda nuova nel clic, prima della richiesta del PDF; il PDF arrivato va nella scheda, o si
 *   scarica se la scheda non c'è; un errore chiude la scheda e si legge nella finestra del blocco; la stampa ricarica la pagina a PDF arrivato,
 *   l'anteprima no; un secondo clic non chiede un secondo PDF. La frase del server dopo un rimando (il messaggio flash) sta in testa alla pagina.
 *
 * Che cosa NON copre: il server (nei test PHP della ricevuta) e l'aspetto vero (si guarda nel browser, in chiaro, in scuro e a 400 px).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: vi.fn(), post: (...a: unknown[]) => axiosPost(...a) } }));
// Le proprietà condivise della pagina (il messaggio flash): un test le cambia prima di montare.
const condivise = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, router: { get: vi.fn(), reload: vi.fn() }, usePage: () => condivise }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
const percorsi: Array<{ nome: string; parametri: Record<string, unknown> }> = [];
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) => {
    percorsi.push({ nome, parametri });

    return `/${nome}`;
};

const Pagina = (await import('./RicevuteIncassi.vue')).default;
const { FRASE_BOLLO, NOTA_CONTRASSEGNO_EMAIL, NOTA_INTERESSI, NOTA_MODO_UNICO, RIGA_ORIGINALE } = await import('@/lib/gestionale/incassi/ricevute');

const riga = (id: number, extra: Record<string, unknown> = {}) => ({
    id, numero: `INC-2026-0000${id}`, data: '2026-10-05', importo: 15000, sopra_soglia: true, ha_email: true,
    posizione: { id: 1, nome: 'Anna Bruni' }, versato_da: null, ultima: null, ultima_email_id: null, ultima_email: null, ...extra,
});

/** Gli elementi di una zona con un colore fisso chiaro (sfondo bianco o pastello) e nessuna variante scura per lo sfondo: restano chiari nel tema scuro. */
function senzaVarianteScura(zona: ParentNode = document.body): string[] {
    const chiari = /^(bg-white|bg-slate-50|bg-rose-50|bg-amber-50|bg-emerald-50|bg-sky-50)$/;

    return [...zona.querySelectorAll('[class]')]
        .map((e) => (e.getAttribute('class') ?? '').split(/\s+/))
        .filter((classi) => classi.some((c) => chiari.test(c)) && !classi.some((c) => c.startsWith('dark:bg-')))
        .map((classi) => classi.join(' '));
}

function proprieta(extra: Record<string, unknown> = {}) {
    return {
        condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: {}, esercizi: [],
        incassi: { data: [riga(1), riga(2)], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 } },
        totale: 2, filters: { data_da: '2026-10-01', data_a: '2026-11-05', gestione_id: null, cassa_id: null, persone: [], email: null, soglia: null, ricevuta: 'mai' },
        persone: [], gestioni: [], casse: [],
        scelta_ricordata: { bollo_sopra: 'assolta', bollo_sotto: 'non_dovuta', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: false, indirizzi: null },
        tetto: 200, senza_server_posta: false, in_coda_da_tempo: 0, non_partite: 0, annullate_da_comunicare: [], registrati_di_recente: null,
        ...extra,
    };
}

const persona = (id: number, nome: string, tipo = 'email', numero = 'INC-2026-00001') => ({
    id, nome, indirizzi: [{ tipo, indirizzo: `${nome.split(' ')[0].toLowerCase()}@esempio.it` }], ricevute: [{ numero, per_conto_di: null }], messaggi: 1,
});

function anteprima(extra: Record<string, unknown> = {}) {
    return {
        senza_server_posta: false, ricevute: 3, annullati: 0, senza_versamento: 0, piu_persone: 0, sopra_soglia: 3, sotto_soglia: 0,
        email: { persone: 3, ricevute: 3, indirizzi: 3, messaggi: 3, messaggi_adesso: 3 }, tetto_messaggi: null,
        persone: [persona(1, 'Anna Bruni', 'email', 'INC-1'), persona(2, 'Carlo Bruni', 'email', 'INC-2'), persona(3, 'Dina Rossi', 'email', 'INC-3')],
        con_causale: 0, causali: [], parti_pdf: [], senza_indirizzo: { ricevute: 0, incassi: [], persone: [] }, solo_pec: 0,
        altri_indirizzi: { email: 0, email_secondaria: 0, pec: 0 }, gia_inviate: { ricevute: 0, incassi: [] }, copie: 0, piu_vecchie: 0,
        senza_indirizzo_postale: [], chi_ha_versato: 0, oltre_tetto: false, tetto: 200,
        ...extra,
    };
}

let pagina: VueWrapper | null = null;

async function monta(props: Record<string, unknown> = {}) {
    pagina?.unmount();
    document.body.innerHTML = '';
    pagina = mount(Pagina, {
        attachTo: document.body,
        props: proprieta(props) as never,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, MultiSelect: true } },
    });
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
// I pulsanti della finestra aperta, se c'è; altrimenti quelli della pagina (la pagina e la finestra hanno «Invia…» tutte e due).
const pulsanti = () => [...(document.body.querySelector('[role="dialog"]') ?? document.body).querySelectorAll('button')] as HTMLButtonElement[];
const pulsante = (nome: string) => pulsanti().find((b) => (b.textContent ?? '').trim() === nome || (b.textContent ?? '').includes(nome) && nome.length > 6);
const clic = async (nome: string) => {
    const b = pulsante(nome);
    if (!b) throw new Error(`Il pulsante «${nome}» non c'è.`);
    b.click();
    await flushPromises();
};

/** Seleziona tutte le ricevute dei filtri, poi apre la finestra del blocco con l'anteprima data. */
async function apriIlBlocco(modo: 'invio' | 'stampa' | 'persone', risposta: Record<string, unknown>) {
    axiosPost.mockResolvedValueOnce({ data: risposta });
    await clic('Seleziona tutte');
    await clic(modo === 'invio' ? 'Invia per email le selezionate' : modo === 'stampa' ? 'Stampa le selezionate' : 'Scarica un PDF per persona');
}

// Che cosa succede e in che ordine quando si chiede un PDF: la scheda che si apre, la richiesta che parte, il file scaricato.
let fatti: string[] = [];
let scheda: { closed: boolean; opener: unknown; location: { href: string }; document: Document; close: () => void } | null = null;
let bloccata = false;
const pdf = { data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), headers: { 'content-disposition': 'inline; filename="ricevute-condominio-via-dei-pini-2026-11-05.pdf"' } };

beforeEach(() => {
    axiosPost.mockReset();
    percorsi.length = 0;
    condivise.props = {};
    fatti = [];
    scheda = null;
    bloccata = false;
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:ricevute');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
    vi.spyOn(window, 'open').mockImplementation(() => {
        fatti.push('scheda');
        if (bloccata) return null;
        scheda = { closed: false, opener: window, location: { href: '' }, document: document.implementation.createHTMLDocument(''), close() { fatti.push('scheda chiusa'); this.closed = true; } };

        return scheda as unknown as Window;
    });
});

afterEach(() => {
    // Il controllo che libera l'indirizzo del PDF si ferma da sé quando la scheda si chiude.
    if (scheda) scheda.closed = true;
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('chi riceve che cosa', () => {
    test('l\'anteprima elenca le persone con gli indirizzi, le ricevute e i messaggi; una persona tolta cambia i conteggi, e l\'invio manda solo le altre', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());

        expect(testo()).toMatch(/Partiranno: 3 persone, 3 ricevute, a 3 indirizzi/);
        await clic('Mostra chi riceve che cosa');
        expect(testo()).toContain('Anna Bruni — Primaria: anna@esempio.it');
        expect(testo()).toContain('n. INC-2');

        // Si toglie Carlo Bruni (la seconda casella dell'elenco, dopo la prima di Anna).
        const caselle = [...document.body.querySelectorAll('ul button[role="checkbox"]')] as HTMLButtonElement[];
        expect(caselle).toHaveLength(3);
        caselle[1].click();
        await flushPromises();
        expect(testo()).toMatch(/Partiranno: 2 persone, 2 ricevute, a 2 indirizzi/);
        expect(testo()).toContain('1 persona tolta');

        axiosPost.mockResolvedValueOnce({ data: { persone: 2, ricevute: 2, messaggi: 2, inviate: 0, in_coda: 2, non_partite: 0, senza_indirizzo: 0, senza_indirizzo_incassi: [], senza_indirizzo_persone: [], restanti: null, errori: [] } });
        await clic('Invia');
        const invio = axiosPost.mock.calls.at(-1)!;
        expect(invio[0]).toContain('gestionale.ricevute.invia');
        expect((invio[1] as { solo_persone: number[] }).solo_persone).toEqual([1, 3]);
    });

    test('senza persone tolte l\'invio non manda «solo_persone»: il server manda a tutte', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        axiosPost.mockResolvedValueOnce({ data: { persone: 3, ricevute: 3, messaggi: 3, inviate: 0, in_coda: 3, non_partite: 0, senza_indirizzo: 0, senza_indirizzo_incassi: [], senza_indirizzo_persone: [], restanti: null, errori: [] } });

        await clic('Invia');

        expect((axiosPost.mock.calls.at(-1)![1] as { solo_persone?: number[] }).solo_persone).toBeUndefined();
    });

    test('«Mandane una a me» usa le persone rimaste', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        await clic('Mostra chi riceve che cosa');
        ([...document.body.querySelectorAll('ul button[role="checkbox"]')][0] as HTMLButtonElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { indirizzo: 'io@studio.it', persona: 'Carlo Bruni', ricevute: 1 } });

        await clic('Mandane una a me');

        expect((axiosPost.mock.calls.at(-1)![1] as { solo_persone: number[] }).solo_persone).toEqual([2, 3]);
        expect(testo()).toContain('Mandato a io@studio.it il messaggio di Carlo Bruni');
    });
});

describe('la nota e il bollo', () => {
    test('la nota parte vuota ogni volta che si apre la finestra, anche dopo averla scritta; il bollo ricordato resta', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        const nota = () => document.body.querySelector('#nota-blocco') as HTMLTextAreaElement;
        expect(nota().value).toBe('');
        expect(testo()).toContain(NOTA_INTERESSI);

        nota().value = 'Contanti consegnati a mano dal figlio';
        nota().dispatchEvent(new Event('input'));
        await flushPromises();
        await clic('Chiudi');

        axiosPost.mockResolvedValueOnce({ data: anteprima() });
        await clic('Invia per email le selezionate');
        expect(nota().value).toBe('');
    });

    test('sul bollo la finestra in blocco dice la frase generica una volta sola, e per le ricevute sopra € 77,47 solo il dato, senza giudizio', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());

        expect(testo()).toContain(FRASE_BOLLO);
        expect(testo().split(FRASE_BOLLO).length - 1).toBe(1);
        const gruppo = document.body.querySelector('[data-testid="scelta-bollo"]') as HTMLElement;
        const [titolo, dato] = [...gruppo.firstElementChild!.children];
        expect(titolo.textContent).toBe('Imposta di bollo');
        expect(dato.textContent).toBe('Ricevute sopra € 77,47 (3)');
        expect(gruppo.querySelector('[role="radiogroup"]')!.getAttribute('aria-label')).toBe('Imposta di bollo, ricevute sopra € 77,47 (3)');
        expect(testo()).not.toMatch(/di norma|scontano il bollo|€\s?2,00|da confermare|è una lettura|solo denaro versato|non lo assolve\. Lo stato/i);
    });
});

describe('più ricevute del tetto di un PDF', () => {
    test('si stampano una parte per volta, e l\'anteprima del PDF non emette', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima({ ricevute: 205, oltre_tetto: true, parti_pdf: [[1, 2, 3], [4, 5]], sopra_soglia: 205 }));
        axiosPost.mockResolvedValue(pdf);

        expect(testo()).toContain('queste si stampano in 2 parti, una per volta');
        expect(pulsante('Stampa le prime 3')).toBeDefined();
        await clic('Stampa le prime 3');
        expect(pulsante('Stampa l\'ultima')).toBeDefined();
        await clic('Stampa l\'ultima');
        expect(pulsante('Tutte stampate')?.disabled).toBe(true);

        const pdfChiesti = axiosPost.mock.calls.slice(1);
        expect(pdfChiesti.map(([url]) => url)).toEqual(['/gestionale.ricevute.stampa', '/gestionale.ricevute.stampa']);
        expect(pdfChiesti[0][1].incassi).toEqual([1, 2, 3]);
        expect(pdfChiesti[1][1].incassi).toEqual([4, 5]);

        await clic('Anteprima');
        expect(axiosPost.mock.calls.at(-1)![0]).toBe('/gestionale.ricevute.pdf-anteprima');
    });

    test('la prima emissione è l\'originale: lo dice la finestra quando almeno una ricevuta non è ancora uscita', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima({ ricevute: 3, copie: 3 }));
        expect(testo()).not.toContain(RIGA_ORIGINALE);
        pagina!.unmount();
        document.body.innerHTML = '';
        await monta();
        await apriIlBlocco('stampa', anteprima({ ricevute: 3, copie: 1 }));
        expect(testo()).toContain(RIGA_ORIGINALE);
    });
});

describe('dopo «Invia le altre»', () => {
    test('resta l\'elenco di chi era senza indirizzo nel primo invio', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        axiosPost.mockResolvedValueOnce({ data: {
            persone: 1, ricevute: 1, messaggi: 1, inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 1, senza_indirizzo_incassi: [9], senza_indirizzo_persone: ['Ugo Neri'],
            restanti: { persone: [2], incassi: [2], ricevute: 1, messaggi: 1 }, tetto_messaggi: 50, errori: [],
        } });
        await clic('Invia');
        expect(testo()).toContain('Ugo Neri: senza indirizzo.');

        axiosPost.mockResolvedValueOnce({ data: {
            persone: 1, ricevute: 1, messaggi: 1, inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 0, senza_indirizzo_incassi: [], senza_indirizzo_persone: [],
            restanti: null, tetto_messaggi: 50, errori: [],
        } });
        await clic('Invia le altre');

        expect(testo()).toContain('Ugo Neri: senza indirizzo.');
        expect(pulsante('Stampa le 1 senza indirizzo')).toBeDefined();
    });
});

describe('le conferme prima di mandare di nuovo', () => {
    const email = (extra: Record<string, unknown> = {}) => ({
        id: 10, canale: 'email', stato: 'inviata', quando: '2026-11-04 10:00', destinatario: 'vecchio@esempio.it', errore: null,
        bollo: 'assolta', modo_bollo: 'virtuale', manda_a: 'nuovo@esempio.it', ...extra,
    });

    test('«Invia di nuovo» dice l\'indirizzo di oggi e il bollo dell\'ultima email, e manda solo dopo il sì', async () => {
        // L'ultima emissione è una stampa con un altro bollo: la conferma non la legge, e il pulsante rimanda l'email.
        await monta({ incassi: { data: [riga(1, {
            ultima: { id: 11, canale: 'stampa', stato: 'stampata', quando: '2026-11-05 09:00', destinatario: null, errore: null, bollo: 'da_assolvere', modo_bollo: null },
            ultima_email_id: 10,
            ultima_email: email(),
        })], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } } });

        await clic('invia di nuovo');
        expect(testo()).toContain('Mando di nuovo la ricevuta a nuovo@esempio.it, con bollo assolto in modo virtuale.');
        expect(testo()).not.toContain('vecchio@esempio.it, con');
        expect(testo()).not.toContain('con bollo da assolvere');
        expect(axiosPost).not.toHaveBeenCalled();

        axiosPost.mockResolvedValueOnce({ data: { inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 0 } });
        await clic('Manda');
        expect(axiosPost.mock.calls.at(-1)![0]).toContain('gestionale.ricevute.invia-di-nuovo');
        expect(percorsi.at(-1)).toEqual({ nome: 'gestionale.ricevute.invia-di-nuovo', parametri: { condominio: 3, invio: 10 } });
    });

    test('se la persona non ha più quell\'indirizzo non c\'è «invia di nuovo»: la conferma non avrebbe un indirizzo da dire', async () => {
        await monta({ incassi: { data: [riga(1, { ultima: email(), ultima_email_id: 10, ultima_email: email({ manda_a: null }) })],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } } });

        expect(pulsante('invia di nuovo')).toBeUndefined();
    });

    test('«Riprova le non partite» dice quanti invii, con lo stesso bollo, e che vanno agli indirizzi di oggi', async () => {
        await monta({ non_partite: 3 });

        await clic('Riprova le non partite');

        expect(testo()).toContain('Rimando 3 invii non partiti, con lo stesso stato del bollo di prima.');
        expect(testo()).toContain('indirizzi che le persone hanno oggi');
        expect(axiosPost).not.toHaveBeenCalled();
    });

    test('dopo «Riprova» dice quanti invii a indirizzi scritti a mano il limite ha fermato; un 429 si legge in italiano', async () => {
        await monta({ non_partite: 12 });
        await clic('Riprova le non partite');
        axiosPost.mockResolvedValueOnce({ data: { inviate: 0, in_coda: 10, non_partite: 0, senza_indirizzo: 0, limite_altro_indirizzo: 2, restanti: null, tetto_messaggi: null } });
        await clic('Manda');
        expect(testo()).toContain('2 invii a indirizzi scritti a mano sono rimasti fermi per il limite di dieci al minuto');

        await monta({ incassi: { data: [riga(1, { ultima_email: email(), ultima_email_id: 10 })], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } } });
        await clic('invia di nuovo');
        axiosPost.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        await clic('Manda');
        expect(testo()).toContain('aspetta un momento e riprova');
        expect(testo()).not.toContain('Too Many Attempts');
    });
});

describe('gli avvisi della pagina', () => {
    test('le mai emesse di incassi registrati da poco con un versamento più vecchio si possono includere', async () => {
        await monta({ registrati_di_recente: { numero: 2, dal: '2026-08-10' } });

        expect(testo()).toContain('2 ricevute mai emesse sono di incassi registrati da poco con un versamento più vecchio del periodo, il primo del 10/08/2026.');
        expect(pulsante('Includile')).toBeDefined();
    });

    test('le annullate dopo l\'uscita della ricevuta non prescrivono: se e come dirlo lo decide l\'amministratore', async () => {
        await monta({ annullate_da_comunicare: [{ id: 4, numero: 'INC-2026-00004', data: '2026-09-01' }] });

        expect(testo()).toContain('Se e come dirlo alla persona lo decidi tu.');
        expect(testo()).not.toContain('da comunicare');
        expect(pulsante('Stampa o invia')).toBeDefined();
    });

    test('«Non serve comunicarla» chiede una conferma con il numero, poi scrive la scelta dell\'amministratore per quella ricevuta e ricarica l\'avviso', async () => {
        const { router } = await import('@inertiajs/vue3');
        await monta({ annullate_da_comunicare: [{ id: 4, numero: 'INC-2026-00004', data: '2026-09-01' }, { id: 5, numero: 'INC-2026-00005', data: '2026-09-02' }] });
        axiosPost.mockResolvedValueOnce({ data: { segnata: true } });

        await clic('Non serve comunicarla');
        // Prima la conferma: il numero dell'incasso, e che la segnatura resta. Niente è ancora scritto.
        expect(testo()).toContain('L\'incasso n. INC-2026-00004 è annullato: segno che alla persona non serve comunicarlo.');
        expect(testo()).toContain('non si annulla');
        expect(axiosPost).not.toHaveBeenCalled();
        await clic('Segna');

        expect(axiosPost.mock.calls.at(-1)![0]).toContain('gestionale.ricevute.non-serve-comunicarla');
        expect(percorsi.at(-1)).toEqual({ nome: 'gestionale.ricevute.non-serve-comunicarla', parametri: { condominio: 3, scrittura: 4 } });
        expect(testo()).toContain('Segnata: alla persona non serve comunicare l\'annullamento.');
        expect(router.reload).toHaveBeenCalled();
    });
});

describe('l\'ordine della stampa vale per tutte le parti del PDF', () => {
    const conParti = (parti: number[][]) => anteprima({ ricevute: 205, oltre_tetto: true, parti_pdf: parti, sopra_soglia: 205 });
    const selectOrdine = () => document.body.querySelector('[role="dialog"] select') as HTMLSelectElement;

    test('cambiare l\'ordine rifà l\'anteprima con l\'ordine scelto, e si riparte dalla prima parte', async () => {
        await monta();
        await apriIlBlocco('stampa', conParti([[1, 2, 3], [4, 5]]));
        expect(axiosPost.mock.calls.at(-1)![1].ordine).toBe('persona');
        expect(testo()).toContain('L\'ordine vale per tutte le parti, dalla prima all\'ultima.');

        axiosPost.mockResolvedValueOnce({ data: conParti([[4, 5], [1, 2, 3]]) });
        selectOrdine().value = 'unita';
        selectOrdine().dispatchEvent(new Event('change'));
        await flushPromises();

        expect(axiosPost.mock.calls.at(-1)![1].ordine).toBe('unita');
        expect(pulsante('Stampa le prime 2')).toBeDefined();
    });

    test('dopo la prima parte stampata l\'ordine non si cambia a metà', async () => {
        await monta();
        await apriIlBlocco('stampa', conParti([[1, 2, 3], [4, 5]]));
        axiosPost.mockResolvedValue(pdf);
        expect(selectOrdine().disabled).toBe(false);

        await clic('Stampa le prime 3');

        expect(selectOrdine().disabled).toBe(true);
        expect(testo()).toContain('Una parte è già stampata: l\'ordine non si cambia a metà.');
    });

    test('con meno ricevute del tetto cambiare l\'ordine non rifà l\'anteprima: non ci sono parti', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima());
        const prima = axiosPost.mock.calls.length;

        selectOrdine().value = 'data';
        selectOrdine().dispatchEvent(new Event('change'));
        await flushPromises();

        expect(axiosPost.mock.calls.length).toBe(prima);
    });
});

describe('il contrassegno su una ricevuta per email', () => {
    test('nell\'invio in blocco la frase c\'è solo scegliendo il contrassegno; nella stampa no', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        expect(testo()).not.toContain(NOTA_CONTRASSEGNO_EMAIL);

        const contrassegno = document.body.querySelector('[role="radio"][value="assolta_contrassegno"]') as HTMLElement | null;
        // I quattro pulsanti ci sono sempre (la scelta ricordata ha acceso «Assolta: virtuale»); si sceglie il contrassegno.
        expect(contrassegno).not.toBeNull();
        contrassegno!.click();
        await flushPromises();
        expect(testo()).toContain(NOTA_CONTRASSEGNO_EMAIL);
    });
});

describe('il bollo in blocco: quattro pulsanti per gruppo', () => {
    const due = () => anteprima({ ricevute: 5, sopra_soglia: 3, sotto_soglia: 2 });
    const gruppi = () => [...document.body.querySelectorAll('[data-testid="scelta-bollo"]')] as HTMLElement[];
    const radio = (g: HTMLElement, id: string) => g.querySelector(`[role="radio"][value="${id}"]`) as HTMLElement;
    const scegli = async (g: HTMLElement, id: string) => { radio(g, id).click(); await flushPromises(); };
    const accesi = (g: HTMLElement) => [...g.querySelectorAll('[role="radio"]')].filter((r) => r.getAttribute('aria-checked') === 'true').map((r) => (r.textContent ?? '').trim());
    const estremi = () => [...document.body.querySelectorAll('[data-testid="estremi-bollo"]')] as HTMLElement[];
    const invia = async () => {
        axiosPost.mockResolvedValueOnce({ data: { persone: 3, ricevute: 5, messaggi: 3, inviate: 0, in_coda: 3, non_partite: 0, senza_indirizzo: 0, senza_indirizzo_incassi: [], senza_indirizzo_persone: [], restanti: null, errori: [] } });
        await clic('Invia');

        return axiosPost.mock.calls.at(-1)![1] as Record<string, unknown>;
    };

    test('i due gruppi, sopra e fino a € 77,47, hanno la stessa forma: titolo, dato in grigio a destra, quattro pulsanti con gli stessi nomi; la frase una volta sola, sotto il primo titolo', async () => {
        await monta();
        await apriIlBlocco('invio', due());

        const [sopra, sotto] = gruppi();
        expect(gruppi()).toHaveLength(2);
        for (const g of [sopra, sotto]) {
            const [titolo, dato] = [...g.firstElementChild!.children];
            expect(titolo.textContent).toBe('Imposta di bollo');
            expect(dato.classList.contains('text-muted-foreground')).toBe(true);
            expect([...g.querySelectorAll('[role="radio"]')].map((r) => (r.textContent ?? '').trim()))
                .toEqual(['Non dovuta', 'Da assolvere', 'Assolta: contrassegno', 'Assolta: virtuale']);
            expect(g.querySelector('[role="radiogroup"]')!.className).toBe(sopra.querySelector('[role="radiogroup"]')!.className);
            expect(g.querySelector('svg')).toBeNull();
        }
        expect(sopra.firstElementChild!.children[1].textContent).toBe('Ricevute sopra € 77,47 (3)');
        expect(sotto.firstElementChild!.children[1].textContent).toBe('Ricevute fino a € 77,47 (2)');
        expect(sopra.querySelector('[role="radiogroup"]')!.getAttribute('aria-label')).toBe('Imposta di bollo, ricevute sopra € 77,47 (3)');
        expect(sotto.querySelector('[role="radiogroup"]')!.getAttribute('aria-label')).toBe('Imposta di bollo, ricevute fino a € 77,47 (2)');
        // La frase generica una volta, dentro il primo gruppo.
        expect(testo().split(FRASE_BOLLO).length - 1).toBe(1);
        expect(sopra.textContent).toContain(FRASE_BOLLO);
        expect(sotto.textContent).not.toContain(FRASE_BOLLO);
        // La scelta ricordata: sopra «Assolta: virtuale», sotto «Non dovuta».
        expect(accesi(sopra)).toEqual(['Assolta: virtuale']);
        expect(accesi(sotto)).toEqual(['Non dovuta']);
        // Né i cerchietti con le etichette accanto, né «Come:».
        expect(testo()).not.toContain('Come:');
        expect(document.body.querySelectorAll('[data-testid="scelta-bollo"] label')).toHaveLength(0);
    });

    test('con un gruppo solo la frase sta sotto il suo titolo, anche se è quello delle ricevute fino a € 77,47', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima({ ricevute: 2, sopra_soglia: 0, sotto_soglia: 2 }));

        expect(gruppi()).toHaveLength(1);
        expect(gruppi()[0].firstElementChild!.children[1].textContent).toBe('Ricevute fino a € 77,47 (2)');
        expect(gruppi()[0].textContent).toContain(FRASE_BOLLO);
        expect(testo().split(FRASE_BOLLO).length - 1).toBe(1);
    });

    test('ogni gruppo ha il suo stato: scegliere nell\'uno non cambia l\'altro, e la richiesta porta tutti e due', async () => {
        await monta();
        await apriIlBlocco('invio', due());
        const [sopra, sotto] = gruppi();

        await scegli(sotto, 'da_assolvere');
        expect(accesi(sotto)).toEqual(['Da assolvere']);
        expect(accesi(sopra)).toEqual(['Assolta: virtuale']);
        expect(await invia()).toMatchObject({ bollo_sopra: 'assolta', bollo_sotto: 'da_assolvere', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1' });
    });

    test('con «Non dovuta» in tutti e due i gruppi la richiesta non porta il modo né gli estremi, anche se sono ricordati', async () => {
        await monta();
        await apriIlBlocco('invio', due());
        const [sopra, sotto] = gruppi();

        await scegli(sopra, 'non_dovuta');
        await scegli(sotto, 'non_dovuta');
        expect(await invia()).toMatchObject({ bollo_sopra: 'non_dovuta', bollo_sotto: 'non_dovuta', modo_bollo: null, estremi_bollo: null });
    });

    test('«Invia» aspetta la scelta di tutti e due i gruppi: finché uno non ne ha una, resta fermo', async () => {
        await monta({ scelta_ricordata: { bollo_sopra: 'non_dovuta', bollo_sotto: null, modo_bollo: null, estremi_bollo: null, stampa_causale: false, indirizzi: null } });
        await apriIlBlocco('invio', due());
        const [sopra, sotto] = gruppi();
        expect(accesi(sotto)).toEqual([]);
        expect(pulsante('Invia')!.disabled).toBe(true);

        await scegli(sotto, 'assolta_contrassegno');
        expect(accesi(sopra)).toEqual(['Non dovuta']);
        expect(pulsante('Invia')!.disabled).toBe(false);
    });

    test('il modo è uno solo per tutte le ricevute: con «Assolta» in tutti e due i gruppi cambiarlo in uno lo cambia nell\'altro, e una riga lo dice', async () => {
        await monta();
        await apriIlBlocco('invio', due());
        const [sopra, sotto] = gruppi();
        expect(testo()).not.toContain(NOTA_MODO_UNICO);

        await scegli(sotto, 'assolta_virtuale');
        expect(testo()).toContain(NOTA_MODO_UNICO);
        await scegli(sotto, 'assolta_contrassegno');
        expect(accesi(sotto)).toEqual(['Assolta: contrassegno']);
        expect(accesi(sopra)).toEqual(['Assolta: contrassegno']);

        await scegli(sotto, 'non_dovuta');
        expect(testo()).not.toContain(NOTA_MODO_UNICO);
        // Il modo scelto per ultimo resta, e con «Assolta» di nuovo in tutti e due la richiesta lo porta.
        await scegli(sotto, 'assolta_contrassegno');
        expect(await invia()).toMatchObject({ bollo_sopra: 'assolta', bollo_sotto: 'assolta', modo_bollo: 'contrassegno' });
    });

    test('gli estremi stanno una volta sola, sotto i gruppi, con una delle due «Assolta» in uno dei gruppi; il segnaposto è quello del modo virtuale', async () => {
        await monta();
        await apriIlBlocco('invio', due());
        const [sopra, sotto] = gruppi();

        expect(estremi()).toHaveLength(1);
        const blocco = estremi()[0];
        expect(sotto.compareDocumentPosition(blocco) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(blocco.querySelector('label')!.textContent).toBe('Estremi (facoltativi)');
        const campo = blocco.querySelector('input') as HTMLInputElement;
        expect(blocco.querySelector('label')!.getAttribute('for')).toBe(campo.id);
        expect(campo.getAttribute('placeholder')).toBe('autorizzazione n. … del …');
        expect(campo.value).toBe('aut. 1');

        await scegli(sopra, 'non_dovuta');
        expect(estremi()).toHaveLength(0);
        // Tornando su «Assolta: virtuale» l'autorizzazione di prima c'è ancora.
        await scegli(sopra, 'assolta_virtuale');
        expect((estremi()[0].querySelector('input') as HTMLInputElement).value).toBe('aut. 1');
        // E anche in un solo gruppo dei due basta.
        await scegli(sopra, 'non_dovuta');
        await scegli(sotto, 'assolta_virtuale');
        expect(estremi()).toHaveLength(1);
    });

    test('un gruppo che non si vede non fa comparire gli estremi: la scelta ricordata «Assolta» delle ricevute sopra la soglia non conta se ci sono solo le altre', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima({ ricevute: 2, sopra_soglia: 0, sotto_soglia: 2 }));

        expect(accesi(gruppi()[0])).toEqual(['Non dovuta']);
        expect(estremi()).toHaveLength(0);
    });

    test('con il contrassegno il blocco dice che il numero in blocco non si scrive, e non offre il campo né «Estremi»', async () => {
        await monta();
        await apriIlBlocco('invio', anteprima());
        await scegli(gruppi()[0], 'assolta_contrassegno');

        expect(estremi()).toHaveLength(0);
        expect(testo()).toContain('Il numero del contrassegno è diverso per ogni ricevuta');
        expect(testo()).not.toContain('Estremi (facoltativi)');
        expect(await invia()).toMatchObject({ bollo_sopra: 'assolta', modo_bollo: 'contrassegno', estremi_bollo: null });
    });

    test('nella stampa la frase sulla marca che non viaggia per email non c\'è; l\'avviso sul numero del contrassegno sì', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima());
        await scegli(gruppi()[0], 'assolta_contrassegno');

        expect(testo()).not.toContain(NOTA_CONTRASSEGNO_EMAIL);
        expect(testo()).toContain('Il numero del contrassegno è diverso per ogni ricevuta');
    });
});

describe('sul telefono e nel tema scuro', () => {
    test('sul telefono ogni riga è una scheda dentro il riquadro: l\'ultima emissione e «Apri» ci sono in ogni riga, e l\'intestazione della tabella si nasconde', async () => {
        await monta({ incassi: { data: [riga(1, { ultima: { id: 5, canale: 'stampa', stato: 'stampata', quando: '2026-11-05 09:00', destinatario: null, errore: null } }), riga(2)],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 } } });
        const elenco = document.body.querySelector('[data-testid="elenco-ricevute"]') as HTMLElement;
        const righe = [...elenco.querySelectorAll('tbody tr')] as HTMLElement[];

        expect(elenco.classList.contains('overflow-x-auto')).toBe(true);
        expect(elenco.querySelector('thead')!.classList.contains('hidden')).toBe(true);
        expect(elenco.querySelector('thead')!.classList.contains('md:table-header-group')).toBe(true);
        expect(righe).toHaveLength(2);
        for (const r of righe) {
            expect(r.classList.contains('grid')).toBe(true);
            expect(r.classList.contains('md:table-row')).toBe(true);
            expect(r.textContent).toContain('Apri');
        }
        expect(righe[0].textContent).toContain('Stampata il 05/11/2026');
        expect(righe[1].textContent).toContain('Mai emessa');
    });

    test('nessun colore fisso chiaro senza la sua variante scura: la pagina, i suoi avvisi e la finestra del blocco seguono il tema', async () => {
        await monta({
            in_coda_da_tempo: 2, non_partite: 1, registrati_di_recente: { numero: 1, dal: '2026-08-10' },
            annullate_da_comunicare: [{ id: 4, numero: 'INC-2026-00004', data: '2026-09-01' }],
        });
        expect(senzaVarianteScura()).toEqual([]);

        await apriIlBlocco('invio', anteprima({ piu_vecchie: 2, copie: 1, senza_indirizzo: { ricevute: 1, incassi: [3], persone: ['Ugo Neri'] }, chi_ha_versato: 1 }));
        expect(senzaVarianteScura()).toEqual([]);

        pagina!.unmount();
        document.body.innerHTML = '';
        await monta();
        await apriIlBlocco('invio', anteprima({ senza_server_posta: true }));
        expect(senzaVarianteScura()).toEqual([]);
    });
});

describe('l\'ordine cambiato mentre l\'anteprima si rifà', () => {
    const conParti = (parti: number[][]) => anteprima({ ricevute: 205, oltre_tetto: true, parti_pdf: parti, sopra_soglia: 205 });
    const selectOrdine = () => document.body.querySelector('[role="dialog"] select') as HTMLSelectElement;

    test('mentre arriva l\'anteprima del nuovo ordine la parte non si stampa, si vede che sta aggiornando, e dopo ogni ricevuta esce una volta', async () => {
        await monta();
        await apriIlBlocco('stampa', conParti([[1, 2, 3], [4, 5]]));
        axiosPost.mockResolvedValue(pdf);
        const stampati = () => axiosPost.mock.calls.filter(([url]) => url === '/gestionale.ricevute.stampa').map(([, campi]) => campi.incassi);

        let arriva: (v: unknown) => void = () => {};
        axiosPost.mockReturnValueOnce(new Promise((r) => { arriva = r; }));
        selectOrdine().value = 'unita';
        selectOrdine().dispatchEvent(new Event('change'));
        await flushPromises();

        // L'anteprima nuova è in viaggio: la parte, «Anteprima» e l'ordine sono fermi, e la finestra lo dice.
        expect(pulsante('Stampa le prime 3')?.disabled).toBe(true);
        expect(pulsante('Anteprima')?.disabled).toBe(true);
        expect(selectOrdine().disabled).toBe(true);
        expect(testo()).toContain('Aggiorno l\'anteprima…');
        pulsante('Stampa le prime 3')!.click();
        await flushPromises();
        expect(stampati()).toEqual([]);

        arriva({ data: conParti([[4, 5], [1, 2, 3]]) });
        await flushPromises();
        expect(testo()).not.toContain('Aggiorno l\'anteprima…');
        await clic('Stampa le prime 2');
        await clic('Stampa l\'ultima');

        expect(stampati()).toEqual([[4, 5], [1, 2, 3]]);
        expect(pulsante('Tutte stampate')?.disabled).toBe(true);
    });

    test('due anteprime in viaggio: vale l\'ultima chiesta, anche se arriva prima', async () => {
        await monta();
        await apriIlBlocco('stampa', conParti([[1, 2, 3], [4, 5]]));
        let primaArriva: (v: unknown) => void = () => {};
        let secondaArriva: (v: unknown) => void = () => {};
        axiosPost.mockReturnValueOnce(new Promise((r) => { primaArriva = r; }));
        axiosPost.mockReturnValueOnce(new Promise((r) => { secondaArriva = r; }));
        selectOrdine().value = 'unita';
        selectOrdine().dispatchEvent(new Event('change'));
        await flushPromises();
        selectOrdine().value = 'data';
        selectOrdine().dispatchEvent(new Event('change'));
        await flushPromises();

        secondaArriva({ data: conParti([[5], [1, 2, 3, 4]]) });
        await flushPromises();
        primaArriva({ data: conParti([[4, 5], [1, 2, 3]]) });
        await flushPromises();

        expect(pulsante('Stampa le prime 1')).toBeDefined();
        expect(pulsante('Stampa le prime 1')?.disabled).toBe(false);
    });
});

describe('sul telefono si seleziona la pagina', () => {
    test('la casella della pagina c\'è anche nella barra della scelta, solo sotto i 768 px, e spunta le righe della pagina', async () => {
        await monta();
        const casella = document.body.querySelector('[data-testid="seleziona-pagina-telefono"]') as HTMLElement;

        expect(casella).not.toBeNull();
        expect(casella.classList.contains('md:hidden')).toBe(true);
        expect(casella.textContent).toContain('Seleziona questa pagina');
        (casella.querySelector('button[role="checkbox"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(testo()).toContain('Selezionate: 2.');
    });
});

describe('«Scarica un PDF per persona»', () => {
    let clic2: string[] = [];
    beforeEach(() => {
        clic2 = [];
        (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:archivio');
        (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { clic2.push(this.download); });
    });

    test('sulle selezionate: le scelte del bollo come la stampa, poi l\'archivio con un PDF per persona si scarica; ogni ricevuta risulta stampata', async () => {
        const { router } = await import('@inertiajs/vue3');
        await monta();
        await apriIlBlocco('persone', anteprima());

        expect(testo()).toContain('Un PDF per persona');
        expect(testo()).toContain('Indirizzi email.txt');
        expect(document.body.querySelector('#nota-blocco')).not.toBeNull();
        axiosPost.mockResolvedValueOnce({ data: new Blob(['PK'], { type: 'application/zip' }), headers: { 'content-disposition': 'attachment; filename="ricevute-per-persona-condominio-via-dei-pini-2026-11-05.zip"' } });
        await clic('Scarica lo .zip');

        const [url, campi, config] = axiosPost.mock.calls.at(-1)!;
        expect(url).toBe('/gestionale.ricevute.pdf-per-persona');
        expect(campi).toMatchObject({ tutte: 1, bollo_sopra: 'assolta', bollo_sotto: 'non_dovuta', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1' });
        expect(config).toEqual({ responseType: 'blob' });
        expect(clic2).toEqual(['ricevute-per-persona-condominio-via-dei-pini-2026-11-05.zip']);
        expect(testo()).toContain('Archivio scaricato');
        expect(router.reload).toHaveBeenCalled();
    });

    test('più ricevute del tetto non stanno in un archivio: il pulsante è fermo e la finestra dice di restringere i filtri; un rifiuto del server si legge', async () => {
        await monta();
        await apriIlBlocco('persone', anteprima({ ricevute: 205, oltre_tetto: true, sopra_soglia: 205 }));
        expect(pulsante('Scarica lo .zip')?.disabled).toBe(true);
        expect(testo()).toContain('In un archivio stanno al massimo 200 ricevute');

        await monta();
        await apriIlBlocco('persone', anteprima());
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: new Blob(['Archivio non fatto. Il numero del contrassegno è diverso per ogni ricevuta.'], { type: 'text/plain' }) } });
        await clic('Scarica lo .zip');
        expect(testo()).toContain('Archivio non fatto. Il numero del contrassegno è diverso per ogni ricevuta.');
        expect(clic2).toEqual([]);
    });
});

describe('la stampa e l\'anteprima in blocco: la scheda nuova si apre nel clic, il PDF arriva in sottofondo', () => {
    /** Il PDF chiesto e non ancora arrivato: la risposta la dà il test, quando vuole. */
    const pdfInViaggio = () => {
        let consegna: (v: unknown) => void = () => {};
        let rifiuta: (e: unknown) => void = () => {};
        axiosPost.mockImplementationOnce(() => { fatti.push('richiesta'); return new Promise((ok, no) => { consegna = ok; rifiuta = no; }); });

        return { arriva: (r: unknown) => consegna(r), fallisce: (e: unknown) => rifiuta(e) };
    };
    const stampaDelBlocco = () => pulsanti().find((b) => (b.textContent ?? '').trim() === 'Stampa')!;

    test('«Stampa»: la scheda prima della richiesta; la pagina ricarica quando il PDF è arrivato, non prima; poi lo dice', async () => {
        const { router } = await import('@inertiajs/vue3');
        await monta();
        await apriIlBlocco('stampa', anteprima());
        vi.mocked(router.reload).mockClear();
        const viaggio = pdfInViaggio();

        stampaDelBlocco().click();
        expect(fatti).toEqual(['scheda', 'richiesta']);
        // Senza «noopener»: con quello il browser renderebbe sempre null, e il PDF si scaricherebbe sempre.
        expect(window.open).toHaveBeenCalledWith('', '_blank');
        expect(scheda!.document.body.textContent).toContain('Sto preparando il PDF');
        const [url, campi, config] = axiosPost.mock.calls.at(-1)!;
        expect(url).toBe('/gestionale.ricevute.stampa');
        expect(campi).toMatchObject({ tutte: 1, bollo_sopra: 'assolta', bollo_sotto: 'non_dovuta', stampa_causale: 0, anche_chi_ha_versato: 0, ordine: 'persona' });
        expect(campi.filtri).toMatchObject({ ricevuta: 'mai', data_da: '2026-10-01', data_a: '2026-11-05' });
        expect(config).toEqual({ responseType: 'blob' });
        // Anche aspettando, finché il PDF non arriva la pagina non ricarica.
        await new Promise((r) => setTimeout(r, 2100));
        await flushPromises();
        expect(router.reload).not.toHaveBeenCalled();
        expect(testo()).toContain('Sto preparando il PDF: si apre nella scheda nuova.');

        viaggio.arriva(pdf);
        await flushPromises();

        expect(scheda!.location.href).toBe('blob:ricevute');
        // La scheda è aperta e mostra il PDF da quell'indirizzo: non si libera adesso.
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(testo()).toContain('Il PDF è aperto in una scheda nuova. Le ricevute risultano stampate.');
        expect(testo()).not.toContain('Sto preparando il PDF');
    });

    test('«Anteprima»: la scheda prima della richiesta, il PDF nella scheda, e la pagina non ricarica', async () => {
        const { router } = await import('@inertiajs/vue3');
        await monta();
        await apriIlBlocco('stampa', anteprima());
        vi.mocked(router.reload).mockClear();
        const viaggio = pdfInViaggio();

        pulsante('Anteprima')!.click();
        expect(fatti).toEqual(['scheda', 'richiesta']);
        expect(window.open).toHaveBeenCalledWith('', '_blank');
        expect(axiosPost.mock.calls.at(-1)![0]).toBe('/gestionale.ricevute.pdf-anteprima');
        viaggio.arriva(pdf);
        await flushPromises();

        expect(scheda!.location.href).toBe('blob:ricevute');
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
        expect(testo()).not.toContain('risultano stampate');
    });

    test('se il browser non apre la scheda la riga d\'attesa lo dice subito, il PDF si scarica con il nome della risposta, e la finestra lo dice', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima());
        bloccata = true;
        const scaricati: string[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { scaricati.push(this.download); });
        const viaggio = pdfInViaggio();

        stampaDelBlocco().click();
        await flushPromises();
        // Mentre aspetta: la scheda non c'è, e la riga non promette di aprirla.
        expect(testo()).toContain('Sto preparando il PDF: la scheda nuova non si è aperta, quindi si scarica.');
        expect(testo()).not.toContain('si apre nella scheda nuova');
        viaggio.arriva(pdf);
        await flushPromises();

        expect(scaricati).toEqual(['ricevute-condominio-via-dei-pini-2026-11-05.pdf']);
        // Il file può non essere ancora sul computer (il browser può chiedere dove salvarlo): la frase non dice «scaricato». La stampa è già nel registro.
        expect(testo()).toContain('La scheda nuova non si è aperta, o era già chiusa: il PDF si scarica con il nome «ricevute-condominio-via-dei-pini-2026-11-05.pdf». '
            + 'Se il browser chiede dove salvarlo, arriva quando rispondi. Le ricevute risultano già stampate, anche se il file arriva dopo.');
        expect(testo()).not.toContain('Sto preparando il PDF');

        // La volta dopo, con la scheda che si apre, la riga torna quella della scheda.
        bloccata = false;
        const ancora = pdfInViaggio();
        stampaDelBlocco().click();
        await flushPromises();
        expect(testo()).toContain('Sto preparando il PDF: si apre nella scheda nuova.');
        ancora.arriva(pdf);
        await flushPromises();
    });

    test('se il server rifiuta, la scheda si chiude, la finestra del blocco dice perché, la pagina non ricarica e la parte resta da stampare', async () => {
        const { router } = await import('@inertiajs/vue3');
        await monta();
        await apriIlBlocco('stampa', anteprima({ ricevute: 205, oltre_tetto: true, parti_pdf: [[1, 2, 3], [4, 5]], sopra_soglia: 205 }));
        vi.mocked(router.reload).mockClear();
        const viaggio = pdfInViaggio();

        pulsante('Stampa le prime 3')!.click();
        viaggio.fallisce({ response: { status: 422, data: new Blob(['Ricevuta non stampata. Il numero del contrassegno è diverso per ogni ricevuta.'], { type: 'text/plain' }) } });
        await flushPromises();

        expect(fatti).toEqual(['scheda', 'richiesta', 'scheda chiusa']);
        expect(testo()).toContain('Ricevuta non stampata. Il numero del contrassegno è diverso per ogni ricevuta.');
        expect(router.reload).not.toHaveBeenCalled();
        expect(pulsante('Stampa le prime 3')?.disabled).toBe(false);

        // La sessione scaduta si legge in italiano.
        const ancora = pdfInViaggio();
        pulsante('Stampa le prime 3')!.click();
        ancora.fallisce({ response: { status: 419, data: new Blob(['{"message":"CSRF token mismatch."}'], { type: 'application/json' }) } });
        await flushPromises();
        expect(testo()).toContain('La sessione è scaduta: ricarica la pagina e, se serve, accedi di nuovo.');
        expect(testo()).not.toContain('CSRF');
    });

    test('un secondo clic mentre il PDF si prepara non apre un\'altra scheda e non chiede un altro PDF', async () => {
        await monta();
        await apriIlBlocco('stampa', anteprima());
        const viaggio = pdfInViaggio();
        const prima = axiosPost.mock.calls.length;

        // Due clic di fila, prima che la finestra si ridisegni, e uno su «Anteprima».
        stampaDelBlocco().click();
        stampaDelBlocco().click();
        pulsante('Anteprima')!.click();
        await flushPromises();

        expect(axiosPost.mock.calls.length - prima).toBe(1);
        expect(window.open).toHaveBeenCalledTimes(1);
        expect(stampaDelBlocco().disabled).toBe(true);
        expect(pulsante('Anteprima')?.disabled).toBe(true);
        viaggio.arriva(pdf);
        await flushPromises();
        expect(axiosPost.mock.calls.length - prima).toBe(1);
    });

    test('la frase del server dopo un rimando (una scheda del PDF riaperta) sta in testa alla pagina, e resta finché non la chiudi', async () => {
        condivise.props = { flash: { message: { type: 'warning', message: 'Il PDF della stampa non si riapre dall\'indirizzo della scheda: per averlo di nuovo premi «Stampa» in questa pagina.' } } };
        await monta();

        expect(testo()).toContain('Il PDF della stampa non si riapre dall\'indirizzo della scheda');
        expect(document.body.querySelector('[aria-label="Chiudi avviso"]')).not.toBeNull();
    });
});

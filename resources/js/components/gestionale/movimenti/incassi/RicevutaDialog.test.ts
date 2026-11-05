// @vitest-environment jsdom

/**
 * La finestra della ricevuta di un incasso (1.12.0-beta.2): quello che l'amministratore legge prima di stampare o mandare. Si monta la finestra
 * vera, con le risposte del server scritte a mano come le manda `RicevuteIncassiController::opzioni()`.
 *
 * - La prima volta una riga dice che la prima emissione sarà l'originale, e «Anteprima» apre il PDF senza il bollo scelto (non emette).
 * - Mostra a quali quote sarà imputato il versamento, e dice dove scrivere gli interessi o le spese che si vogliono tenere fuori.
 * - Sul bollo una frase sola, generica (se e come la ricevuta lo sconta lo decidi tu, con il tuo commercialista); sopra € 77,47 resta il solo dato, in grigio
 *   a destra del titolo, senza giudizio. La scelta è di quattro pulsanti in fila, uno solo acceso, e scrive gli stessi valori per il server.
 * - La nota parte vuota; la ricevuta di un incasso annullato si manda per email solo se era già partita; un incasso con le rate di più
 *   persone non si emette e indica l'estratto conto di ciascuna; l'errore intero del server si legge solo se il server lo manda.
 * - Il contrassegno su una ricevuta per email si può scegliere, con la frase che dice che la marca non viaggia; un 429 si legge in italiano;
 *   la ricevuta annullata partita a due primarie ha due caselle e manda le chiavi spuntate; nessun colore fisso chiaro senza la variante scura.
 * - «Anteprima» e «Stampa» aprono la scheda nuova nel clic, prima della richiesta del PDF, e il PDF arrivato va nella scheda (o si scarica, se la
 *   scheda non c'è); un errore chiude la scheda e si legge nella finestra; la stampa rilegge la finestra a PDF arrivato; un secondo clic non
 *   chiede un secondo PDF.
 *
 * Che cosa NON copre: il server (nei test PHP della ricevuta) e l'aspetto vero (si guarda nel browser, in chiaro e in scuro).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { csrf_token: 'gettone' } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.anagrafica ? `/${parametri.anagrafica}` : ''}`;

const RicevutaDialog = (await import('./RicevutaDialog.vue')).default;
const { FRASE_BOLLO, NOTA_CONTRASSEGNO_EMAIL, NOTA_INTERESSI, RIGA_ORIGINALE } = await import('@/lib/gestionale/incassi/ricevute');

const anna = { anagrafica_id: 1, nome: 'Anna Bruni', ruolo: 'posizione', indirizzi: [{ tipo: 'email', indirizzo: 'anna@esempio.it', proposto: true }] };

function opzioni(extra: Record<string, unknown> = {}) {
    return {
        id: 5, numero: 'INC-2026-00005', data_versamento: '2026-02-01', importo: 15000, causale: null, compensazioni_escluse: [],
        quote: [{ rata: 'Rata 1', piano: 'Preventivo 2026', gestione: 'Ordinaria 2026', unita: 'Int. 4', importo: 15000, imputato: 15000, dal_credito: 0, dopo: 0 }],
        parte_in_piu: [], credito_usato: 0, sopra_soglia: true, annullato: false, inviabile: true, emettibile: true, motivo: null, persone_dell_incasso: [],
        senza_server_posta: false, copia: null, storno: null, posizione: { id: 1, nome: 'Anna Bruni' }, versato_da: null, destinatari: [anna], emissioni: [],
        bozza_email: { oggetto: 'Condominio Via dei Pini — Ricevuta n. INC-2026-00005 del 01/02/2026', saluto: 'Buongiorno,', saluti: { 1: 'Gentile Anna Bruni,' },
            righe: ['In allegato la ricevuta del versamento di € 150,00 del 01/02/2026, numero INC-2026-00005.'], chiusura: 'Cordiali saluti, Condominio Via dei Pini' },
        scelta_ricordata: { bollo_sopra: null, bollo_sotto: null, modo_bollo: null, estremi_bollo: null, stampa_causale: null },
        ...extra,
    };
}

let finestra: VueWrapper | null = null;

async function apri(risposta: Record<string, unknown>) {
    axiosGet.mockResolvedValue({ data: risposta });
    finestra = mount(RicevutaDialog, { attachTo: document.body, props: { open: false, condominioId: 3, incassoId: 5 } });
    await finestra.setProps({ open: true });
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
/** Le righe della tabella delle quote, cella per cella, con gli spazi normalizzati (anche quello dopo l'euro). */
// Le righe della tabella, senza quella dei titoli delle colonne (ha la sua prova: «i titoli delle colonne stanno sotto il nome del piano»).
const righeDellaTabella = () => [...document.body.querySelectorAll('[data-testid="quote-del-versamento"] tbody tr:not([data-titoli-colonne]), [data-testid="quote-del-versamento"] tfoot tr')]
    .map((r) => [...r.querySelectorAll('td, th')].map((c) => [...c.childNodes].filter((n) => n.nodeType !== Node.COMMENT_NODE).map((n) => n.textContent ?? '').join(' ').replace(/\s+/g, ' ').trim()));
const pulsante = (nome: string) => [...document.body.querySelectorAll('button')].find((b) => (b.textContent ?? '').includes(nome)) as HTMLButtonElement | undefined;

beforeEach(() => {
    axiosGet.mockReset();
    axiosPost.mockReset();
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('prima di emettere', () => {
    test('la prima volta dice che la prima emissione sarà l\'originale, e «Anteprima» c\'è anche senza il bollo scelto', async () => {
        await apri(opzioni());

        expect(testo()).toContain(RIGA_ORIGINALE);
        expect(pulsante('Anteprima')?.disabled).toBe(false);
        // Stampa e Invia chiedono il bollo; l'anteprima no.
        expect(pulsante('Stampa')?.disabled).toBe(true);
        expect(pulsante('Invia')?.disabled).toBe(true);
    });

    test('dopo la prima emissione la riga non c\'è: la prossima esce come copia, e lo dice', async () => {
        await apri(opzioni({
            copia: { del: '2026-02-02', cambiata: false },
            emissioni: [{ id: 1, canale: 'stampa', stato: 'stampata', quando: '2026-02-02 10:00', destinatario: null, errore: null }],
        }));

        expect(testo()).not.toContain(RIGA_ORIGINALE);
        expect(testo()).toContain('Già emessa il 02/02/2026: la prossima esce come «Copia»');
    });

    test('mostra a quali quote va il versamento in una tabella: il piano una volta, una riga per quota con il resto, il totale, e dove scrivere interessi o spese', async () => {
        await apri(opzioni({
            quote: [
                { rata: 'Rata 1', piano: 'Preventivo 2026', gestione: 'Ordinaria 2026', unita: 'Int. 4', scadenza: '2026-01-31', importo: 15000, imputato: 10000, dal_credito: 0, dopo: 0 },
                { rata: 'Rata 2', piano: 'Preventivo 2026', gestione: 'Ordinaria 2026', unita: 'Int. 4', scadenza: '2026-03-31', importo: 12050, imputato: 8050, dal_credito: 3000, dopo: 4000 },
            ],
            parte_in_piu: [{ importo: 5000, persona: 'Carlo Bruni', rata: 'Rata 3', riduce: 5000, a_credito: 0 }],
        }));

        const t = testo();
        expect(t).toContain('A che cosa va il versamento');
        expect(t.split('Preventivo 2026')).toHaveLength(2);
        expect(righeDellaTabella()).toEqual([
            ['Preventivo 2026 — Ordinaria 2026'],
            ['Int. 4', 'Rata 1 31/01/2026', '€ 100,00', '€ 0,00'],
            ['Int. 4', 'Rata 2 31/03/2026', '€ 80,50 di cui dal credito € 30,00', '€ 40,00'],
            ['Totale', '€ 180,50', '€ 40,00'],
        ]);
        expect(t).toMatch(/Parte in più: €\s?50,00 riducono la Rata 3 di Carlo Bruni/);
        expect(t).toContain('Se non è giusto, storna l\'incasso e registralo di nuovo prima di emettere.');
        expect(t).toContain(NOTA_INTERESSI);
        // Nessuna parola di effetto legale sulla nota.
        expect(NOTA_INTERESSI).not.toMatch(/art\.|presum|quietanza/i);
    });

    test('la nota parte vuota', async () => {
        await apri(opzioni());

        expect((document.body.querySelector('#nota-ricevuta') as HTMLTextAreaElement).value).toBe('');
    });
});

describe('la soglia del bollo', () => {
    test('sul bollo la finestra dice la frase generica: se e come la ricevuta lo sconta lo decidi tu, con il tuo commercialista', async () => {
        await apri(opzioni());

        expect(testo()).toContain(FRASE_BOLLO);
        expect(FRASE_BOLLO).toBe('Se e come la ricevuta sconta il bollo lo decidi tu, con il tuo commercialista.');
    });

    test('la frase generica c\'è anche quando l\'importo non è sopra la soglia', async () => {
        await apri(opzioni({ sopra_soglia: false, importo: 5000 }));

        expect(testo()).toContain(FRASE_BOLLO);
    });

    test('sopra la soglia c\'è solo il dato «Importo sopra € 77,47», senza giudizio: niente «di norma», niente bollo di € 2,00', async () => {
        await apri(opzioni());

        expect(testo()).toContain('Importo sopra € 77,47');
        expect(testo()).not.toMatch(/di norma|sconta il bollo di|€\s?2,00|non lo assolve\.\s*Lo stato|da confermare|è una lettura|solo denaro versato/i);
        // La sola frase sul bollo è quella generica: compare una volta.
        expect(testo().split(FRASE_BOLLO).length - 1).toBe(1);
        expect(testo()).not.toContain('Il programma non lo calcola e non lo assolve.');
    });

    test('«Importo sopra € 77,47» sta a destra del titolo, in grigio: niente riquadro colorato, niente icona di avviso', async () => {
        await apri(opzioni());

        const blocco = document.body.querySelector('[data-testid="scelta-bollo"]') as HTMLElement;
        const [titolo, dato] = [...blocco.firstElementChild!.children] as HTMLElement[];
        expect(titolo.textContent).toBe('Imposta di bollo');
        expect(dato.textContent).toBe('Importo sopra € 77,47');
        expect(dato.classList.contains('text-muted-foreground')).toBe(true);
        // A destra: il titolo e il dato stanno sulla stessa riga, il dato spinto a destra.
        expect(blocco.firstElementChild!.classList.contains('justify-between')).toBe(true);
        expect(dato.classList.contains('ml-auto')).toBe(true);
        const frase = [...blocco.querySelectorAll('p')].find((p) => (p.textContent ?? '').includes(FRASE_BOLLO))!;
        // Nessun riquadro (bordo, sfondo) né colore di avviso sulla riga del titolo e sulla frase; nessuna icona.
        expect(blocco.querySelector('svg')).toBeNull();
        expect(blocco.firstElementChild!.outerHTML + frase.outerHTML).not.toMatch(/amber|rose|border|bg-|text-(red|orange|yellow)/);
        // La frase generica sta sotto il titolo, prima dei pulsanti.
        const gruppo = blocco.querySelector('[role="radiogroup"]')!;
        expect(blocco.firstElementChild!.compareDocumentPosition(frase) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(frase.compareDocumentPosition(gruppo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    test('sotto la soglia non c\'è nessun avviso sull\'importo', async () => {
        await apri(opzioni({ sopra_soglia: false, importo: 5000 }));

        expect(testo()).not.toContain('Importo sopra € 77,47');
    });

    test('se una parte viene dal credito lo dice, perché non conta per la soglia', async () => {
        await apri(opzioni({ sopra_soglia: false, importo: 7050, credito_usato: 5000 }));

        expect(testo()).toMatch(/In questa ricevuta €\s?50,00 vengono dal credito: non contano per la soglia\./);
    });
});

describe('l\'incasso annullato', () => {
    const annullato = { annullato: true, storno: { protocollo: 'RET-2026-00001', data: '2026-02-10' }, emissioni: [{ id: 1, canale: 'email', stato: 'inviata', quando: '2026-02-02 10:00', destinatario: 'anna@esempio.it', errore: null }], copia: { del: '2026-02-02', cambiata: false } };

    test('se la ricevuta era partita per email la si può mandare di nuovo agli stessi indirizzi, e non c\'è un altro indirizzo da scrivere', async () => {
        await apri(opzioni({ ...annullato }));

        expect(testo()).toContain('La ricevuta si stampa con «INCASSO ANNULLATO».');
        expect(testo()).toContain('Era partita per email: puoi mandarla di nuovo agli stessi indirizzi.');
        expect(testo()).toContain('gli indirizzi a cui era già andata');
        expect(testo()).not.toContain('Un altro indirizzo');
        expect(pulsante('Invia')).toBeDefined();
        expect(testo()).not.toContain('da consegnare');
    });

    test('se non era partita per email non c\'è «Invia», e si dice che se e come dirlo alla persona lo decide l\'amministratore', async () => {
        await apri(opzioni({ ...annullato, inviabile: false, destinatari: [], emissioni: [] , copia: null }));

        expect(pulsante('Invia')).toBeUndefined();
        expect(testo()).toContain('Se e come dirlo alla persona lo decidi tu.');
        expect(pulsante('Stampa')).toBeDefined();
    });
});

describe('quando la ricevuta non si può fare', () => {
    test('un incasso con le rate di più persone: il motivo e l\'estratto conto di ciascuna, e niente anteprima né stampa', async () => {
        await apri(opzioni({
            emettibile: false, motivo: 'Questo incasso copre le rate di più persone (Anna Bruni, Ugo Neri) e non dice chi ha versato: non si può fare una ricevuta sola. Per ciascuna persona c\'è l\'estratto conto.',
            persone_dell_incasso: [{ id: 1, nome: 'Anna Bruni' }, { id: 7, nome: 'Ugo Neri' }],
        }));

        expect(testo()).toContain('non dice chi ha versato');
        const link = [...document.body.querySelectorAll('a')].map((a) => [a.textContent?.trim(), a.getAttribute('href')]);
        expect(link).toEqual([['Estratto conto di Anna Bruni', '/gestionale.anagrafiche.estratto-conto/1'], ['Estratto conto di Ugo Neri', '/gestionale.anagrafiche.estratto-conto/7']]);
        expect(pulsante('Anteprima')?.disabled).toBe(true);
        expect(pulsante('Stampa')?.disabled).toBe(true);
    });
});

describe('l\'errore del server di posta', () => {
    const nonPartita = { id: 2, canale: 'email', stato: 'non_partita', quando: '2026-02-03 09:00', destinatario: 'anna@esempio.it', errore: 'Il server di posta non ha accettato il messaggio (codice 535).' };

    test('tutti leggono la frase con il codice; l\'errore intero c\'è solo se il server lo manda', async () => {
        await apri(opzioni({ emissioni: [nonPartita], copia: null }));

        expect(testo()).toContain('Il server di posta non ha accettato il messaggio (codice 535).');
        expect(testo()).not.toContain('Dal server:');

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ emissioni: [{ ...nonPartita, errore_completo: 'Failed to authenticate on SMTP server with username "studio@smtp.example"' }], copia: null }));

        expect(testo()).toContain('Dal server: Failed to authenticate on SMTP server with username "studio@smtp.example"');
    });
});

/** Gli elementi con un colore fisso chiaro (sfondo bianco o pastello) e nessuna variante scura per lo sfondo: restano chiari nel tema scuro. */
function senzaVarianteScura(): string[] {
    const chiari = /^(bg-white|bg-slate-50|bg-rose-50|bg-amber-50|bg-emerald-50|bg-sky-50)$/;

    return [...document.body.querySelectorAll('[class]')]
        .map((e) => (e.getAttribute('class') ?? '').split(/\s+/))
        .filter((classi) => classi.some((c) => chiari.test(c)) && !classi.some((c) => c.startsWith('dark:bg-')))
        .map((classi) => classi.join(' '));
}

describe('il contrassegno su una ricevuta per email', () => {
    const scegliIlContrassegno = async () => {
        (document.body.querySelector('[role="radio"][value="assolta_contrassegno"]') as HTMLElement).click();
        await flushPromises();
    };

    test('si può scegliere: la frase dice che una ricevuta per email non porta la marca, e che il modo lo decide l\'amministratore', async () => {
        await apri(opzioni());
        expect(testo()).not.toContain(NOTA_CONTRASSEGNO_EMAIL);

        await scegliIlContrassegno();

        expect(testo()).toContain(NOTA_CONTRASSEGNO_EMAIL);
        expect(pulsante('Stampa')).toBeDefined();
    });

    test('senza un server di posta la frase c\'è, perché con «Prepara l\'email» la ricevuta parte per email lo stesso; se non si può mandare, no', async () => {
        await apri(opzioni({ senza_server_posta: true }));
        await scegliIlContrassegno();
        expect(testo()).toContain(NOTA_CONTRASSEGNO_EMAIL);

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ annullato: true, inviabile: false, destinatari: [], senza_server_posta: true }));
        await scegliIlContrassegno();
        expect(testo()).not.toContain(NOTA_CONTRASSEGNO_EMAIL);
    });
});

describe('i quattro pulsanti del bollo', () => {
    const radio = (id: string) => document.body.querySelector(`[role="radio"][value="${id}"]`) as HTMLElement | null;
    const scegli = async (id: string) => { radio(id)!.click(); await flushPromises(); };
    const gruppo = () => document.body.querySelector('[data-testid="scelta-bollo"] [role="radiogroup"]') as HTMLElement;
    const accesi = () => [...gruppo().querySelectorAll('[role="radio"]')].filter((r) => r.getAttribute('aria-checked') === 'true').map((r) => (r.textContent ?? '').trim());
    const estremi = () => document.body.querySelector('[data-testid="estremi-bollo"]') as HTMLElement | null;
    const ricordata = (extra: Record<string, unknown>) => ({ scelta_ricordata: { bollo_sopra: 'assolta', bollo_sotto: null, modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: null, ...extra } });
    const invia = async () => {
        axiosPost.mockResolvedValueOnce({ data: { inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 0, errori: [] } });
        pulsante('Invia')!.click();
        await flushPromises();

        return axiosPost.mock.calls.at(-1)![1];
    };

    test('sono quattro in fila, in questo ordine, in un solo gruppo a scelta singola con il suo nome; sul telefono vanno a capo due per riga', async () => {
        await apri(opzioni());

        expect(document.body.querySelectorAll('[role="radiogroup"]')).toHaveLength(1);
        expect(gruppo().getAttribute('aria-label')).toBe('Imposta di bollo');
        expect([...gruppo().querySelectorAll('[role="radio"]')].map((r) => (r.textContent ?? '').trim()))
            .toEqual(['Non dovuta', 'Da assolvere', 'Assolta: contrassegno', 'Assolta: virtuale']);
        // Una griglia: due colonne sul telefono, quattro in fila dalla larghezza della finestra in su; mai una riga che va a capo a pezzi. Le quattro
        // colonne sono larghe quanto il testo e ogni pulsante sta su una riga (Vincenzo, 05/11: «Assolta: contrassegno» andava a capo).
        expect(gruppo().classList.contains('grid')).toBe(true);
        expect(gruppo().classList.contains('grid-cols-2')).toBe(true);
        expect(gruppo().classList.contains('sm:grid-cols-[repeat(4,auto)]')).toBe(true);
        expect(gruppo().classList.contains('sm:grid-cols-4')).toBe(false);
        expect([...gruppo().querySelectorAll('[role="radio"]')].every((r) => r.classList.contains('sm:whitespace-nowrap'))).toBe(true);
        expect(gruppo().classList.contains('flex-wrap')).toBe(false);
        // Non sono più tre cerchietti con le etichette accanto, né c'è un «Come:».
        expect(document.body.querySelectorAll('[data-testid="scelta-bollo"] label')).toHaveLength(0);
        expect(testo()).not.toContain('Come:');
        expect(testo()).not.toContain('Con il contrassegno (la marca)');
    });

    test('la prima volta nessuno è acceso; scegliere ne accende uno solo, e un altro spegne il primo', async () => {
        await apri(opzioni());
        expect(accesi()).toEqual([]);

        await scegli('da_assolvere');
        expect(accesi()).toEqual(['Da assolvere']);
        await scegli('assolta_contrassegno');
        expect(accesi()).toEqual(['Assolta: contrassegno']);
        await scegli('assolta_virtuale');
        expect(accesi()).toEqual(['Assolta: virtuale']);
        await scegli('non_dovuta');
        expect(accesi()).toEqual(['Non dovuta']);
    });

    test('si scorrono con le frecce: la freccia accende il successivo', async () => {
        await apri(opzioni(ricordata({ modo_bollo: 'contrassegno', bollo_sopra: 'assolta' })));
        const tutti = [...gruppo().querySelectorAll('[role="radio"]')] as HTMLElement[];
        expect(accesi()).toEqual(['Assolta: contrassegno']);

        tutti[2].focus();
        tutti[2].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        // Il successivo prende il fuoco subito dopo, e si accende con un altro timer: si aspetta, poi il tasto si rilascia.
        await new Promise((r) => setTimeout(r, 30));
        tutti[2].dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowRight', bubbles: true }));
        await flushPromises();

        expect(accesi()).toEqual(['Assolta: virtuale']);
    });

    test('ogni pulsante scrive per il server gli stessi valori di prima: lo stato e, per le due «Assolta», il modo', async () => {
        await apri(opzioni());

        await scegli('non_dovuta');
        expect(await invia()).toMatchObject({ bollo: 'non_dovuta', modo_bollo: null, estremi_bollo: null });
        await scegli('da_assolvere');
        expect(await invia()).toMatchObject({ bollo: 'da_assolvere', modo_bollo: null, estremi_bollo: null });
        await scegli('assolta_contrassegno');
        expect(await invia()).toMatchObject({ bollo: 'assolta', modo_bollo: 'contrassegno', estremi_bollo: null });
        await scegli('assolta_virtuale');
        expect(await invia()).toMatchObject({ bollo: 'assolta', modo_bollo: 'virtuale', estremi_bollo: null });
    });

    test('«Stampa» e «Invia» chiedono una scelta: fino ad allora restano fermi, qualunque dei quattro la faccia', async () => {
        await apri(opzioni());
        expect(pulsante('Stampa')!.disabled).toBe(true);
        expect(pulsante('Invia')!.disabled).toBe(true);

        await scegli('assolta_virtuale');
        expect(pulsante('Stampa')!.disabled).toBe(false);
        expect(pulsante('Invia')!.disabled).toBe(false);
    });

    test('gli estremi stanno sotto i pulsanti e compaiono solo con una delle due «Assolta»: con nessuna scelta, «Non dovuta» e «Da assolvere» non ci sono', async () => {
        await apri(opzioni());
        expect(estremi()).toBeNull();

        await scegli('non_dovuta');
        expect(estremi()).toBeNull();
        await scegli('da_assolvere');
        expect(estremi()).toBeNull();

        await scegli('assolta_virtuale');
        expect(estremi()).not.toBeNull();
        expect(gruppo().compareDocumentPosition(estremi()!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        const etichetta = estremi()!.querySelector('label')!;
        const campo = estremi()!.querySelector('input')!;
        expect(etichetta.textContent).toBe('Estremi (facoltativi)');
        expect(etichetta.getAttribute('for')).toBe(campo.id);
        expect(campo.id).not.toBe('');
        expect(campo.getAttribute('maxlength')).toBe('200');
    });

    test('il segnaposto degli estremi segue il modo: contrassegno n. … oppure autorizzazione n. … del …', async () => {
        await apri(opzioni());

        await scegli('assolta_contrassegno');
        expect(estremi()!.querySelector('input')!.getAttribute('placeholder')).toBe('contrassegno n. …');
        await scegli('assolta_virtuale');
        expect(estremi()!.querySelector('input')!.getAttribute('placeholder')).toBe('autorizzazione n. … del …');
    });

    test('la frase sul contrassegno per email sta con gli estremi, solo con «Assolta: contrassegno»', async () => {
        await apri(opzioni());

        await scegli('assolta_virtuale');
        expect(estremi()!.querySelector('[data-testid="nota-contrassegno-email"]')).toBeNull();
        await scegli('assolta_contrassegno');
        expect(estremi()!.querySelector('[data-testid="nota-contrassegno-email"]')!.textContent).toBe(NOTA_CONTRASSEGNO_EMAIL);
    });

    test('l\'ultima scelta è ricordata: il pulsante è acceso e gli estremi ci sono; tornando su «Assolta» ci sono ancora, e la richiesta li porta; con un altro stato no', async () => {
        await apri(opzioni(ricordata({})));
        expect(accesi()).toEqual(['Assolta: virtuale']);
        expect((estremi()!.querySelector('input') as HTMLInputElement).value).toBe('aut. 1');

        await scegli('non_dovuta');
        expect(estremi()).toBeNull();
        await scegli('assolta_virtuale');
        expect((estremi()!.querySelector('input') as HTMLInputElement).value).toBe('aut. 1');

        expect(await invia()).toMatchObject({ bollo: 'assolta', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1' });
    });

    test('con «Non dovuta» scelta la richiesta non porta il modo né gli estremi, anche se sono ricordati', async () => {
        await apri(opzioni(ricordata({})));
        await scegli('non_dovuta');

        expect(await invia()).toMatchObject({ bollo: 'non_dovuta', modo_bollo: null, estremi_bollo: null });
    });

    test('gli estremi scritti partono con la richiesta', async () => {
        await apri(opzioni());
        await scegli('assolta_contrassegno');
        const campo = estremi()!.querySelector('input') as HTMLInputElement;
        campo.value = '0117';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(await invia()).toMatchObject({ bollo: 'assolta', modo_bollo: 'contrassegno', estremi_bollo: '0117' });
    });

    test('«Assolta» ricordata senza il modo non accende nessun pulsante e non vale come scelta: «Stampa» resta fermo', async () => {
        await apri(opzioni(ricordata({ modo_bollo: null })));

        expect(accesi()).toEqual([]);
        expect(estremi()).toBeNull();
        expect(pulsante('Stampa')!.disabled).toBe(true);
    });

    test('sotto la soglia i pulsanti sono gli stessi, senza il dato a destra; la scelta ricordata per le ricevute sotto la soglia è quella accesa', async () => {
        await apri(opzioni({ sopra_soglia: false, importo: 5000, ...ricordata({ bollo_sopra: 'assolta', bollo_sotto: 'da_assolvere', modo_bollo: null }) }));

        expect(accesi()).toEqual(['Da assolvere']);
        expect(document.body.querySelector('[data-testid="scelta-bollo"]')!.firstElementChild!.children).toHaveLength(1);
    });
});

describe('un invio che il limite ferma', () => {
    test('un 429 si legge in italiano, e non come lo scrive il framework', async () => {
        await apri(opzioni({ scelta_ricordata: { bollo_sopra: 'assolta', bollo_sotto: null, modo_bollo: 'virtuale', estremi_bollo: null, stampa_causale: null } }));
        const altro = document.body.querySelector('#altro-indirizzo') as HTMLInputElement;
        altro.value = 'chiunque@esterno.example';
        altro.dispatchEvent(new Event('input'));
        await flushPromises();
        axiosPost.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });

        pulsante('Invia')!.click();
        await flushPromises();

        expect(testo()).toContain('Hai già mandato dieci ricevute a indirizzi scritti a mano nell\'ultimo minuto');
        expect(testo()).not.toContain('Too Many Attempts');
    });
});

describe('l\'incasso annullato partito a due primarie', () => {
    test('la finestra ha due caselle, una per indirizzo, e manda le chiavi di quelle spuntate', async () => {
        const due = { anagrafica_id: 1, nome: 'Anna Bruni', ruolo: 'posizione', indirizzi: [
            { tipo: 'email', indirizzo: 'vecchio@esempio.it', proposto: true, chiave: 'email|vecchio@esempio.it' },
            { tipo: 'email', indirizzo: 'nuovo@esempio.it', proposto: true, chiave: 'email|nuovo@esempio.it' },
        ] };
        await apri(opzioni({
            annullato: true, storno: { protocollo: 'RET-2026-00001', data: '2026-02-10' }, destinatari: [due], copia: { del: '2026-02-02', cambiata: false },
            emissioni: [{ id: 1, canale: 'email', stato: 'inviata', quando: '2026-02-02 10:00', destinatario: 'nuovo@esempio.it', errore: null }],
            scelta_ricordata: { bollo_sopra: 'non_dovuta', bollo_sotto: null, modo_bollo: null, estremi_bollo: null, stampa_causale: null },
        }));
        const caselle = () => [...document.body.querySelectorAll('[role="dialog"] button[role="checkbox"]')] as HTMLButtonElement[];
        expect(testo()).toContain('Primaria: vecchio@esempio.it');
        expect(testo()).toContain('Primaria: nuovo@esempio.it');
        expect(caselle()).toHaveLength(2);

        // Si toglie la casella del vecchio indirizzo: parte solo la chiave del nuovo.
        caselle()[0].click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 0, errori: [] } });
        pulsante('Invia')!.click();
        await flushPromises();

        expect(axiosPost.mock.calls.at(-1)![1].destinatari).toEqual([{ anagrafica_id: 1, tipi: ['email|nuovo@esempio.it'] }]);
    });
});

describe('il tema scuro', () => {
    test('nessun colore fisso chiaro senza la sua variante scura: la finestra, i suoi avvisi e i suoi riquadri seguono il tema', async () => {
        await apri(opzioni({
            annullato: true, storno: { protocollo: 'RET-2026-00001', data: '2026-02-10' }, copia: { del: '2026-02-02', cambiata: true }, senza_server_posta: true,
            emissioni: [{ id: 1, canale: 'email', stato: 'non_partita', quando: '2026-02-02 10:00', destinatario: 'anna@esempio.it', errore: 'Il server di posta non ha accettato il messaggio.' }],
        }));
        expect(senzaVarianteScura()).toEqual([]);

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ emettibile: false, motivo: 'Questo incasso copre le rate di più persone.', persone_dell_incasso: [{ id: 1, nome: 'Anna Bruni' }] }));
        expect(senzaVarianteScura()).toEqual([]);

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ sopra_soglia: true }));
        expect(senzaVarianteScura()).toEqual([]);
    });
});

describe('la tabella delle quote di più piani, e sul telefono', () => {
    test('un gruppo per piano, con il piano e la gestione una volta sola in testa; le cifre non vanno a capo, il resto sì', async () => {
        await apri(opzioni({
            quote: [
                { rata: 'Rata 1', piano: 'Preventivo 2026', gestione: 'Ordinaria 2026', unita: 'Int. 4', scadenza: '2026-01-31', importo: 15000, imputato: 15000, dal_credito: 0, dopo: 0 },
                { rata: 'Rata 1', piano: 'Conguaglio 2025', gestione: 'Ordinaria 2026', unita: 'Int. 4', scadenza: '2026-02-28', importo: 8000, imputato: 8000, dal_credito: 0, dopo: 0 },
            ],
        }));

        expect(righeDellaTabella()).toEqual([
            ['Preventivo 2026 — Ordinaria 2026'],
            ['Int. 4', 'Rata 1 31/01/2026', '€ 150,00', '€ 0,00'],
            ['Conguaglio 2025 — Ordinaria 2026'],
            ['Int. 4', 'Rata 1 28/02/2026', '€ 80,00', '€ 0,00'],
            ['Totale', '€ 230,00', '€ 0,00'],
        ]);
        // Sul telefono la tabella sta nella larghezza della finestra: niente larghezze fisse, le cifre su una riga, unità e rata vanno a capo.
        const tabella = document.body.querySelector('[data-testid="quote-del-versamento"]') as HTMLElement;
        expect(tabella.classList.contains('w-full')).toBe(true);
        expect(tabella.outerHTML).not.toMatch(/\b(min-)?w-\[\d+px\]/);
        const celle = [...tabella.querySelector('tbody')!.querySelectorAll('tr:nth-child(3) td')] as HTMLElement[];
        expect(celle.map((c) => c.classList.contains('whitespace-nowrap'))).toEqual([false, false, true, true]);
        expect(celle[0].classList.contains('break-words')).toBe(true);
    });

    test('i titoli delle colonne stanno sotto il nome del piano, in ogni gruppo, e la tabella non ha un thead sopra tutti', async () => {
        // Vincenzo, 05/11: «i titoli delle colonne … non dovrebbe essere sotto il testo Piano rate …?». Due piani: il nome, i titoli, le righe; poi l'altro.
        await apri(opzioni({ quote: [
            { ...opzioni().quote[0] },
            { ...opzioni().quote[0], piano: 'Conguaglio 2025', rata: 'Rata 1', scadenza: '2026-02-28', imputato: 8000, dopo: 0 },
        ] }));

        const tabella = document.body.querySelector('[data-testid="quote-del-versamento"]') as HTMLElement;
        expect(tabella.querySelector('thead')).toBeNull();
        const gruppi = [...tabella.querySelectorAll('tbody')].map((b) => [...b.querySelectorAll('tr')].map((r) => [...r.querySelectorAll('th, td')].map((c) => (c.textContent ?? '').trim()).join(' ')));
        const titoli = 'Unità Rata e scadenza Imputato Resta';
        expect(gruppi.map((g) => g.slice(0, 2))).toEqual([
            ['Preventivo 2026 — Ordinaria 2026', titoli],
            ['Conguaglio 2025 — Ordinaria 2026', titoli],
        ]);
        expect([...tabella.querySelectorAll('[data-titoli-colonne] th')].every((c) => c.getAttribute('scope') === 'col')).toBe(true);
    });

    test('una quota sola: la riga, senza il totale che la ripeterebbe', async () => {
        await apri(opzioni());

        expect(righeDellaTabella()).toEqual([['Preventivo 2026 — Ordinaria 2026'], ['Int. 4', 'Rata 1', '€ 150,00', '€ 0,00']]);
    });
});

describe('«Non serve comunicarla» nella finestra di un incasso annullato', () => {
    const annullatoUscito = (extra: Record<string, unknown> = {}) => opzioni({
        annullato: true, storno: { protocollo: 'RET-2026-00001', data: '2026-02-10' }, copia: { del: '2026-02-02', cambiata: false },
        emissioni: [{ id: 1, canale: 'stampa', stato: 'stampata', quando: '2026-02-02 10:00', destinatario: null, errore: null }],
        inviabile: false, destinatari: [], ...extra,
    });

    test('c\'è se la ricevuta era uscita; chiede una conferma con il numero, poi scrive la scelta, rilegge la finestra e avvisa la pagina', async () => {
        await apri(annullatoUscito());
        expect(pulsante('Non serve comunicarla')).toBeDefined();
        expect(axiosPost).not.toHaveBeenCalled();

        pulsante('Non serve comunicarla')!.click();
        await flushPromises();
        expect(testo()).toContain('L\'incasso n. INC-2026-00005 è annullato: segno che alla persona non serve comunicarlo.');
        expect(axiosPost).not.toHaveBeenCalled();

        axiosPost.mockResolvedValueOnce({ data: { segnata: true } });
        axiosGet.mockResolvedValueOnce({ data: annullatoUscito({ emissioni: [
            { id: 2, canale: 'nessuno', stato: 'non_da_comunicare', quando: '2026-02-11 09:00', destinatario: null, errore: null },
            { id: 1, canale: 'stampa', stato: 'stampata', quando: '2026-02-02 10:00', destinatario: null, errore: null },
        ] }) });
        pulsante('Sì, segna')!.click();
        await flushPromises();

        expect(axiosPost.mock.calls.at(-1)![0]).toBe('/gestionale.ricevute.non-serve-comunicarla');
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
        // Segnata: il pulsante non c'è più, e la riga si legge fra le emissioni.
        expect(pulsante('Non serve comunicarla')).toBeUndefined();
        expect(testo()).toContain('non serve comunicare l\'annullamento alla persona');
    });

    test('se la finestra passa a un altro incasso mentre la richiesta è in viaggio, la scelta è scritta sul suo incasso ma il messaggio non compare nell\'altra finestra', async () => {
        let quale = 5;
        axiosGet.mockImplementation(() => Promise.resolve({ data: annullatoUscito(quale === 5 ? {} : { id: 6, numero: 'INC-2026-00006' }) }));
        finestra = mount(RicevutaDialog, { attachTo: document.body, props: { open: false, condominioId: 3, incassoId: 5 } });
        await finestra.setProps({ open: true });
        await flushPromises();
        pulsante('Non serve comunicarla')!.click();
        await flushPromises();
        let finisce: (v: unknown) => void = () => {};
        axiosPost.mockImplementationOnce(() => new Promise((r) => { finisce = r; }));
        pulsante('Sì, segna')!.click();
        await flushPromises();

        quale = 6;
        await finestra.setProps({ open: false });
        await finestra.setProps({ incassoId: 6 });
        await finestra.setProps({ open: true });
        await flushPromises();
        finisce({ data: { segnata: true } });
        await flushPromises();

        expect(testo()).toContain('INC-2026-00006');
        expect(testo()).not.toContain('Segnata:');
        // La riga di Anna è scritta: la pagina ricarica l'avviso. La finestra dell'altro incasso non si rilegge per questo.
        expect(finestra.emitted('emessa')).toHaveLength(1);
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(pulsante('Non serve comunicarla')).toBeDefined();
    });

    test('anche per la ricevuta annullata che era partita per email', async () => {
        await apri(annullatoUscito({ inviabile: true, destinatari: [anna],
            emissioni: [{ id: 1, canale: 'email', stato: 'inviata', quando: '2026-02-02 10:00', destinatario: 'anna@esempio.it', errore: null }] }));

        expect(pulsante('Non serve comunicarla')).toBeDefined();
        pulsante('Non serve comunicarla')!.click();
        await flushPromises();
        pulsante('No')!.click();
        await flushPromises();
        expect(axiosPost).not.toHaveBeenCalled();
        expect(testo()).not.toContain('segno che alla persona non serve comunicarlo');
    });

    test('non c\'è se l\'incasso non è annullato, se la ricevuta non era mai uscita, o se è già segnata', async () => {
        await apri(opzioni({ copia: { del: '2026-02-02', cambiata: false } }));
        expect(pulsante('Non serve comunicarla')).toBeUndefined();

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(annullatoUscito({ copia: null, emissioni: [] }));
        expect(pulsante('Non serve comunicarla')).toBeUndefined();

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(annullatoUscito({ emissioni: [{ id: 2, canale: 'nessuno', stato: 'non_da_comunicare', quando: '2026-02-11 09:00', destinatario: null, errore: null }] }));
        expect(pulsante('Non serve comunicarla')).toBeUndefined();
    });
});

describe('«Prepara l\'email»', () => {
    const bozza = {
        oggetto: 'Condominio Via dei Pini — Ricevuta n. INC-2026-00005 del 01/02/2026', saluto: 'Buongiorno,', saluti: { 1: 'Gentile Anna Bruni,' },
        righe: ['In allegato la ricevuta del versamento di € 150,00 del 01/02/2026, numero INC-2026-00005.'], chiusura: 'Cordiali saluti, Condominio Via dei Pini',
    };
    const conDueIndirizzi = { ...anna, indirizzi: [
        { tipo: 'email', indirizzo: 'anna@esempio.it', proposto: true }, { tipo: 'email_secondaria', indirizzo: 'anna.casa@esempio.it', proposto: true },
    ] };
    const conBollo = { scelta_ricordata: { bollo_sopra: 'assolta', bollo_sotto: null, modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: true }, causale: 'Bonifico' };
    const pdf = { data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), headers: { 'content-disposition': 'inline; filename="ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf"' } };

    let clic: string[] = [];
    beforeEach(() => {
        clic = [];
        (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:ricevuta');
        (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clic.push(this.download ? `scarica ${this.download}` : `apri ${this.getAttribute('href')}`);
        });
    });
    afterEach(() => {
        delete (navigator as unknown as Record<string, unknown>).canShare;
        delete (navigator as unknown as Record<string, unknown>).share;
    });

    test('con un server di posta e nessun invio non partito non c\'è: la ricevuta si manda con «Invia»', async () => {
        await apri(opzioni({ bozza_email: bozza }));

        expect(pulsante('Prepara l\'email')).toBeUndefined();
    });

    test('c\'è senza un server di posta, e dopo un invio non partito; chiede il bollo, come la stampa', async () => {
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza }));
        expect(pulsante('Prepara l\'email')?.disabled).toBe(true);
        // Gli indirizzi si vedono, per scegliere a chi va l'email; «Invia» no.
        expect(testo()).toContain('Primaria: anna@esempio.it');
        expect(pulsante('Invia')?.disabled ?? true).toBe(true);

        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ bozza_email: bozza, ...conBollo, emissioni: [
            { id: 2, canale: 'email', stato: 'non_partita', quando: '2026-02-03 09:00', destinatario: 'anna@esempio.it', errore: 'Il server di posta non ha accettato il messaggio (codice 535).' },
        ] }));
        expect(pulsante('Prepara l\'email')?.disabled).toBe(false);

        // Un invio partito dopo quello non partito: non serve più.
        finestra?.unmount();
        document.body.innerHTML = '';
        await apri(opzioni({ bozza_email: bozza, ...conBollo, copia: { del: '2026-02-04', cambiata: false }, emissioni: [
            { id: 3, canale: 'email', stato: 'inviata', quando: '2026-02-04 09:00', destinatario: 'anna@esempio.it', errore: null },
            { id: 2, canale: 'email', stato: 'non_partita', quando: '2026-02-03 09:00', destinatario: 'anna@esempio.it', errore: 'x' },
        ] }));
        expect(pulsante('Prepara l\'email')).toBeUndefined();
    });

    test('senza la condivisione dei file: emette la ricevuta come una stampa con le scelte della finestra, scarica il PDF e apre l\'email', async () => {
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, destinatari: [conDueIndirizzi], ...conBollo }));
        axiosPost.mockResolvedValueOnce(pdf);

        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        const [url, campi, config] = axiosPost.mock.calls.at(-1)!;
        expect(url).toBe('/gestionale.ricevute.stampa');
        // Il PDF va per email: senza l'indirizzo per la busta (la stampa per la posta, invece, lo tiene).
        expect(campi).toMatchObject({ incassi: [5], per_email: 1, bollo: 'assolta', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: 1 });
        expect(config).toEqual({ responseType: 'blob' });
        expect(clic[0]).toBe('scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        expect(clic[1]).toMatch(/^apri mailto:anna@esempio\.it,anna\.casa@esempio\.it\?subject=Condominio%20Via%20dei%20Pini%20%E2%80%94%20Ricevuta/);
        expect(decodeURIComponent(clic[1].split('body=')[1])).toBe('Gentile Anna Bruni,\r\n\r\nIn allegato la ricevuta del versamento di € 150,00 del 01/02/2026, numero INC-2026-00005.\r\n\r\nCordiali saluti, Condominio Via dei Pini');
        // Se l'email non si è aperta, c'è il collegamento; la finestra si rilegge (la ricevuta ora è uscita) e la pagina lo sa.
        const collegamento = [...document.body.querySelectorAll('a')].find((a) => (a.textContent ?? '').includes('Apri l\'email'));
        expect(collegamento?.getAttribute('href')).toMatch(/^mailto:anna@esempio\.it/);
        expect(testo()).toContain('Allega il PDF scaricato');
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
    });

    test('dove il browser condivide i file apre la condivisione con il PDF allegato: non scarica e non apre l\'email', async () => {
        const condivisi: Array<{ files: File[]; title: string; text: string }> = [];
        Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true });
        Object.defineProperty(navigator, 'share', { value: async (d: { files: File[]; title: string; text: string }) => { condivisi.push(d); }, configurable: true });
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }));
        axiosPost.mockResolvedValueOnce(pdf);

        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        expect(condivisi).toHaveLength(1);
        expect(condivisi[0].files[0].name).toBe('ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        expect(condivisi[0].title).toBe(bozza.oggetto);
        expect(clic).toEqual([]);
        expect(testo()).toContain('anna@esempio.it');
    });

    test('se il server rifiuta, la finestra dice perché, e non scarica niente', async () => {
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }));
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: new Blob(['Ricevuta non stampata. Questo incasso copre le rate di più persone.'], { type: 'text/plain' }) } });

        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        expect(testo()).toContain('Ricevuta non stampata. Questo incasso copre le rate di più persone.');
        expect(clic).toEqual([]);
    });

    // La finestra è una sola per tutte le righe della pagina delle ricevute: mentre il PDF si prepara si può chiuderla e aprirne un'altra.
    const ugo = { anagrafica_id: 2, nome: 'Ugo Neri', ruolo: 'posizione', indirizzi: [{ tipo: 'email', indirizzo: 'ugo@esempio.it', proposto: true }] };
    const opzioniDiUgo = () => opzioni({
        id: 6, numero: 'INC-2026-00006', posizione: { id: 2, nome: 'Ugo Neri' }, destinatari: [ugo], senza_server_posta: true,
        bozza_email: { ...bozza, oggetto: 'Condominio Via dei Pini — Ricevuta n. INC-2026-00006 del 05/02/2026', saluti: { 2: 'Gentile Ugo Neri,' } },
        scelta_ricordata: conBollo.scelta_ricordata,
    });
    const pdfInViaggio = () => {
        let consegna: (v: unknown) => void = () => {};
        axiosPost.mockImplementationOnce(() => new Promise((r) => { consegna = r; }));

        return (risposta: unknown) => consegna(risposta);
    };

    test('chiusa la finestra mentre il PDF si prepara e aperta quella di un altro incasso, il PDF arrivato non si scarica e l\'email non si apre', async () => {
        const condivisi: unknown[] = [];
        Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true });
        Object.defineProperty(navigator, 'share', { value: async (d: unknown) => { condivisi.push(d); }, configurable: true });
        let quale = 5;
        axiosGet.mockImplementation(() => Promise.resolve({ data: quale === 5 ? opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }) : opzioniDiUgo() }));
        finestra = mount(RicevutaDialog, { attachTo: document.body, props: { open: false, condominioId: 3, incassoId: 5 } });
        await finestra.setProps({ open: true });
        await flushPromises();
        expect(testo()).toContain('anna@esempio.it');
        const arriva = pdfInViaggio();

        pulsante('Prepara l\'email')!.click();
        await flushPromises();
        quale = 6;
        await finestra.setProps({ open: false });
        await finestra.setProps({ incassoId: 6 });
        await finestra.setProps({ open: true });
        await flushPromises();
        expect(testo()).toContain('ugo@esempio.it');
        arriva(pdf);
        await flushPromises();

        // Niente file, niente email, niente condivisione; la finestra di Ugo non dice che un PDF è pronto né nomina quello di Anna.
        expect(clic).toEqual([]);
        expect(condivisi).toEqual([]);
        expect(testo()).not.toContain('Il PDF è pronto');
        expect(testo()).not.toContain('ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        expect([...document.body.querySelectorAll('a')].find((a) => (a.textContent ?? '').includes('Apri l\'email'))).toBeUndefined();
        // La ricevuta di Anna però è uscita (una riga nel registro): la pagina lo sa, perché ricarichi l'elenco.
        expect(finestra.emitted('emessa')).toHaveLength(1);
        // La finestra di Ugo è libera: «Prepara l'email» si può usare.
        expect(pulsante('Prepara l\'email')?.disabled).toBe(false);
    });

    test('chiusa la finestra mentre il PDF si prepara, il PDF arrivato non si scarica e l\'email non si apre', async () => {
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }));
        const arriva = pdfInViaggio();

        pulsante('Prepara l\'email')!.click();
        await flushPromises();
        await finestra!.setProps({ open: false });
        arriva(pdf);
        await flushPromises();

        expect(clic).toEqual([]);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
    });

    test('gli indirizzi, l\'oggetto e il saluto sono quelli di quando si è premuto «Prepara l\'email», anche se intanto si prova a cambiare le caselle', async () => {
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, destinatari: [conDueIndirizzi], ...conBollo }));
        const arriva = pdfInViaggio();

        pulsante('Prepara l\'email')!.click();
        await flushPromises();
        // Mentre il PDF si prepara si prova a togliere la spunta al secondo indirizzo: la casella è ferma.
        const casella = [...document.body.querySelectorAll('label')].find((l) => (l.textContent ?? '').includes('anna.casa@esempio.it'))?.querySelector('button[role="checkbox"]') as HTMLElement | null;
        expect(casella).not.toBeNull();
        casella!.click();
        await flushPromises();
        arriva(pdf);
        await flushPromises();

        expect(clic[1]).toMatch(/^apri mailto:anna@esempio\.it,anna\.casa@esempio\.it\?subject=/);
        expect(decodeURIComponent(clic[1].split('body=')[1])).toContain('Gentile Anna Bruni,');
    });

    // Una finestra montata come la monta una pagina: `v-model:open`, e il genitore segue la finestra quando chi lavora la chiude.
    const montaComeUnaPagina = async (risposta: Record<string, unknown>) => {
        axiosGet.mockResolvedValue({ data: risposta });
        const w: VueWrapper = mount(RicevutaDialog, {
            attachTo: document.body,
            props: { open: false, condominioId: 3, incassoId: 5, 'onUpdate:open': (v: boolean) => { w.setProps({ open: v }); } },
        });
        finestra = w;
        await w.setProps({ open: true });
        await flushPromises();

        return w;
    };
    // Che cosa ne pensa la pagina: il valore che ha seguito la finestra.
    const apertaPerLaPagina = (w: VueWrapper) => (w.props() as { open: boolean }).open;
    const finestraAperta = () => document.body.querySelector('[role="dialog"]') !== null;
    const tentaDiChiudere = async () => {
        pulsante('Chiudi')!.click();
        (document.body.querySelector('[data-slot="dialog-close"]') as HTMLElement).click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        const sfondo = document.body.querySelector('[data-slot="dialog-overlay"]') as HTMLElement;
        sfondo.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        sfondo.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();
    };

    test('mentre il PDF si prepara la finestra non si chiude, né con «Chiudi» né con la X né con Esc né con un clic fuori, e lo dice; arrivato il PDF si scarica e la finestra si chiude come sempre', async () => {
        const w = await montaComeUnaPagina(opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }));
        const arriva = pdfInViaggio();
        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        await tentaDiChiudere();

        expect(apertaPerLaPagina(w)).toBe(true);
        expect(finestraAperta()).toBe(true);
        expect(pulsante('Chiudi')!.disabled).toBe(true);
        expect(testo()).toContain('Sto preparando il PDF');
        arriva(pdf);
        await flushPromises();

        // Il PDF arriva a chi lavora, nella finestra della sua ricevuta, e la finestra si chiude di nuovo.
        expect(clic[0]).toBe('scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        expect(clic[1]).toMatch(/^apri mailto:anna@esempio\.it\?subject=/);
        expect(testo()).toContain('Il PDF è pronto');
        expect(testo()).not.toContain('Sto preparando il PDF');
        expect(pulsante('Chiudi')!.disabled).toBe(false);
        pulsante('Chiudi')!.click();
        await flushPromises();
        expect(apertaPerLaPagina(w)).toBe(false);
        expect(finestraAperta()).toBe(false);
    });

    test('se il server rifiuta, la finestra si può chiudere di nuovo', async () => {
        const w = await montaComeUnaPagina(opzioni({ senza_server_posta: true, bozza_email: bozza, ...conBollo }));
        axiosPost.mockRejectedValueOnce({ response: { status: 500, data: new Blob(['x'], { type: 'text/plain' }) } });

        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        expect(pulsante('Chiudi')!.disabled).toBe(false);
        expect(testo()).not.toContain('Sto preparando il PDF');
        pulsante('Chiudi')!.click();
        await flushPromises();
        expect(apertaPerLaPagina(w)).toBe(false);
    });

    test('mentre il PDF si prepara le caselle degli indirizzi e «Un altro indirizzo» non si toccano: la finestra mostra gli indirizzi dell\'email, e li libera all\'arrivo del PDF', async () => {
        const carlo = { anagrafica_id: 7, nome: 'Carlo Bruni', ruolo: 'versato_da', indirizzi: [{ tipo: 'email', indirizzo: 'carlo@esempio.it', proposto: false }] };
        await apri(opzioni({ senza_server_posta: true, bozza_email: bozza, destinatari: [anna, carlo], versato_da: { id: 7, nome: 'Carlo Bruni' }, ...conBollo }));
        const casella = (indirizzo: string) => [...document.body.querySelectorAll('label')].find((l) => (l.textContent ?? '').includes(indirizzo))?.querySelector('button[role="checkbox"]') as HTMLButtonElement;
        const altro = () => document.body.querySelector('#altro-indirizzo') as HTMLInputElement;
        // Chi lavora spunta Carlo, che ha versato: la ricevuta va a tutti e due.
        casella('carlo@esempio.it').click();
        await flushPromises();
        expect(casella('carlo@esempio.it').getAttribute('aria-checked')).toBe('true');
        const arriva = pdfInViaggio();

        pulsante('Prepara l\'email')!.click();
        await flushPromises();

        expect(casella('anna@esempio.it').disabled).toBe(true);
        expect(casella('carlo@esempio.it').disabled).toBe(true);
        expect(altro().disabled).toBe(true);
        casella('carlo@esempio.it').click();
        await flushPromises();
        expect(casella('carlo@esempio.it').getAttribute('aria-checked')).toBe('true');
        arriva(pdf);
        await flushPromises();

        // La finestra e l'email dicono la stessa cosa: tutti e due gli indirizzi.
        expect(clic[1]).toMatch(/^apri mailto:anna@esempio\.it,carlo@esempio\.it\?subject=/);
        const collegamento = [...document.body.querySelectorAll('a')].find((a) => (a.textContent ?? '').includes('Apri l\'email'));
        expect(collegamento?.getAttribute('href')).toMatch(/^mailto:anna@esempio\.it,carlo@esempio\.it\?subject=/);
        expect(casella('carlo@esempio.it').disabled).toBe(false);
        expect(altro().disabled).toBe(false);
    });
});

describe('«Invia»: la finestra è una sola per tutte le righe della pagina', () => {
    const ugo = { anagrafica_id: 2, nome: 'Ugo Neri', ruolo: 'posizione', indirizzi: [{ tipo: 'email', indirizzo: 'ugo@esempio.it', proposto: true }] };
    const scelta = { scelta_ricordata: { bollo_sopra: 'non_dovuta', bollo_sotto: null, modo_bollo: null, estremi_bollo: null, stampa_causale: null } };
    const diAnna = () => opzioni({ ...scelta });
    const diUgo = () => opzioni({ id: 6, numero: 'INC-2026-00006', posizione: { id: 2, nome: 'Ugo Neri' }, destinatari: [ugo], ...scelta });
    const esito = { data: { inviate: 0, in_coda: 1, non_partite: 0, senza_indirizzo: 0, errori: [] } };

    /** La finestra di Anna con «Invia» premuto e la risposta ancora in viaggio. */
    async function invioInViaggio() {
        let quale = 5;
        axiosGet.mockImplementation(() => Promise.resolve({ data: quale === 5 ? diAnna() : diUgo() }));
        const w: VueWrapper = mount(RicevutaDialog, {
            attachTo: document.body,
            props: { open: false, condominioId: 3, incassoId: 5, 'onUpdate:open': (v: boolean) => { w.setProps({ open: v }); } },
        });
        finestra = w;
        await w.setProps({ open: true });
        await flushPromises();
        let consegna: (v: unknown) => void = () => {};
        let rifiuta: (e: unknown) => void = () => {};
        axiosPost.mockImplementationOnce(() => new Promise((ok, no) => { consegna = ok; rifiuta = no; }));
        pulsante('Invia')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toMatchObject({ destinatari: [{ anagrafica_id: 1 }] });

        // Chi lavora chiude la finestra di Anna e apre quella di Ugo prima della risposta.
        pulsante('Chiudi')!.click();
        await flushPromises();
        quale = 6;
        await w.setProps({ incassoId: 6, open: true });
        await flushPromises();
        expect(testo()).toContain('INC-2026-00006');

        return { w, consegna, rifiuta };
    }

    test('nella stessa finestra l\'esito si legge, la finestra si rilegge e la pagina lo sa', async () => {
        await apri(diAnna());
        axiosPost.mockResolvedValueOnce(esito);

        pulsante('Invia')!.click();
        await flushPromises();

        expect(testo()).toContain('In coda 1.');
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
    });

    test('l\'esito dell\'invio di Anna non compare nella finestra di Ugo, la cui ricevuta non è partita; la pagina però lo sa', async () => {
        const { w, consegna } = await invioInViaggio();

        consegna(esito);
        await flushPromises();

        expect(testo()).toContain('INC-2026-00006');
        expect(testo()).not.toContain('In coda 1');
        expect(w.emitted('emessa')).toHaveLength(1);
        // La finestra di Ugo non si rilegge per la risposta di Anna.
        expect(axiosGet).toHaveBeenCalledTimes(2);
    });

    test('nemmeno l\'errore dell\'invio di Anna compare nella finestra di Ugo', async () => {
        const { w, rifiuta } = await invioInViaggio();

        rifiuta({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        await flushPromises();

        expect(testo()).toContain('INC-2026-00006');
        expect(testo()).not.toContain('Hai già mandato dieci ricevute');
        expect(testo()).not.toContain('Invio non riuscito');
        expect(w.emitted('emessa')).toBeUndefined();
    });
});

describe('«Anteprima» e «Stampa»: la scheda nuova si apre nel clic, il PDF arriva in sottofondo', () => {
    const conBollo = { scelta_ricordata: { bollo_sopra: 'assolta', bollo_sotto: null, modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: true }, causale: 'Bonifico' };
    const pdf = { data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), headers: { 'content-disposition': 'inline; filename="ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf"' } };
    // Che cosa succede e in che ordine: la scheda che si apre, la richiesta che parte, il file scaricato.
    let fatti: string[] = [];
    let scheda: { closed: boolean; opener: unknown; location: { href: string }; document: Document; close: () => void } | null = null;
    let bloccata = false;

    beforeEach(() => {
        fatti = [];
        bloccata = false;
        scheda = null;
        (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:ricevuta');
        (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
        vi.spyOn(window, 'open').mockImplementation(() => {
            fatti.push('scheda');
            if (bloccata) return null;
            scheda = { closed: false, opener: window, location: { href: '' }, document: document.implementation.createHTMLDocument(''), close() { fatti.push('scheda chiusa'); this.closed = true; } };

            return scheda as unknown as Window;
        });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            fatti.push(this.download ? `scarica ${this.download}` : `apri ${this.getAttribute('href')}`);
        });
    });
    afterEach(() => {
        // Il controllo che libera l'indirizzo del PDF si ferma da sé quando la scheda si chiude.
        if (scheda) scheda.closed = true;
    });

    /** Il PDF chiesto e non ancora arrivato: la risposta la dà il test, quando vuole. */
    const pdfInViaggio = () => {
        let consegna: (v: unknown) => void = () => {};
        let rifiuta: (e: unknown) => void = () => {};
        axiosPost.mockImplementationOnce(() => { fatti.push('richiesta'); return new Promise((ok, no) => { consegna = ok; rifiuta = no; }); });

        return { arriva: (r: unknown) => consegna(r), fallisce: (e: unknown) => rifiuta(e) };
    };

    test('«Anteprima»: la scheda si apre nel clic, prima della richiesta; il PDF va alla rotta dell\'anteprima e nella scheda; niente si ricarica', async () => {
        await apri(opzioni());
        const viaggio = pdfInViaggio();

        pulsante('Anteprima')!.click();

        // Nel clic stesso, senza nessuna attesa: prima la scheda, poi la richiesta. La scheda si chiede senza «noopener», con cui il browser
        // renderebbe sempre null (e il PDF si scaricherebbe sempre).
        expect(fatti).toEqual(['scheda', 'richiesta']);
        expect(window.open).toHaveBeenCalledWith('', '_blank');
        expect(scheda!.opener).toBeNull();
        expect(scheda!.document.body.textContent).toContain('Sto preparando il PDF');
        const [url, campi, config] = axiosPost.mock.calls.at(-1)!;
        expect(url).toBe('/gestionale.ricevute.pdf-anteprima');
        expect(campi).toMatchObject({ incassi: [5] });
        expect(config).toEqual({ responseType: 'blob' });
        viaggio.arriva(pdf);
        await flushPromises();

        expect(scheda!.location.href).toBe('blob:ricevuta');
        // La scheda è aperta e mostra il PDF da quell'indirizzo: non si libera adesso.
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        // L'anteprima non scrive niente: la finestra non si rilegge, la pagina non ricarica, e non c'è niente da dire.
        expect(axiosGet).toHaveBeenCalledTimes(1);
        expect(finestra!.emitted('emessa')).toBeUndefined();
        expect(testo()).not.toContain('risulta stampata');
    });

    test('«Stampa»: la scheda prima della richiesta; la finestra si rilegge e la pagina ricarica quando il PDF è arrivato, non prima; la richiesta non chiede il PDF per email', async () => {
        await apri(opzioni(conBollo));
        const viaggio = pdfInViaggio();

        pulsante('Stampa')!.click();
        expect(fatti).toEqual(['scheda', 'richiesta']);
        expect(window.open).toHaveBeenCalledWith('', '_blank');
        const [url, campi, config] = axiosPost.mock.calls.at(-1)!;
        expect(url).toBe('/gestionale.ricevute.stampa');
        expect(campi).toMatchObject({ incassi: [5], bollo: 'assolta', modo_bollo: 'virtuale', estremi_bollo: 'aut. 1', stampa_causale: 1 });
        // La stampa per la posta tiene l'indirizzo per la busta.
        expect(Object.keys(campi)).not.toContain('per_email');
        expect(config).toEqual({ responseType: 'blob' });
        // Anche aspettando, finché il PDF non arriva la finestra non si rilegge e la pagina non sa niente.
        await new Promise((r) => setTimeout(r, 1600));
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledTimes(1);
        expect(finestra!.emitted('emessa')).toBeUndefined();

        viaggio.arriva(pdf);
        await flushPromises();

        expect(scheda!.location.href).toBe('blob:ricevuta');
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
        expect(testo()).toContain('Il PDF è aperto in una scheda nuova. La ricevuta risulta stampata.');
        expect(fatti).not.toContain('scheda chiusa');
    });

    test('se il browser non apre la scheda il PDF si scarica con il nome della risposta, e la finestra lo dice', async () => {
        bloccata = true;
        await apri(opzioni(conBollo));
        axiosPost.mockImplementationOnce(() => { fatti.push('richiesta'); return Promise.resolve(pdf); });

        pulsante('Stampa')!.click();
        await flushPromises();

        expect(fatti).toEqual(['scheda', 'richiesta', 'scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf']);
        // Il file può non essere ancora sul computer (il browser può chiedere dove salvarlo): la frase non dice «scaricato». La stampa è già nel registro.
        expect(testo()).toContain('La scheda nuova non si è aperta, o era già chiusa: il PDF si scarica con il nome «ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf». '
            + 'Se il browser chiede dove salvarlo, arriva quando rispondi. La ricevuta risulta già stampata, anche se il file arriva dopo.');
        expect(testo()).not.toContain('scaricato come');
        expect(finestra!.emitted('emessa')).toHaveLength(1);
    });

    test('se la scheda è stata chiusa prima che il PDF arrivasse, il PDF si scarica', async () => {
        await apri(opzioni());
        const viaggio = pdfInViaggio();

        pulsante('Anteprima')!.click();
        scheda!.closed = true;
        viaggio.arriva({ ...pdf, headers: { 'content-disposition': 'inline; filename="anteprima-ricevuta-inc-2026-00005.pdf"' } });
        await flushPromises();

        expect(fatti).toEqual(['scheda', 'richiesta', 'scarica anteprima-ricevuta-inc-2026-00005.pdf']);
        expect(scheda!.location.href).toBe('');
        expect(testo()).toContain('il PDF si scarica con il nome «anteprima-ricevuta-inc-2026-00005.pdf»');
        expect(testo()).not.toContain('stampata');
    });

    test.each([
        ['un rifiuto del server (422)', { response: { status: 422, data: new Blob(['Ricevuta non stampata. Scegli lo stato dell\'imposta di bollo.'], { type: 'text/plain' }) } }, 'Ricevuta non stampata. Scegli lo stato dell\'imposta di bollo.'],
        ['la sessione scaduta (419)', { response: { status: 419, data: new Blob(['{"message":"CSRF token mismatch."}'], { type: 'application/json' }) } }, 'La sessione è scaduta: ricarica la pagina e, se serve, accedi di nuovo.'],
        ['un errore del server (500)', { response: { status: 500, data: new Blob(['{"message":"Server Error"}'], { type: 'application/json' }) } }, 'Non è stato possibile stampare la ricevuta. Riprova.'],
        ['la rete', new Error('Network Error'), 'Non è stato possibile stampare la ricevuta. Riprova.'],
    ])('%s: la scheda si chiude, la finestra dice perché, e niente si ricarica', async (_caso, errore, frase) => {
        await apri(opzioni(conBollo));
        const viaggio = pdfInViaggio();

        pulsante('Stampa')!.click();
        viaggio.fallisce(errore);
        await flushPromises();

        expect(fatti).toEqual(['scheda', 'richiesta', 'scheda chiusa']);
        expect(scheda!.closed).toBe(true);
        expect(testo()).toContain(frase);
        expect(testo()).not.toContain('CSRF');
        expect(testo()).not.toContain('Server Error');
        expect(axiosGet).toHaveBeenCalledTimes(1);
        expect(finestra!.emitted('emessa')).toBeUndefined();
        // Si può riprovare.
        expect(pulsante('Stampa')!.disabled).toBe(false);
    });

    test('un secondo clic mentre il PDF si prepara non apre un\'altra scheda e non chiede un altro PDF: per la stampa sarebbe una seconda riga nel registro', async () => {
        await apri(opzioni(conBollo));
        const viaggio = pdfInViaggio();

        // Due clic di fila, prima che la finestra si ridisegni.
        pulsante('Stampa')!.click();
        pulsante('Stampa')!.click();
        pulsante('Anteprima')!.click();
        await flushPromises();

        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(window.open).toHaveBeenCalledTimes(1);
        expect(pulsante('Stampa')!.disabled).toBe(true);
        expect(pulsante('Anteprima')!.disabled).toBe(true);
        viaggio.arriva(pdf);
        await flushPromises();

        expect(axiosPost).toHaveBeenCalledTimes(1);
        expect(finestra!.emitted('emessa')).toHaveLength(1);
        expect(pulsante('Stampa')!.disabled).toBe(false);
    });
});

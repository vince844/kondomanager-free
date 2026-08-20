// @vitest-environment jsdom

/**
 * beta.48 — Il totale dei millesimi a video, mentre si digita.
 *
 * Questa pagina non aveva **nessun** totale: si compilava riga per riga e un refuso si scopriva
 * al primo riparto, o mai. «Mai» è il caso normale, perché il motore normalizza su
 * `valore / sommaValori`: una tabella che somma a 900 ripartisce comunque il 100% della spesa,
 * facendola pagare agli altri, e nessun controllo contabile ha niente da segnalare.
 *
 * ## Metà di questi test verificano ciò che la pagina NON deve dire
 *
 * Il totale **non giudica**: niente confronto con 1000, niente verde, niente «mancano N». Sui
 * dati veri nove tabelle su quindici non sommano a 1000 e sono tutte corrette — parziali, a
 * parti uguali, o arrotondate dal tecnico e approvate così in assemblea.
 *
 * Non è una svista da riempire al prossimo giro: il testo di guida di `TabelleList.vue`
 * sosteneva che il sistema *«controlla in tempo reale che la somma sia esattamente 1000»*, era
 * falso, e fu corretto il 26/07/2026. Aggiungere un totale è esattamente l'occasione in cui
 * quella frase rientra dalla finestra, e questi test la tengono fuori.
 *
 * *(Un confronto con un totale dichiarato per tabella è stato progettato e messo da parte
 * l'11/08/2026 — il progetto resta in `docs/validatore_coerenza_millesimi.md`.)*
 *
 * ## Cosa questo file NON copre
 *
 * - Il salvataggio: il totale è un indicatore e non tocca il `submit`.
 * - Le varianti per tipo di tabella: **non esistono più dalla beta.50**. Le tabelle `acqua` e
 *   `riscaldamento` avevano cinque colonne in più — contatore, ultima lettura, quota fissa,
 *   quota variabile, coefficiente di dispersione — che si compilavano e si salvavano mentre
 *   nessun calcolo le leggeva. Il modulo ora è uno solo per tutti i tipi.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * `<Head>` pretende il contesto di una pagina Inertia vera, che qui non c'è e non serve. Non
 * basta elencarlo fra gli `stubs`: in `<script setup>` i componenti sono riferimenti risolti
 * nello scope del modulo, non nomi cercati in un registro — è la trappola già pagata nella
 * beta.36 e scritta in `flusso_di_lavoro_rilascio.md`.
 *
 * `useForm` resta autentico: è lui che tiene le righe su cui il totale si somma, ed è proprio
 * quel legame che questo file verifica.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

// `route()` di Ziggy va su globalThis: il componente lo chiama anche fuori dal template
// (breadcrumb e back-url), dove i `mocks` di Vue Test Utils non arrivano.
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const QuoteList = (await import('./QuoteList.vue')).default;

const CONDOMINIO = { id: 28, nome: 'Condominio Demo KM' };

function immobile(i: number) {
    return { id: i, nome: `Int ${i}`, interno: String(i), piano: '1', superficie: 80, palazzina: null, scala: null };
}

function monta(valori: number[], decimali = 2) {
    return mount(QuoteList, {
        props: {
            condominio: CONDOMINIO as never,
            tabella: {
                id: 74,
                condominio_id: 28,
                nome: 'Millesimi generali',
                tipo: 'standard',
                quota: 'millesimi',
                attiva: true,
                numero_decimali: decimali,
            } as never,
            millesimi: valori.map((v, i) => ({
                id: i + 1,
                immobile: immobile(i + 1),
                valore: String(v),
                coefficienti: null,
            })) as never,
            immobili: valori.map((_, i) => immobile(i + 1)) as never,
        },
        global: {
            mocks: { route: (name: string) => `/${name}` },
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' },
                // La guida in testa alla pagina contiene anch'essa la parola «millesimi»:
                // neutralizzarla evita che le asserzioni sul testo peschino lì dentro.
                PageHeaderGuide: { template: '<div />' },
                Link: { template: '<a><slot /></a>' },
                'v-select': true,
            },
        },
    });
}

describe('mostra la somma delle righe a schermo', () => {
    test('somma i valori e li stampa con i decimali dichiarati dalla tabella', () => {
        const w = monta([500, 300, 200]);

        // `1000.00` e non `1000`: il totale porta gli stessi decimali delle righe che somma,
        // e lo stesso separatore — altrimenti nella stessa colonna convivono due convenzioni.
        // Nessun raggruppamento delle migliaia, che qui sarebbe ambiguo con il decimale.
        expect(w.text()).toContain('Totale');
        expect(w.text()).toContain('1000.00');
    });

    test('le caselle mostrano i decimali della tabella, non i cinque della colonna', () => {
        // Dal database 500 arriva come `500.00000` — la colonna è `decimal(12,5)` per tutte le
        // tabelle, e quel cinque non c'entra con quanti decimali la tabella dichiara.
        const w = monta([500, 300, 200]);
        const caselle = w.findAll('input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle).toContain('500.00');
    });

    test('quello che si vede nella casella è quello che si salva', () => {
        // Il punto decimale è la forma che `UpdateQuoteRequest` valida (`numeric`): niente
        // conversione al `submit`, e nessuna ambiguità con il separatore delle migliaia.
        const w = monta([333.33, 300, 200]);
        const caselle = w.findAll('input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle).toContain('333.33');
        expect(caselle.join(' ')).not.toContain(',');
    });

    test('un valore più preciso della tabella si conserva per intero', () => {
        // ⚠️ **Questo test diceva l'opposto fino alla beta.60**, e la beta.61 ha cambiato la
        // regola chiudendo la coda ⑪: `numero_decimali` governa **come il valore si mostra**, mai
        // cosa si conserva. Prima `333.33333` su una tabella a due decimali diventava `333.33` —
        // e non solo a video: aprire la pagina e salvarla riscriveva il valore accorciato.
        //
        // Una tabella millesimale la redige un tecnico e la approva l'assemblea: il numero nel
        // programma dev'essere quello sulla carta. E nessun calcolo ha bisogno
        // dell'arrotondamento, perché il motore normalizza `valore / somma dei valori`.
        const w = monta([333.33333, 300, 200], 2);
        const caselle = w.findAll('input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle).toContain('333.33333');
    });

    test('i decimali dichiarati restano un minimo di leggibilità, non un tetto', () => {
        // L'altra metà della regola: un valore tondo si scrive comunque con i decimali della
        // tabella, così la colonna non diventa un elenco frastagliato. `500` su una tabella a due
        // resta `500.00`; `333.33333` sulla stessa tabella resta intero.
        const w = monta([333.33333, 300, 200], 2);
        const caselle = w.findAll('input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle).toContain('300.00');
        expect(caselle).toContain('200.00');
    });

    test('i valori a zero non partecipano, come nel motore', () => {
        const w = monta([500, 0, 300]);

        expect(w.text()).toContain('800.00');
    });

    test('non stampa la coda binaria di una somma di decimali', () => {
        // 333,33 × 3 = 999.9899999999999 in virgola mobile. Senza arrotondamento ai decimali
        // dichiarati finirebbe così sullo schermo.
        const w = monta([333.33, 333.33, 333.33], 2);

        expect(w.text()).not.toContain('999.9899');
        expect(w.text()).toContain('999.99');
    });

    test('rispetta i decimali dichiarati dalla tabella', () => {
        const w = monta([333.3333, 333.3333, 333.3334], 4);

        expect(w.text()).toContain('1000.0000');
    });
});

describe('la digitazione si ferma ai decimali dichiarati', () => {
    /** Digita nella casella di una riga come farebbe una persona, un evento `input` alla volta. */
    async function digita(w: ReturnType<typeof monta>, riga: number, testo: string) {
        const casella = w.findAll('input')[riga];
        await casella.setValue(testo);
        return (casella.element as HTMLInputElement).value;
    }

    test('il terzo decimale non entra su una tabella che ne dichiara due', async () => {
        // Tronca, non arrotonda: `500.345` resta `500.34`. Arrotondare mentre si digita
        // vorrebbe dire che battere una cifra ne cambia un'altra già scritta, e a schermo si
        // legge come un errore del programma.
        //
        // ⚠️ **Questo limite è sopravvissuto alla coda ⑪, e la distinzione conta.** Chiudendo
        // quella coda era stato alzato ai cinque decimali della colonna, ed era una correzione
        // eccessiva: battere è un atto deliberato della stessa persona che ha impostato
        // `numero_decimali`, e fermarla prima che il dato esista non toglie niente. Ciò che la
        // coda ⑪ ha tolto è l'arrotondamento **di un dato già scritto**, che è un'altra cosa —
        // vedi il test «un valore più preciso della tabella si conserva per intero».
        const w = monta([500, 300, 200], 2);

        expect(await digita(w, 0, '500.345')).toBe('500.34');
    });

    test('su una tabella a quattro decimali il quarto entra e il quinto no', async () => {
        const w = monta([500, 300, 200], 4);

        expect(await digita(w, 0, '333.33333')).toBe('333.3333');
    });

    test('con zero decimali il separatore non si può proprio scrivere', async () => {
        const w = monta([500, 300, 200], 0);

        expect(await digita(w, 0, '333.99')).toBe('33399');
    });

    test('un valore più fine già registrato si vede intero, anche se non si potrebbe più battere', async () => {
        // ⚠️ È il punto di incontro fra le due regole, e va presidiato perché sembra una
        // contraddizione e non lo è: la tabella dichiara due decimali, quindi da tastiera il terzo
        // non entra; ma il valore che c'è già — importato, o scritto quando la dichiarazione era
        // più alta — **si vede per intero e non viene toccato**. Chi ha bisogno di scriverne di
        // più fini alza l'impostazione della tabella.
        const w = monta([228.5002, 300, 200], 2);
        const caselle = w.findAll('tbody input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle[0]).toBe('228.5002');
        expect(await digita(w, 0, '228.5002')).toBe('228.50');
    });

    test('lettere e simboli non entrano, e il secondo separatore si ignora', async () => {
        const w = monta([500, 300, 200], 2);

        expect(await digita(w, 0, '12a.b3.7x')).toBe('12.37');
    });

    test('il separatore da solo resta, perché è uno stato legittimo di chi sta scrivendo', async () => {
        const w = monta([500, 300, 200], 2);

        // Bloccare `500.` costringerebbe a battere la cifra decimale prima del punto.
        expect(await digita(w, 0, '500.')).toBe('500.');
    });

    test('la virgola battuta per abitudine non viene rifiutata', async () => {
        const w = monta([500, 300, 200], 2);

        // Resta virgola mentre si digita; la raddrizza il normalizzatore all'uscita dal campo.
        expect(await digita(w, 0, '500,25')).toBe('500,25');
    });
});

describe('il totale non giudica', () => {
    test('una tabella che somma a 900 non viene segnalata', () => {
        const w = monta([500, 300, 100]);

        expect(w.text()).toContain('900.00');
        expect(w.text()).not.toContain('mancano');
        expect(w.text()).not.toContain('eccesso');
    });

    test('una tabella che somma a 1000 non viene lodata', () => {
        // Nessun verde, nessun «quadra»: 1000 non è più giusto di 1550 o di 16.
        const w = monta([500, 300, 200]);

        expect(w.text()).not.toContain('quadra');
    });

    test('non compare mai un 1000 che l\'amministratore non ha scritto', () => {
        // «SCALE» di Le Terrazze somma a 1550 ed è corretta: la pagina mostra 1550 e basta.
        const w = monta([1000, 550]);

        expect(w.text()).toContain('1550.00');
        expect(w.text()).not.toContain('/ 1000');
    });
});

/* ─────────────────────────────────────────────────────────────────────────────
 * beta.52 — Il tetto che non era un tetto.
 *
 * Segnalazione forum del 15/08/2026: un amministratore con 67 unità immobiliari si ferma a 40 e
 * chiede «perché questo limite così stringente?». Non c'era nessun limite: quel condominio aveva
 * 40 unità inserite in anagrafica, e questa pagina le associa soltanto, non le crea.
 *
 * Il difetto era doppio, e la seconda metà non è cosmetica: il messaggio mentiva sulla causa, e
 * la guardia contava **le righe** invece delle **unità ancora libere**.
 * ───────────────────────────────────────────────────────────────────────────── */

/** Come `monta()`, ma con più unità in anagrafica che righe già associate. */
function montaConLibere(nAssociate: number, nInAnagrafica: number, decimali = 2) {
    return mount(QuoteList, {
        props: {
            condominio: CONDOMINIO as never,
            tabella: {
                id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                quota: 'millesimi', attiva: true, numero_decimali: decimali,
            } as never,
            millesimi: Array.from({ length: nAssociate }, (_, i) => ({
                id: i + 1, immobile: immobile(i + 1), valore: '100', coefficienti: null,
            })) as never,
            immobili: Array.from({ length: nInAnagrafica }, (_, i) => immobile(i + 1)) as never,
        },
        global: {
            mocks: { route: (name: string) => `/${name}` },
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { template: '<div />' },
                Link: { template: '<a><slot /></a>' },
                'v-select': true,
            },
        },
    });
}

const bottoneAggiungi = (w: ReturnType<typeof monta>) =>
    w.findAll('button').find((b) => b.text().toLowerCase().includes('aggiungi immobile'));

describe('il limite è il numero di unità in anagrafica, e si vede prima di sbatterci contro', () => {
    test('il contatore dice quante unità restano da associare', () => {
        // 40 unità in anagrafica, 38 già associate: ne restano 2, e si leggono senza cliccare
        // niente. È la riga che avrebbe evitato la segnalazione.
        const w = montaConLibere(38, 40);

        expect(w.text()).toContain('2 da associare');
    });

    test('quando sono tutte associate lo dice, invece di aspettare il clic', () => {
        const w = montaConLibere(40, 40);

        expect(w.text()).toContain('Tutte associate');
    });

    test('UNA RIGA VUOTA NON CONSUMA UN\'UNITÀ — è la regressione da cui nasce questa beta', async () => {
        // La guardia vecchia confrontava `form.quote.length` con `rawImmobili.length`: con 3 unità
        // in anagrafica e 2 associate, la riga vuota appena aggiunta portava le righe a 3 e
        // bloccava l'aggiunta **mentre un'unità era ancora libera**.
        //
        // Il conteggio giusto non è «quante righe ho», è «c'è ancora un'unità da associare».
        const w = montaConLibere(2, 3);

        await bottoneAggiungi(w)?.trigger('click');   // riga vuota: le righe diventano 3

        // Resta una sola unità libera, e la riga vuota non l'ha consumata.
        expect(w.text()).toContain('1 da associare');

        // Con la guardia vecchia (righe ≥ unità) qui l'avviso sarebbe già scattato.
        await bottoneAggiungi(w)?.trigger('click');
        expect(w.text().toLowerCase()).not.toContain('numero massimo');
    });

    test('il dialogo si raggiunge, e non parla di righe né di massimi consentiti', async () => {
        // Il pulsante resta attivo anche a zero **di proposito**: è così che l'amministratore
        // arriva alla spiegazione e al collegamento verso l'anagrafica. Un pulsante spento
        // renderebbe irraggiungibile il messaggio, cioè proprio ciò che mancava.
        const w = montaConLibere(3, 3);

        await bottoneAggiungi(w)?.trigger('click');

        expect(w.text().toLowerCase()).not.toContain('numero massimo');
        expect(w.text().toLowerCase()).not.toContain('righe consentite');
    });
});

/**
 * beta.61 — Ricerca e ordinamento sulla **vista**, mai sull'elenco.
 *
 * ⚠️ Il vincolo che regge tutto il blocco, e la ragione per cui questi test esistono: questa
 * pagina è un modulo unico. `form.quote` parte tutto insieme con un `put`, e a server il
 * salvataggio comincia con `whereNotIn('id', $idsPresenti)->delete()`. Se il filtro agisse
 * sull'array, le righe nascoste **uscirebbero dalla richiesta** e il server le leggerebbe come
 * cancellate: cercare un'unità e premere «Salva» distruggerebbe tutte le altre.
 *
 * Il primo test di questo blocco è quello che presidia il difetto peggiore che questa beta
 * poteva introdurre.
 */
describe('il filtro nasconde, non toglie', () => {
    const casella = (w: ReturnType<typeof monta>) =>
        w.findAll('input').find((i) => (i.element as HTMLInputElement).type === 'search');

    function montaMolte(n: number, nInAnagrafica = n) {
        return mount(QuoteList, {
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: Array.from({ length: n }, (_, i) => ({
                    id: i + 1, immobile: immobile(i + 1), valore: String(i + 1), coefficienti: null,
                })) as never,
                immobili: Array.from({ length: nInAnagrafica }, (_, i) => immobile(i + 1)) as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    test('cercando, le righe nascoste restano nel modulo che si salva', async () => {
        const w = montaMolte(12);

        expect((w.vm as never as { form: { quote: unknown[] } }).form.quote).toHaveLength(12);

        await casella(w)?.setValue('Int 7');

        // A video ne resta una sola…
        expect(w.findAll('tbody tr')).toHaveLength(1);

        // …ma l'array che parte con il `put` ne ha ancora dodici. È tutto il punto.
        expect((w.vm as never as { form: { quote: unknown[] } }).form.quote).toHaveLength(12);
    });

    test('dice quante righe sta nascondendo, invece di lasciarlo indovinare', async () => {
        const w = montaMolte(12);

        await casella(w)?.setValue('Int 7');

        expect(w.text()).toContain('11');
        expect(w.text().toLowerCase()).toContain('nascoste dalla ricerca');
    });

    test("l'ordinamento cambia l'ordine a video, non quello dell'array", async () => {
        const w = montaMolte(12);
        const primaDelClic = (w.vm as never as { form: { quote: { valore: string }[] } })
            .form.quote.map((q) => q.valore);

        const intestazione = w.findAll('thead button').find((b) => b.text().includes('Millesimi'));
        await intestazione?.trigger('click');   // crescente
        await intestazione?.trigger('click');   // decrescente

        const dopoIlClic = (w.vm as never as { form: { quote: { valore: string }[] } })
            .form.quote.map((q) => q.valore);

        expect(dopoIlClic).toEqual(primaDelClic);
    });

    test('una riga senza unità non si nasconde mai, e sta in fondo', async () => {
        // È la riga che l'amministratore ha appena creato: farla sparire perché non corrisponde a
        // una ricerca scritta prima è il modo più rapido di far credere che il pulsante non
        // funzioni.
        const w = montaMolte(12, 14);

        await bottoneAggiungi(w)?.trigger('click');
        await casella(w)?.setValue('Int 7');

        // La riga trovata più quella vuota.
        expect(w.findAll('tbody tr')).toHaveLength(2);
    });
});

describe('il piè di pagina conta le unità, non le righe', () => {
    test('una riga vuota non viene contata come unità associata', async () => {
        // ⚠️ Contava `form.quote.length`: la stessa schermata poteva dire «70 unità associate» in
        // fondo e «70 da associare» in cima.
        const w = montaConLibere(2, 5);

        await bottoneAggiungi(w)?.trigger('click');

        expect(w.text()).toContain('2 unità associate');
        expect(w.text()).not.toContain('3 unità associate');
    });

    test('le righe ancora senza millesimi si dicono a parte', () => {
        const w = mount(QuoteList, {
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: [
                    { id: 1, immobile: immobile(1), valore: '500', coefficienti: null },
                    { id: 2, immobile: immobile(2), valore: null, coefficienti: null },
                ] as never,
                immobili: [immobile(1), immobile(2)] as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });

        expect(w.text()).toContain('2 unità associate');
        expect(w.text()).toContain('1');
        expect(w.text().toLowerCase()).toContain('da compilare');
    });
});

/**
 * beta.61, revisione avversariale — i due difetti che la Fase 1-bis ha trovato a suite verde.
 *
 * Il primo era **preesistente e reso pericoloso da questa beta**: il cestino non dichiarava
 * `type="button"`, quindi dentro il `<form>` valeva `submit`. Togliere una riga faceva partire il
 * salvataggio dell'intera tabella — e a server il salvataggio comincia con
 * `whereNotIn(...)->delete()`, quindi la cancellazione diventava definitiva senza che nessuno
 * avesse premuto «Salva quote». Fino alla .60 c'era un freno per caso: con il millesimo
 * obbligatorio, una tabella con una riga vuota faceva fallire quel salvataggio involontario.
 * Rendendo il valore facoltativo il freno è saltato.
 */
describe('nessun comando della riga salva la tabella di nascosto', () => {
    test('il cestino è un pulsante, non un «invia»', () => {
        const w = monta([500, 300, 200]);

        const cestini = w.findAll('tbody tr button').filter((b) => b.find('.lucide-trash2-icon').exists());

        expect(cestini.length).toBeGreaterThan(0);
        cestini.forEach((b) => expect((b.element as HTMLButtonElement).type).toBe('button'));
    });

    test('nessun pulsante dentro il modulo invia, tranne «Salva quote»', () => {
        // La regola generale, così il prossimo comando aggiunto alla pagina non rifà lo stesso
        // difetto: dentro un `<form>` un `<button>` senza tipo vale `submit`.
        const w = monta([500, 300, 200]);

        const invianti = w.findAll('button')
            .filter((b) => (b.element as HTMLButtonElement).type === 'submit')
            .map((b) => b.text().trim().toLowerCase());

        expect(invianti).toEqual(['salva quote']);
    });
});

describe('la ricerca non si può lasciare accesa senza comandi', () => {
    function montaFiltrabile(nAssociate: number, nInAnagrafica: number) {
        return mount(QuoteList, {
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: Array.from({ length: nAssociate }, (_, i) => ({
                    id: i + 1, immobile: immobile(i + 1), valore: '100', coefficienti: null,
                })) as never,
                immobili: Array.from({ length: nInAnagrafica }, (_, i) => immobile(i + 1)) as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    const casella = (w: ReturnType<typeof monta>) =>
        w.findAll('input').find((i) => (i.element as HTMLInputElement).type === 'search');

    test('scendendo sotto la soglia con un filtro acceso, la casella resta', async () => {
        // ⚠️ Il difetto: la casella compariva solo sopra le otto righe. Cancellando righe con la
        // ricerca accesa si scendeva sotto la soglia, la casella spariva, il filtro restava — e
        // restava un elenco vuoto senza nessun comando per spegnerlo.
        const w = montaFiltrabile(12, 12);

        // «Int 1» pesca Int 1, Int 10, Int 11 e Int 12: quattro righe visibili da cancellare
        // **senza mai spegnere il filtro**, che è il punto del test.
        await casella(w)?.setValue('Int 1');
        expect(casella(w)).toBeDefined();

        const cestini = () => w.findAll('tbody tr button').filter((b) => b.find('.lucide-trash2-icon').exists());
        while (cestini().length > 0) {
            await cestini()[0].trigger('click');
        }

        expect((w.vm as never as { form: { quote: unknown[] } }).form.quote.length).toBeLessThan(9);
        expect(casella(w), 'la casella deve restare finché sta filtrando').toBeDefined();
    });

    test('se il filtro non trova niente, l elenco lo dice e offre di annullarlo', async () => {
        const w = montaFiltrabile(12, 12);

        await casella(w)?.setValue('non esiste nessuna unità così');

        expect(w.text()).toContain('Nessuna unità corrisponde');
        expect(w.text().toLowerCase()).toContain('restano nel salvataggio');

        const annulla = w.findAll('button').find((b) => b.text().toLowerCase().includes('annulla la ricerca'));
        expect(annulla).toBeDefined();

        await annulla?.trigger('click');
        expect(w.findAll('tbody tr').length).toBe(12);
    });
});

describe('la ricerca non nasconde le cose che bloccano il salvataggio', () => {
    function montaFiltrabile(n: number) {
        return mount(QuoteList, {
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: Array.from({ length: n }, (_, i) => ({
                    id: i + 1, immobile: immobile(i + 1), valore: String((i + 1) * 10), coefficienti: null,
                })) as never,
                immobili: Array.from({ length: n }, (_, i) => immobile(i + 1)) as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    const casella = (w: ReturnType<typeof monta>) =>
        w.findAll('input').find((i) => (i.element as HTMLInputElement).type === 'search');

    test('un errore su una riga nascosta viene detto, invece di far sembrare rotto il pulsante', async () => {
        // ⚠️ Il difetto: l'unico posto in cui l'errore compare è dentro la riga, che con il filtro
        // attivo non è disegnata. Si premeva «Salva quote» e non succedeva niente.
        const w = montaFiltrabile(12);
        const vm = w.vm as never as { form: { setError: (e: Record<string, string>) => void } };

        await casella(w)?.setValue('Int 7');
        vm.form.setError({ 'quote.2.valore': 'Il valore millesimale deve essere numerico.' });
        await w.vm.$nextTick();

        expect(w.text().toLowerCase()).toContain('nascost');
        const mostraTutte = w.findAll('button').find((b) => b.text().toLowerCase().includes('mostra tutte'));
        expect(mostraTutte).toBeDefined();

        await mostraTutte?.trigger('click');
        expect(w.findAll('tbody tr').length).toBe(12);
    });

    test('senza errori nascosti non compare nessun avviso', async () => {
        // Il ramo innocente: un avviso che compare quando non serve si impara a ignorarlo.
        const w = montaFiltrabile(12);

        await casella(w)?.setValue('Int 7');

        expect(w.findAll('button').find((b) => b.text().toLowerCase().includes('mostra tutte'))).toBeUndefined();
    });
});

describe('ordinando per millesimi la riga non scappa da sotto le dita', () => {
    test('digitare un valore non riordina l elenco', async () => {
        // ⚠️ Il difetto: `righeVisibili` leggeva `q.valore` vivo, quindi ogni battuta rimetteva la
        // riga al suo nuovo posto e la casella perdeva il fuoco. Misurato: 10-20-30-40-50 con
        // «999» sulla prima diventava 2-3-4-5-1.
        const w = monta([10, 20, 30, 40, 50]);

        const intestazione = w.findAll('thead button').find((b) => b.text().includes('Millesimi'));
        await intestazione?.trigger('click');

        const nomiPrima = w.findAll('tbody tr').map((r) => r.text().split(' ').slice(0, 2).join(' '));

        const caselle = w.findAll('tbody input');
        await caselle[0].setValue('999');

        const nomiDopo = w.findAll('tbody tr').map((r) => r.text().split(' ').slice(0, 2).join(' '));

        expect(nomiDopo).toEqual(nomiPrima);
    });

    test('ricliccando l intestazione l ordine si aggiorna sui valori nuovi', async () => {
        // L'istantanea non deve diventare una prigione: riordinare resta possibile, con un gesto
        // voluto invece che a ogni tasto.
        const w = monta([10, 20, 30, 40, 50]);

        const intestazione = () => w.findAll('thead button').find((b) => b.text().includes('Millesimi'));
        await intestazione()?.trigger('click');

        await w.findAll('tbody input')[0].setValue('999');
        await intestazione()?.trigger('click');   // decrescente: rilegge i valori

        // Il valore sta nel campo, non nel testo della riga.
        const primaRiga = w.findAll('tbody tr')[0];
        // «999» e non «999.00»: la normalizzazione ai decimali scatta all'uscita dal campo, e qui
        // il campo non è stato lasciato.
        expect((primaRiga.find('input').element as HTMLInputElement).value).toBe('999');
        expect(primaRiga.text()).toContain('Int 1');
    });
});

describe('la casella non può sembrare vuota tenendo dentro qualcosa', () => {
    test('battendo lettere, casella e modello restano d accordo', async () => {
        // ⚠️ `Input.vue` usa `useVModel(..., { passive: true })`: con `v-model` l'emissione arrivava
        // **dopo** `onInputValore`, che quindi ripuliva la casella e si vedeva risovrascrivere il
        // modello col testo grezzo. Risultato: casella visibilmente vuota, modello con «abc», e il
        // contatore che continuava a dire «Tutte associate» — cioè il segnale «vuoto = da
        // compilare» falsificato dal lato client.
        const w = monta([500, 300]);

        const caselle = w.findAll('tbody input');
        await caselle[1].setValue('abc');

        const vm = w.vm as never as { form: { quote: { valore: string }[] } };

        expect((caselle[1].element as HTMLInputElement).value).toBe('');
        expect(vm.form.quote[1].valore, 'il modello deve dire quello che dice la casella').toBe('');
    });

    test('un testo non numerico non lascia la pagina a dire «Tutte associate»', async () => {
        const w = montaConLibere(2, 2);

        const caselle = w.findAll('tbody input');
        await caselle[0].setValue('abc');

        // La riga è ora senza valore, e la pagina deve dirlo invece di dichiararsi finita.
        expect(w.text()).toContain('da compilare');
    });
});

/**
 * beta.61 — Associare più unità insieme.
 *
 * Nasce da una segnalazione sul forum: con 67 unità, associarle una per una vuol dire 67 giri di
 * tendina. Arriva solo adesso perché prima non era possibile: fino alla .60 il millesimo era
 * obbligatorio, quindi una tabella riempita in blocco non si poteva più salvare finché non la si
 * compilava tutta.
 */
describe('associare più unità insieme', () => {
    /** Un'unità con palazzina e tipologia, per provare i raggruppamenti. */
    function unita(i: number, palazzina: string | null, tipologia: string | null) {
        return {
            id: i, nome: `Int ${i}`, interno: String(i), piano: '1', superficie: 80,
            palazzina: palazzina ? { name: palazzina } : null,
            scala: null,
            tipologia_immobile: tipologia ? { id: 1, nome: tipologia, categoria: 'unita_abitativa' } : null,
        };
    }

    // ⚠️ Montando su `document.body` i componenti si accumulano fra un test e l'altro, e le
    // interrogazioni sul documento peschebbero anche i pulsanti del test precedente: il primo
    // giro contava quattro unità mancanti dove ce n'erano tre.
    afterEach(() => { document.body.innerHTML = ''; });

    // ⚠️ `attachTo: document.body`: il dialogo si disegna in un **teleport**, fuori dalla radice
    // del componente, quindi `wrapper.text()` non lo vede. Le asserzioni sulla modale interrogano
    // il documento.
    function montaConGruppi(associate: number[], tutte: ReturnType<typeof unita>[]) {
        return mount(QuoteList, {
            attachTo: document.body,
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: associate.map((id, i) => ({
                    id: i + 1, immobile: tutte.find((u) => u.id === id), valore: '100', coefficienti: null,
                })) as never,
                immobili: tutte as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    const tutte = [
        unita(1, 'A', 'Abitazione'), unita(2, 'A', 'Abitazione'),
        unita(3, 'B', 'Box'), unita(4, 'B', 'Box'), unita(5, 'B', 'Cantina'),
    ];

    /** I pulsanti veri della pagina **e** della modale teleportata. */
    const bottoni = () => [...document.querySelectorAll('button')] as HTMLButtonElement[];
    const bottoneCon = (testo: string) =>
        bottoni().find((b) => (b.textContent ?? '').toLowerCase().includes(testo.toLowerCase()));

    const attendi = async (w: ReturnType<typeof monta>) => { await w.vm.$nextTick(); await w.vm.$nextTick(); };

    const apri = async (w: ReturnType<typeof monta>) => {
        bottoneCon('associa in blocco')?.click();
        await attendi(w);
    };

    const clicca = async (w: ReturnType<typeof monta>, testo: string) => {
        const b = bottoneCon(testo);
        expect(b, `pulsante «${testo}» non trovato`).toBeDefined();
        b?.click();
        await attendi(w);
    };

    test('offre solo i criteri che in questo condominio producono qualcosa', async () => {
        // ⚠️ Sui dati veri `scala_id` è valorizzato su **zero** unità su 42. Un elenco fisso di
        // criteri mostrerebbe una voce che non produce mai niente, e un criterio sempre vuoto
        // insegna a non fidarsi del menu.
        const w = montaConGruppi([1], tutte);
        await apri(w);

        const testo = (document.body.textContent ?? '').toLowerCase();
        expect(testo).toContain('tutte le unità mancanti');
        expect(testo).toContain('per palazzina');
        expect(testo).toContain('per tipologia');
        expect(testo, 'nessuna unità ha una scala: il criterio non deve comparire').not.toContain('per scala');
    });

    test('«tutte le mancanti» aggiunge solo quelle non ancora associate', async () => {
        // ⚠️ A server non c'è nessuna regola `distinct`: l'unica difesa contro il doppione è
        // l'indice unico `(tabella_id, immobile_id)`, che uscirebbe come pagina di errore.
        const w = montaConGruppi([1, 2], tutte);
        await apri(w);

        // Ora la modale mostra **quali** unità, e si conferma: «Abitazione 7» dice quante, non
        // quali, e su un criterio deciso dall'anagrafica è una differenza che conta.
        expect(bottoneCon('associa 3')).toBeDefined();
        await clicca(w, 'associa 3');

        const vm = w.vm as never as { form: { quote: { immobile: { id: number } | null }[] } };
        const ids = vm.form.quote.map((q) => q.immobile?.id);

        expect(vm.form.quote).toHaveLength(5);
        expect(new Set(ids).size, 'nessun doppione').toBe(5);
    });

    test('il filtro sui doppioni regge anche se lo si chiama con un elenco sporco', async () => {
        // ⚠️ Questo test è nato da un **controllo negativo fallito**: togliendo il filtro dal
        // codice, la suite restava verde. Il motivo è che dalla modale le unità arrivano già da
        // `immobiliDisponibili`, che le associate le ha tolte — quindi sui percorsi di oggi il
        // filtro non può scattare, e un test che passa dalla modale non lo prova.
        //
        // O si prova chiamando la funzione con un elenco che contiene un doppione, o il filtro è
        // una riga di cui nessuno sa se funziona. A server non c'è nessuna regola `distinct`:
        // l'unica difesa è l'indice unico `(tabella_id, immobile_id)`, che uscirebbe come pagina
        // di errore invece che come messaggio.
        const w = montaConGruppi([1, 2], tutte);
        const vm = w.vm as never as {
            associaInBlocco: (u: unknown[]) => void;
            form: { quote: { immobile: { id: number } | null }[] };
        };

        // Elenco sporco: due già associate più una nuova.
        vm.associaInBlocco([tutte[0], tutte[1], tutte[2]]);
        await w.vm.$nextTick();

        const ids = vm.form.quote.map((q) => q.immobile?.id);

        expect(vm.form.quote, 'solo la nuova deve entrare').toHaveLength(3);
        expect(new Set(ids).size).toBe(3);
        expect(ids).toContain(3);
    });

    test('un gruppo aggiunge solo le sue unità, e senza millesimo', async () => {
        const w = montaConGruppi([1], tutte);
        await apri(w);

        await clicca(w, 'per palazzina');

        // La palazzina B ha tre unità, nessuna associata.
        const gruppoB = bottoni().find((b) => (b.textContent ?? '').trim().startsWith('B'));
        expect(gruppoB?.textContent).toContain('3 da associare');
        gruppoB?.click();
        await attendi(w);

        // L'anteprima elenca le tre unità, e il pulsante dice quante ne entreranno.
        expect(document.body.textContent).toContain('Int 3');
        await clicca(w, 'associa 3');

        const vm = w.vm as never as { form: { quote: { immobile: { id: number } | null; valore: string }[] } };

        expect(vm.form.quote).toHaveLength(4);
        const nuove = vm.form.quote.slice(1);
        expect(nuove.map((q) => q.immobile?.id)).toEqual([3, 4, 5]);
        expect(nuove.every((q) => q.valore === ''), 'le righe nascono senza millesimo').toBe(true);
    });

    test('dopo l associazione in blocco la pagina non si dichiara finita', async () => {
        const w = montaConGruppi([1, 2], tutte);
        await apri(w);

        await clicca(w, 'associa 3');

        expect(w.text()).toContain('da compilare');
        expect(w.text(), 'tutte associate ma nessuna compilata non è «finita»').not.toContain('Tutte associate');
    });

    test('una ricerca attiva viene spenta, così le righe nuove si vedono', async () => {
        // Le righe appena create non corrispondono a un filtro scritto prima, e sparirebbero
        // nell'istante in cui ricevono la loro unità: si leggerebbe come un pulsante che non
        // funziona.
        const w = montaConGruppi([1, 2], [...tutte, ...Array.from({ length: 8 }, (_, i) => unita(10 + i, 'A', 'Abitazione'))]);

        const casella = w.findAll('input').find((i) => (i.element as HTMLInputElement).type === 'search');
        await casella?.setValue('Int 1');

        await apri(w);
        await clicca(w, 'associa');

        const dopo = w.findAll('input').find((i) => (i.element as HTMLInputElement).type === 'search');
        expect((dopo?.element as HTMLInputElement)?.value ?? '').toBe('');
    });
});

describe("l'anteprima mostra quali unità, non solo quante", () => {
    const bottoni = () => [...document.querySelectorAll('button')] as HTMLButtonElement[];
    const bottoneCon = (testo: string) =>
        bottoni().find((b) => (b.textContent ?? '').toLowerCase().includes(testo.toLowerCase()));

    afterEach(() => { document.body.innerHTML = ''; });

    function unita(i: number) {
        return {
            id: i, nome: `Int ${i}`, interno: String(i), piano: '1', superficie: 80,
            palazzina: { name: 'A' }, scala: null,
            tipologia_immobile: { id: 1, nome: 'Abitazione', categoria: 'unita_abitativa' },
        };
    }

    function monta5() {
        return mount(QuoteList, {
            attachTo: document.body,
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: [] as never,
                immobili: [1, 2, 3, 4, 5].map(unita) as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    test('elenca le unità per nome, e le nasce tutte spuntate', async () => {
        const w = monta5();
        bottoneCon('associa in blocco')?.click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        const testo = document.body.textContent ?? '';
        [1, 2, 3, 4, 5].forEach((i) => expect(testo, `manca «Int ${i}»`).toContain(`Int ${i}`));

        // Tutte spuntate: il caso normale resta un clic solo.
        expect(bottoneCon('associa 5')).toBeDefined();
    });

    test('togliendo una spunta il conto scende, e quella unità non entra', async () => {
        const w = monta5();
        bottoneCon('associa in blocco')?.click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        const caselle = [...document.querySelectorAll('[role="checkbox"], input[type="checkbox"]')] as HTMLElement[];
        expect(caselle.length, 'una casella per unità').toBe(5);

        caselle[0].click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        expect(bottoneCon('associa 4'), 'il conto deve scendere a 4').toBeDefined();

        bottoneCon('associa 4')?.click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        const vm = w.vm as never as { form: { quote: { immobile: { id: number } | null }[] } };
        const ids = vm.form.quote.map((q) => q.immobile?.id);

        expect(vm.form.quote).toHaveLength(4);
        expect(ids, "l'unità deselezionata non deve entrare").not.toContain(1);
    });

    test('senza nessuna spunta non si può confermare', async () => {
        const w = monta5();
        bottoneCon('associa in blocco')?.click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        bottoneCon('deseleziona tutte')?.click();
        await w.vm.$nextTick(); await w.vm.$nextTick();

        const conferma = bottoneCon('associa 0');
        expect((conferma as HTMLButtonElement)?.disabled).toBe(true);
    });
});

describe('la soglia della ricerca è un numero che anche la guida dichiara', () => {
    // ⚠️ La guida in-app scrive «da nove righe in su». Un numero scritto in prosa e non presidiato
    // diverge dal codice alla prima modifica, e questa pagina ne ha già pagato uno — il messaggio
    // che parlava di «numero massimo di righe consentite» quando nessun massimo esisteva.
    function montaN(n: number) {
        return mount(QuoteList, {
            props: {
                condominio: CONDOMINIO as never,
                tabella: {
                    id: 74, condominio_id: 28, nome: 'Millesimi generali', tipo: 'standard',
                    quota: 'millesimi', attiva: true, numero_decimali: 2,
                } as never,
                millesimi: Array.from({ length: n }, (_, i) => ({
                    id: i + 1, immobile: immobile(i + 1), valore: '10', coefficienti: null,
                })) as never,
                immobili: Array.from({ length: n }, (_, i) => immobile(i + 1)) as never,
            },
            global: {
                mocks: { route: (name: string) => `/${name}` },
                stubs: {
                    GestionaleLayout: { template: '<div><slot /></div>' },
                    PageHeaderGuide: { template: '<div />' },
                    Link: { template: '<a><slot /></a>' },
                    'v-select': true,
                },
            },
        });
    }

    const haLaRicerca = (w: ReturnType<typeof monta>) =>
        w.findAll('input').some((i) => (i.element as HTMLInputElement).type === 'search');

    test('con otto righe non compare', () => {
        expect(haLaRicerca(montaN(8))).toBe(false);
    });

    test('con nove righe compare', () => {
        expect(haLaRicerca(montaN(9))).toBe(true);
    });
});

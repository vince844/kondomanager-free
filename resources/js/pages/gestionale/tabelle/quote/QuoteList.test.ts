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

import { describe, expect, test, vi } from 'vitest';
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

    test('un valore più preciso della tabella viene portato alla precisione dichiarata', () => {
        // `333.33333` su una tabella a due decimali diventa `333.33`. Perde un pezzo, ed è il
        // prezzo dichiarato di avere una precisione unica per colonna: oggi il caso non esiste
        // sui dati (0 valori su 98 sono più precisi della loro tabella) e diventa raggiungibile
        // solo abbassando i decimali di una tabella già compilata.
        const w = monta([333.33333, 300, 200], 2);
        const caselle = w.findAll('input').map(i => (i.element as HTMLInputElement).value);

        expect(caselle).toContain('333.33');
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
        const w = monta([500, 300, 200], 2);

        // Tronca, non arrotonda: `500.345` resta `500.34`. Arrotondare mentre si digita
        // vorrebbe dire che battere una cifra ne cambia un'altra già scritta, e a schermo si
        // legge come un errore del programma.
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

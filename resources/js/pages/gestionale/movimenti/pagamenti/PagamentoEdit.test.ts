// @vitest-environment jsdom

/**
 * La schermata di MODIFICA di un pagamento fornitore, sul solo campo delle commissioni
 * bancarie.
 *
 * Il difetto: il form inizializzava il campo con `importo_commissione * 100`, ma la prop
 * arriva dalla `PagamentoFornitoreResource` ed è **già** in centesimi interi — la colonna
 * `importo_commissione` è dichiarata `bigInteger` con commento «Commissioni bancarie in
 * centesimi», il Model la casta a `integer` e la Resource la espone grezza. Il `* 100` era
 * quindi la seconda conversione della stessa cifra: il bug del ×100 della beta.32, in un
 * altro punto.
 *
 * Perché il test monta il componente invece di provare una funzione: l'inizializzazione
 * vive dentro `useForm({...})` nel `<script setup>`, e il valore sbagliato si vede solo
 * arrivando fino alla prop `modelValue` del `MoneyInput`. È lo stesso motivo per cui
 * `Confirm.test.ts` monta la sua schermata — vedi la nota in `vitest.config.ts`.
 *
 * L'altra metà del contratto — che la prop sia davvero in centesimi, e che un salvataggio
 * senza modifiche non muova la cifra a DB — è fissata da
 * `tests/Feature/Gestionale/PagamentoCommissioniRoundTripTest.php`. I due lati vanno letti
 * insieme: è la regola imparata nella beta.35, quando un numero calcolato in due
 * linguaggi è divergente senza che nessuna delle due parti sia sbagliata da sola.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * `<Head>` pretende il contesto di una pagina Inertia vera, che qui non esiste e non
 * serve: viene sostituito prima dell'import del componente. Tutto il resto del modulo
 * resta quello autentico — `useForm` in particolare, che è proprio ciò che si sta
 * mettendo alla prova.
 *
 * Non basta elencarlo negli `stubs`: in `<script setup>` i componenti sono riferimenti
 * risolti nello scope del modulo, non nomi che Vue cerca in un registro.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    // `usePermission()` legge i ruoli dalle props di pagina per scegliere il prefisso di
    // rotta (`admin.` o `user.`). Serve solo a costruire i link dell'intestazione.
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import PagamentoEdit from './PagamentoEdit.vue';

/**
 * Ziggy espone `route()` come globale. Il componente lo chiama dentro `<script setup>`
 * (i breadcrumb, il `back-url`), non solo nel template: deve quindi esistere davvero su
 * `globalThis`, e non basta passarlo fra i `mocks` del contesto di rendering.
 */
(globalThis as any).route = (name: string) => `/${name}`;

const mocks = { route: (name: string) => `/${name}` };

/**
 * La schermata monta l'intero kit UI, il layout del gestionale e `vue-select`. Qui
 * interessa un solo campo, quindi tutto il resto diventa uno stub trasparente.
 *
 * `MoneyInput` è l'unico stub che conserva qualcosa: il `modelValue` che riceve, perché è
 * esattamente il numero che l'amministratore si vede scritto nella casella.
 */
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    // `<Head>` di Inertia pretende il contesto di una pagina vera: qui non c'è, e non serve.
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: {
        name: 'MoneyInput',
        props: ['modelValue'],
        template: '<input :value="modelValue" />',
    },
};

/**
 * Un pagamento con 2,50 € di commissioni: 250 centesimi a DB, il caso della segnalazione.
 */
const pagamento = {
    id: 7,
    fornitore: { id: 3, ragione_sociale: 'Idraulica Rossi S.r.l.' },
    conto_corrente: { id: 11, nome: 'Banca Intesa', iban: 'IT60X0542811101000000123456' },
    data_pagamento: '2026-07-15',
    metodo_pagamento: 'bonifico',
    importo_commissione: 250,
    importo_lordo: 122_000,
    importo_ritenuta: 0,
    importo_netto: 122_000,
    bonifico_parlante: false,
};

function render(overrides: Record<string, unknown> = {}) {
    return mount(PagamentoEdit, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo KM' },
            condomini: [{ id: 1, nome: 'Condominio Demo KM' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            fornitori: [pagamento.fornitore],
            banche: [
                { id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 500_000 },
            ],
            pagamento: { ...pagamento, ...overrides },
        },
        global: { stubs, mocks },
    });
}

const commissioniInput = (wrapper: ReturnType<typeof render>) =>
    wrapper.getComponent<any>({ name: 'MoneyInput' });

describe('riapertura in modifica di un pagamento con commissioni bancarie', () => {
    /**
     * L'invariante: la casella deve mostrare gli euro che l'amministratore aveva digitato.
     * Col `* 100` mostrava 25000, cioè «€ 25.000,00» al posto di «€ 2,50».
     */
    test('la casella mostra 2,50 e non venticinquemila', () => {
        const wrapper = render();

        expect(commissioniInput(wrapper).props('modelValue')).toBe(2.5);
    });

    test('una commissione con i decimali non perde il centesimo', () => {
        const wrapper = render({ importo_commissione: 137 });

        expect(commissioniInput(wrapper).props('modelValue')).toBe(1.37);
    });

    test('un pagamento senza commissioni apre la casella a zero', () => {
        const wrapper = render({ importo_commissione: 0 });

        expect(commissioniInput(wrapper).props('modelValue')).toBe(0);
    });

    /**
     * Il giro completo, che è poi ciò che finiva a DB: quel che la casella mostra,
     * riconvertito in centesimi dal `transform()` del submit, deve tornare al valore di
     * partenza. Col difetto il giro dava 2.500.000 — la cifra iniziale moltiplicata per
     * diecimila — e non serviva toccare il campo perché accadesse.
     */
    test('senza toccare il campo, il valore che tornerebbe a DB è quello di partenza', () => {
        const wrapper = render();

        const mostrato = commissioniInput(wrapper).props('modelValue') as number;
        const rispedito = Math.round((Number(mostrato) || 0) * 100);

        expect(rispedito).toBe(pagamento.importo_commissione);
    });
});

/**
 * Il rifiuto che non si vedeva (beta.49, coda ⑮).
 *
 * ## Il difetto
 *
 * `handleSubmit` gestiva gli esiti del server con una catena di `if (errors.<chiave>)`, ciascuno
 * con la sua finestra, e in fondo un ripiego sul solo `errors.error`. Su undici chiavi che i
 * controller restituiscono, dieci avevano una finestra e **una no**: `ritenuta_incoerente`.
 * Quella tornava dal server, non entrava nella catena, non era `error`, e spariva.
 *
 * Il commento del controller su quella guardia dice *«non è bypassabile: la ritenuta discende
 * dalle fatture pagate»*. È una guardia giusta, su un dato fiscale — e l'amministratore non aveva
 * modo di sapere che era scattata: vedeva solo un pagamento che non si registrava.
 *
 * Verificando è emerso che il buco era più largo della singola chiave: questa schermata **non
 * mostra nessun errore accanto ai campi** (zero `form.errors` nel template), quindi anche le
 * validazioni ordinarie — fornitore mancante, data futura, allocazioni vuote — non avevano un
 * posto dove comparire.
 *
 * ## Perché il ripiego è per esclusione e non per inclusione
 *
 * Il difetto non era «manca un `if`»: era che l'elenco fosse **di inclusione**, cioè giusto il
 * giorno in cui è stato scritto e silenzioso alla prima chiave nuova. Ora si mostra tutto ciò che
 * non ha già una finestra propria. Sbagliando l'elenco si vede un messaggio due volte, invece di
 * non vederlo affatto: è il verso giusto in cui sbagliare.
 */
describe('il rifiuto senza finestra dedicata', () => {
    const motivi = (wrapper: ReturnType<typeof render>, errori: Record<string, string>) =>
        (wrapper.vm as any).motiviSenzaFinestra(errori);

    test('la ritenuta incoerente entra nel ripiego', () => {
        const wrapper = render();

        expect(motivi(wrapper, { ritenuta_incoerente: 'La ritenuta non corrisponde alle fatture.' }))
            .toEqual(['La ritenuta non corrisponde alle fatture.']);
    });

    test('anche una validazione ordinaria entra nel ripiego', () => {
        // Non era previsto da nessuno, ed è il caso più frequente dei due.
        const wrapper = render();

        expect(motivi(wrapper, { data_pagamento: 'La data non può essere futura.' }))
            .toEqual(['La data non può essere futura.']);
    });

    test('chi ha già la sua finestra non viene mostrato due volte', () => {
        const wrapper = render();

        expect(motivi(wrapper, {
            illegal_cash: 'Contante oltre soglia.',
            fiscal_year_closed: 'Esercizio chiuso.',
            modifica_vietata: 'Non modificabile.',
            error: 'Guasto.',
        })).toEqual([]);
    });

    test('una chiave che nessuno ha previsto viene mostrata lo stesso', () => {
        // ⚠️ La garanzia vera: `guardia_del_futuro` non esiste da nessuna parte. Se questo test
        // si rompe perché il ripiego è tornato a filtrare per inclusione, è tornato il silenzio.
        const wrapper = render();

        expect(motivi(wrapper, { guardia_del_futuro: 'Motivo che nessuno ha previsto.' }))
            .toEqual(['Motivo che nessuno ha previsto.']);
    });

    test('i motivi arrivano davvero a schermo', async () => {
        const wrapper = render();

        (wrapper.vm as any).rifiutoMotivi = ['La ritenuta non corrisponde alle fatture.'];
        (wrapper.vm as any).showRifiutoModal = true;
        await wrapper.vm.$nextTick();

        // `document.body` e non `wrapper.text()`: ogni finestra di questa schermata è dentro
        // un `<Teleport to="body">`, quindi il markup esce dall'albero del componente.
        expect(document.body.textContent).toContain('Modifica non registrata');
        expect(document.body.textContent).toContain('La ritenuta non corrisponde alle fatture.');
    });
});

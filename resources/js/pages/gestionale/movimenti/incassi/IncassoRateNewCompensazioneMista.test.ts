// @vitest-environment jsdom

/**
 * La schermata «Nuovo incasso rate», sul solo caso della rata che netta a zero perché
 * contiene insieme un debito e un credito.
 *
 * Da dove nasce quella riga: `SituazioneDebitoriaController` aggrega le quote per RATA e
 * pubblica il residuo NETTO del gruppo. Quando il netto è zero c'è un ramo esplicito,
 * commentato «Compensazione Mista» (:62-71), che TIENE la riga in lista apposta perché
 * l'amministratore la veda — mentre una rata davvero chiusa viene scartata. Il server, in
 * altre parole, conserva quella riga per un motivo.
 *
 * Il difetto: la pagina decide cosa mettere nella colonna dell'azione guardando lo stesso
 * numero netto. Residuo positivo dà la casella dell'importo, residuo negativo dà il
 * pulsante «Usa credito», residuo zero dà la scritta «N/D». Il credito che vive dentro il
 * gruppo resta quindi visibile — la riga porta perfino il badge «SALDO MISTO», che la
 * pagina calcola leggendo `dettaglio_quote` — ma non c'è niente da cliccarci sopra.
 *
 * Perché è un difetto e non una scelta prudente: quel credito è realmente spendibile. Il
 * motore non confronta mai la rata del credito con quella del debito
 * (`StoreIncassoRateAction:253-347`): dalla quota indicata risale alla rata di origine,
 * raccoglie lì tutte le quote del pagante con `credito_disponibile > 0` e le consuma per
 * chiudere debiti che stanno su ALTRE rate. I 250 € di questa Rata n.2 andrebbero benissimo
 * sulla Rata n.3; è l'interfaccia a non offrire la strada.
 *
 * Perché il test sta qui e non dietro un test PHP sul controller: il payload ha già tutto
 * il necessario, quindi chiedere al server campi nuovi vorrebbe dire fissare un contratto
 * che non serve. Ogni voce di `dettaglio_quote` porta `residuo` e `is_credito`, e l'importo
 * del credito è di lì leggibile; l'`id` della riga è l'id di una quota di quel gruppo, che
 * è l'unica cosa che il motore chiede per identificare la rata da cui prelevare. Il buco è
 * di sola interfaccia, e il test va scritto dove vive il buco.
 *
 * Montare il componente è d'obbligo: la decisione è una catena di `v-if` sul residuo dentro
 * il `<template>`, non una funzione che si possa interrogare da sola. È lo stesso motivo di
 * `PagamentoEdit.test.ts` — vedi la nota in `vitest.config.ts`.
 *
 * Il denaro qui è in EURO decimali, non in centesimi: la pagina formatta con
 * `useCurrencyFormatter({ fromCents: false })` e il controller converte già con
 * `MoneyHelper::fromCents` prima di spedire.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

/**
 * La lista che il server restituirebbe. Vive in `vi.hoisted` perché la fabbrica di
 * `vi.mock` viene issata sopra il corpo del modulo: una `const` normale sarebbe ancora
 * nella zona morta quando il mock viene costruito.
 */
const server = vi.hoisted(() => ({ rate: [] as any[] }));

// I debiti arrivano via `useDebitiLoader`, che è un `axios.get`. Mockare il trasporto e non
// il composable lascia in piedi la mappatura vera (`da_pagare: 0`, `selezionata: false`,
// `scaduta`), che è parte di ciò che il template poi legge.
vi.mock('axios', () => ({
    default: { get: vi.fn(async () => ({ data: { rate: server.rate } })) },
}));

/**
 * `<Head>` pretende il contesto di una pagina Inertia vera, che qui non esiste e non serve.
 * Non basta elencarlo negli `stubs`: in `<script setup>` i componenti sono riferimenti
 * risolti nello scope del modulo, non nomi che Vue cerca in un registro.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    // `usePermission()` legge i ruoli dalle props di pagina per scegliere il prefisso di
    // rotta (`admin.` o `user.`).
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import IncassoRateNew from './IncassoRateNew.vue';

/**
 * Ziggy espone `route()` come globale. Il componente lo chiama dentro `<script setup>`
 * (breadcrumb, `back-url`, URL dei debiti), non solo nel template.
 */
(globalThis as any).route = (name: string) => `/${name}`;

const mocks = { route: (name: string) => `/${name}` };

/**
 * Qui interessa una sola colonna di una sola riga: layout, intestazione e caselle di importo
 * diventano stub trasparenti. `PageHeaderGuide` va neutralizzato del tutto perché le sue
 * schede di aiuto contengono anch'esse la stringa «Usa credito».
 */
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const GESTIONE = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };

/**
 * Lo scenario: Mario Rossi possiede due unità. Sulla Rata n.2 una quota è a debito di 250 €
 * e l'altra è a credito di 250 € (uno strapagamento emerso), quindi la rata netta a zero ma
 * il credito esiste eccome. Sulla Rata n.3 resta un debito aperto di 400 €: è lì che quel
 * credito andrebbe a finire.
 */
const COMPENSAZIONE_MISTA = {
    id: 501, // l'id della PRIMA quota del gruppo: al motore basta per risalire alla rata
    rata_padre_id: 90,
    descrizione: 'Rata n.2',
    residuo: 0, // il NETTO: è questo numero che oggi manda la riga su «N/D»
    importo_totale: 250,
    data_scadenza: '2026-04-30',
    scadenza_human: '30/04/2026',
    gestione_id: GESTIONE.id,
    gestione: GESTIONE.nome,
    intestatario: 'Rossi Mario',
    intestatari_full: 'Rossi Mario',
    unita: 'Int. 1, Int. 2',
    tipologia: 'Aggregato',
    is_credito: false,
    is_emitted: true,
    is_published: true,
    dettaglio_quote: [
        {
            unita: 'Int. 1',
            anagrafica: 'Rossi Mario',
            anagrafica_id: 5,
            ruolo: 'P',
            residuo: 250,
            residuo_originale: 250,
            is_credito: false,
            componente_saldo: 0,
            componente_spesa: 250,
        },
        {
            unita: 'Int. 2',
            anagrafica: 'Rossi Mario',
            anagrafica_id: 5,
            ruolo: 'P',
            residuo: -250,
            residuo_originale: -250,
            is_credito: true,
            componente_saldo: -250,
            componente_spesa: 0,
        },
    ],
};

const DEBITO_APERTO = {
    id: 601,
    rata_padre_id: 91,
    descrizione: 'Rata n.3',
    residuo: 400,
    importo_totale: 400,
    data_scadenza: '2026-06-30',
    scadenza_human: '30/06/2026',
    gestione_id: GESTIONE.id,
    gestione: GESTIONE.nome,
    intestatario: 'Rossi Mario',
    intestatari_full: 'Rossi Mario',
    unita: 'Int. 1',
    tipologia: 'Aggregato',
    is_credito: false,
    is_emitted: true,
    is_published: true,
    dettaglio_quote: [
        {
            unita: 'Int. 1',
            anagrafica: 'Rossi Mario',
            anagrafica_id: 5,
            ruolo: 'P',
            residuo: 400,
            residuo_originale: 400,
            is_credito: false,
            componente_saldo: 0,
            componente_spesa: 400,
        },
    ],
};

/**
 * Monta la schermata con un pagante già scelto: la pagina carica i debiti solo dopo, e il
 * modo che il prodotto usa davvero per arrivarci è il link con `prefill_anagrafica_id`,
 * letto in `onMounted`. In jsdom la query string si imposta da `history`.
 */
async function apriConDebiti(rate: any[], query = '?prefill_anagrafica_id=5') {
    server.rate = rate;
    window.history.replaceState({}, '', `/gestionale/incassi/nuovo${query}`);

    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            risorse: [{ id: 2, nome: 'Banca Intesa', tipo: 'banca' }],
            condomini: [{ id: 5, nome: 'Rossi Mario' }],
            immobili: [{ id: 1, interno: '1', descrizione: 'Piano terra' }],
            gestioni: [GESTIONE],
        },
        global: { stubs, mocks },
    });

    // Il caricamento dei debiti è una promise, e il watcher che ne consegue si concede due
    // `nextTick` prima di ridistribuire: un solo giro non basta a vedere lo stato finale.
    await flushPromises();
    await flushPromises();

    return wrapper;
}

/** La riga della tabella che descrive quella rata. */
function riga(wrapper: any, descrizione: string) {
    const trovata = wrapper.findAll('tbody tr').find((tr: any) => tr.text().includes(descrizione));
    if (!trovata) throw new Error(`La riga «${descrizione}» non è in lista.`);
    return trovata;
}

/** L'ultima cella della riga è quella dell'azione: casella importo, pulsante, o «N/D». */
function cellaAzione(rigaTrovata: any) {
    const celle = rigaTrovata.findAll('td');
    return celle[celle.length - 1];
}

describe('la rata a compensazione mista nella schermata di incasso', () => {
    /**
     * Premessa verificata invece che data per buona, e insieme la prova che il dato c'è già:
     * la pagina sa che dentro quella riga convivono un debito e un credito, perché lo legge
     * da `dettaglio_quote`, e lo dichiara a schermo con un badge.
     */
    test('la pagina riconosce la riga come saldo misto', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        expect(riga(wrapper, 'Rata n.2').text()).toContain('SALDO MISTO');
    });

    /**
     * L'invariante che oggi non regge: se la riga contiene un credito, deve esistere un
     * comando per spenderlo. Non conta quale sia l'etichetta esatta — conta che ci sia
     * qualcosa di cliccabile, e che la colonna non si limiti a dire «N/D».
     *
     * Oggi l'amministratore legge un badge che gli annuncia un credito e a fianco una
     * casella vuota di significato. Il denaro c'è, il motore lo saprebbe usare sulla Rata
     * n.3, e l'unica via d'uscita rimasta è registrare l'incasso a mano da un'altra parte.
     */
    test('offre un comando per usare il credito nascosto nella compensazione', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        const comandi = cella.findAll('button').filter((b: any) => /credito/i.test(b.text()));

        expect(cella.text()).not.toContain('N/D');
        expect(comandi.length).toBeGreaterThan(0);
    });

    /**
     * Il contrappeso, perché la correzione non diventi «ogni riga a zero offre un credito»:
     * una rata davvero chiusa non ha niente da spendere, e lì «N/D» è la risposta giusta.
     *
     * Una riga così, in verità, il controller nemmeno la manda: la scarta a :68-70. La si
     * monta lo stesso perché la regola dell'interfaccia deve reggere da sola.
     */
    test('una rata chiusa senza crediti continua a non offrire nulla', async () => {
        const chiusa = {
            ...COMPENSAZIONE_MISTA,
            id: 701,
            rata_padre_id: 92,
            descrizione: 'Rata n.1',
            dettaglio_quote: COMPENSAZIONE_MISTA.dettaglio_quote.map((q) => ({
                ...q,
                residuo: 0,
                residuo_originale: 0,
                is_credito: false,
                componente_saldo: 0,
                componente_spesa: 0,
            })),
        };

        const wrapper = await apriConDebiti([chiusa, DEBITO_APERTO]);
        const cella = cellaAzione(riga(wrapper, 'Rata n.1'));

        expect(cella.text()).toContain('N/D');
        expect(cella.findAll('button')).toHaveLength(0);
    });
});

describe('il credito di un altro condòmino non si spende per sbaglio', () => {
    /**
     * Il caso che la beta.46 ha aperto senza accorgersene.
     *
     * Cercando per IMMOBILE, il server filtra su `immobile_id` e non sull'anagrafica
     * (`SituazioneDebitoriaController:39-45`): un gruppo-rata può quindi contenere le quote
     * di più comproprietari. Su una rata dove Bianchi è a credito e Rossi a debito il netto
     * torna zero, la riga è «SALDO MISTO», e il pulsante «Usa credito» — che prima di questa
     * versione non c'era — offrirebbe il credito di Bianchi mentre l'intestatario scelto è
     * Rossi.
     *
     * Cosa succede se ci si clicca sopra, verificato sul motore: il payload nomina una quota
     * di quella rata, e `StoreIncassoRateAction:265-271` cerca le quote a credito **del
     * pagante** su quella rata. Per Rossi non ce n'è. A quel punto l'esito dipende da quale
     * quota il database ha inserito per prima: o si finisce in
     * `RuntimeException('Quota credito non trovata per rata_id')` — che il controller non
     * cattura, quindi pagina 500 e distribuzione persa — oppure scatta il ramo
     * comproprietario di `:281-297` e il credito di Bianchi paga il debito di Rossi, senza
     * che la schermata lo dichiari da nessuna parte.
     *
     * Due esiti opposti dagli stessi identici clic, decisi dall'ordine di inserimento delle
     * righe. Il pulsante deve quindi guardare **di chi è** il credito, non solo che ci sia.
     */
    const MISTA_FRA_PERSONE = {
        ...COMPENSAZIONE_MISTA,
        intestatario: 'Rossi Mario, Bianchi Anna',
        intestatari_full: 'Rossi Mario, Bianchi Anna',
        dettaglio_quote: [
            {
                unita: 'Int. 1', anagrafica: 'Rossi Mario', anagrafica_id: 5, ruolo: 'P',
                residuo: 250, residuo_originale: 250, is_credito: false,
                componente_saldo: 0, componente_spesa: 250,
            },
            {
                // Il credito è di Bianchi, non di chi sta incassando.
                unita: 'Int. 1', anagrafica: 'Bianchi Anna', anagrafica_id: 9, ruolo: 'P',
                residuo: -250, residuo_originale: -250, is_credito: true,
                componente_saldo: -250, componente_spesa: 0,
            },
        ],
    };

    test('la riga mista fra due persone non offre il credito altrui', async () => {
        const wrapper = await apriConDebiti([MISTA_FRA_PERSONE, DEBITO_APERTO]);
        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));

        const comandi = cella.findAll('button').filter((b: any) => /credito/i.test(b.text()));

        expect(comandi.length).toBe(0);
        expect(cella.text()).toContain('N/D');
    });

    test('la riga mista della stessa persona continua a offrirlo', async () => {
        // La controprova: la correzione non deve richiudere il caso buono appena aperto.
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);
        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));

        const comandi = cella.findAll('button').filter((b: any) => /credito/i.test(b.text()));

        expect(comandi.length).toBeGreaterThan(0);
        expect(cella.text()).not.toContain('N/D');
    });
});

describe('quello che la schermata dichiara dopo il click', () => {
    /**
     * L'anteprima contabile è l'ultimo riquadro che l'amministratore legge prima di scrivere
     * in partita doppia, ed è proprio quello che il banner gli chiede di controllare
     * («verifica gli importi e conferma»).
     *
     * Il verso del movimento va dedotto dall'IMPORTO, non dal residuo della riga: su una riga
     * a saldo misto il residuo netto vale zero, quindi il ramo «credito» non scattava mai e
     * il prelievo usciva etichettato come pagamento parziale, con la scritta «Resta da
     * pagare» su una rata che invece sarebbe stata lasciata a saldo zero.
     */
    test('il prelievo di credito è descritto come credito, non come debito parziale', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        const anteprima = (wrapper.vm as any).previewContabile;
        const rigaCredito = anteprima.righe.find((r: any) => /Rata n\.2/.test(r.descrizione));

        expect(rigaCredito, 'La riga del credito deve comparire in anteprima.').toBeTruthy();
        expect(rigaCredito.pagato).toBeLessThan(0);
        expect(rigaCredito.isCredito).toBe(true);
        expect(rigaCredito.status).not.toBe('PARZIALE');
        expect(rigaCredito.residuo_futuro).toBe(0);
    });

    /**
     * Il caso archetipico: un condòmino la cui unica rata è a compensazione interna. Il
     * credito non ha dove andare — `creditoNecessario` conta come debiti solo le righe a
     * residuo positivo, e questa vale zero — quindi non viene impegnato niente.
     *
     * Il pulsante non deve dichiarare un'operazione che non è avvenuta: prima restava verde
     * con la spunta «Credito applicato» mentre «Conferma incasso» rimaneva spento, e l'unica
     * lettura possibile era «il programma si è bloccato».
     */
    test('senza niente da coprire il pulsante non dichiara di aver applicato il credito', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();
        await flushPromises();

        expect((wrapper.vm as any).form.dettaglio_pagamenti).toHaveLength(0);
        expect(
            cellaAzione(riga(wrapper, 'Rata n.2')).text(),
            'Niente è stato impegnato: il pulsante non può dire il contrario.',
        ).not.toContain('Credito applicato');
    });
});

describe('il credito offerto è sempre e solo quello di chi sta incassando', () => {
    /**
     * Le due facce dello stesso difetto, chiuse insieme.
     *
     * La guardia sul pagante era stata messa **dove si disegna il pulsante**, e copriva solo
     * le righe a saldo misto. Restavano fuori due strade, entrambe verso lo stesso posto —
     * `RuntimeException` non catturata (pagina 500, distribuzione persa) oppure il credito di
     * una persona speso sulla ricevuta di un'altra, senza che lo schermo lo dica:
     *
     * 1. **La riga a credito puro.** `creditoRiga` usciva con `Math.abs(netto)` prima ancora di
     *    guardare il pagante, quindi cercando per immobile il pulsante compariva su un credito
     *    altrui. È il percorso più comune, non un caso di frontiera.
     * 2. **L'allocazione già scritta.** Applicato il credito, bastava cambiare l'intestatario:
     *    il pulsante spariva ma `da_pagare` e `form.dettaglio_pagamenti` conservavano la riga
     *    negativa, che non era più deselezionabile perché il comando non c'era più.
     *
     * La guardia si sposta quindi dal disegno del pulsante alla **fonte**: quanto credito una
     * riga offre è sempre la somma delle quote a credito **del pagante**, che la riga sia mista
     * o pura. E un cambio di intestatario azzera quello che era stato allocato per un altro.
     */
    const CREDITO_PURO_DI_UN_ALTRO = {
        ...COMPENSAZIONE_MISTA,
        id: 777,
        rata_padre_id: 777,
        descrizione: 'Saldo pregresso',
        residuo: -250,
        importo_totale: -250,
        intestatario: 'Bianchi Anna',
        intestatari_full: 'Bianchi Anna',
        dettaglio_quote: [
            {
                unita: 'Int. 1', anagrafica: 'Bianchi Anna', anagrafica_id: 9, ruolo: 'P',
                residuo: -250, residuo_originale: -250, is_credito: true,
                componente_saldo: -250, componente_spesa: 0,
            },
        ],
    };

    test('una riga a credito puro intestata a un altro non offre niente', async () => {
        const wrapper = await apriConDebiti([CREDITO_PURO_DI_UN_ALTRO, DEBITO_APERTO]);
        const cella = cellaAzione(riga(wrapper, 'Saldo pregresso'));

        const comandi = cella.findAll('button').filter((b: any) => /credito/i.test(b.text()));

        expect(comandi.length, 'Il credito è di Bianchi, sta incassando Rossi.').toBe(0);
    });

    test('il credito proprio, anche su riga pura, continua a essere offerto', async () => {
        // Controprova: la guardia non deve chiudere il caso normale.
        const MIO = {
            ...CREDITO_PURO_DI_UN_ALTRO,
            intestatario: 'Rossi Mario',
            dettaglio_quote: [{ ...CREDITO_PURO_DI_UN_ALTRO.dettaglio_quote[0], anagrafica: 'Rossi Mario', anagrafica_id: 5 }],
        };

        const wrapper = await apriConDebiti([MIO, DEBITO_APERTO]);
        const cella = cellaAzione(riga(wrapper, 'Saldo pregresso'));

        expect(cella.findAll('button').filter((b: any) => /credito/i.test(b.text())).length).toBeGreaterThan(0);
    });

    test('cambiando intestatario non resta un\'allocazione fantasma nel payload', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        expect((wrapper.vm as any).form.dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(true);

        // L'amministratore si accorge che la ricevuta va intestata a un altro.
        (wrapper.vm as any).form.pagante_id = 9;
        await flushPromises();
        await flushPromises();

        const negative = (wrapper.vm as any).form.dettaglio_pagamenti.filter((p: any) => p.importo < 0);
        expect(
            negative,
            'Il credito era di chi incassava prima: non può restare impegnato per un altro.',
        ).toHaveLength(0);
    });
});

describe('«Usa credito» funziona anche in modalità manuale', () => {
    /**
     * `spalmaCredito` — e con essa la regola «selezionata solo se qualcosa è stato davvero
     * impegnato» — vive nel solo ramo automatico. In manuale, dove si finisce con «Resetta»,
     * «Paga tutto» o «Paga scadute», `toggleCredito` accendeva la selezione a mano e nessuno
     * la smentiva né allocava niente: pulsante verde «Credito applicato», payload vuoto,
     * «Conferma incasso» spento senza dire perché.
     */
    test('in manuale il click impegna davvero il credito', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        (wrapper.vm as any).mode = 'manual';
        await flushPromises();

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        const pagamenti = (wrapper.vm as any).form.dettaglio_pagamenti;
        const negative = pagamenti.filter((p: any) => p.importo < 0);
        const positive = pagamenti.filter((p: any) => p.importo > 0);

        expect(negative, 'Il click deve impegnare il credito, non solo colorare il pulsante.').toHaveLength(1);
        expect(negative[0].importo).toBe(-250);

        // E soprattutto: il credito deve PAGARE qualcosa. Senza una riga positiva nello stesso
        // payload il motore preleva il credito e lo riaccredita subito
        // (StoreIncassoRateAction:414-445): due scritture quadrate, nessun effetto, la rata
        // resta scoperta e l'amministratore crede di aver compensato.
        expect(positive, 'Il credito senza un debito da pagare è un giro a vuoto.').toHaveLength(1);
        expect(positive[0].importo).toBe(250);
    });

    test('due click non impegnano piu credito del debito che c\'è', async () => {
        // `creditoNecessario` guarda il debito totale e il contante, non quanto credito è già
        // stato impegnato sulle altre righe: due righe a credito cliccate una dopo l'altra
        // arrivavano a superare il debito.
        const SECONDO_CREDITO = {
            ...DEBITO_APERTO,
            id: 900, rata_padre_id: 900, descrizione: 'Saldo pregresso',
            residuo: -400, importo_totale: -400,
            dettaglio_quote: [{
                unita: 'Int. 1', anagrafica: 'Rossi Mario', anagrafica_id: 5, ruolo: 'P',
                residuo: -400, residuo_originale: -400, is_credito: true,
                componente_saldo: -400, componente_spesa: 0,
            }],
        };

        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, SECONDO_CREDITO, DEBITO_APERTO]);
        (wrapper.vm as any).mode = 'manual';
        await flushPromises();

        for (const descrizione of ['Rata n.2', 'Saldo pregresso']) {
            const cella = cellaAzione(riga(wrapper, descrizione));
            const b = cella.findAll('button').filter((x: any) => /credito/i.test(x.text()));
            if (b.length) { await b[0].trigger('click'); await flushPromises(); }
        }

        const impegnato = (wrapper.vm as any).form.dettaglio_pagamenti
            .filter((p: any) => p.importo < 0)
            .reduce((s: number, p: any) => s + Math.abs(p.importo), 0);

        expect(impegnato, 'Il debito aperto è 400: non si può impegnare più di così.').toBeLessThanOrEqual(400);
    });
});

describe('il credito applicato non diventa contante', () => {
    /**
     * Il difetto peggiore trovato su questa funzione, perché **quadra ed è falso**.
     *
     * `handleManualChange` ricalcola `form.importo_totale` — il CONTANTE — sommando i
     * `da_pagare` delle sole righe a residuo positivo. Dopo che il credito è stato applicato
     * quelle righe contengono anche la parte coperta dal credito, che in cassa non è mai
     * entrata. L'identità che il server verifica (`importo_totale = somma righe + eccedenza`)
     * torna lo stesso, perché la riga a credito è negativa e finisce nell'eccedenza: si
     * registra un incasso di denaro mai ricevuto, e nessuna guardia lo ferma.
     */
    test('in manuale nessuna riga porta credito, quindi il contante non lo include', async () => {
        // L'invariante che sostituisce il vecchio test: quello verificava che il contante non
        // includesse il credito su una riga coperta a metà, ma quello stato non è più
        // raggiungibile — `toggleMode` rilascia il credito entrando in manuale. Qui si fissa il
        // motivo per cui il difetto non può tornare, invece di un caso che non esiste più.
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        (wrapper.vm as any).toggleMode();
        await flushPromises();

        const rigaDebito = (wrapper.vm as any).rateList.find((r: any) => r.descrizione === 'Rata n.3');
        (wrapper.vm as any).handleManualChange(rigaDebito, 400);
        await flushPromises();

        const negative = (wrapper.vm as any).rateList.filter((r: any) => (r.da_pagare || 0) < 0);
        expect(negative, 'In manuale non sopravvivono righe a credito impegnate.').toHaveLength(0);

        expect(
            Number((wrapper.vm as any).form.importo_totale),
            'Il credito è stato rilasciato: quei 400 sono contante, e la cifra lo dice.',
        ).toBe(400);
    });
});

describe('l\'invariante del credito nella cella', () => {
    /**
     * L'invariante che rende sicuro il ciclo di applicazione del credito.
     *
     * Una revisione aveva segnalato che il ciclo, iterando su tutte le righe, potesse pagare
     * con il credito la riga che il credito lo porta — e poi sovrascriverla, producendo un
     * payload con la sola riga negativa: un giro a vuoto etichettato «CREDITO_USATO».
     *
     * Non è raggiungibile, e il motivo va fissato perché è una proprietà del **template**, non
     * del ciclo: la cella mostra il campo importo su `residuo > 0` e il pulsante solo nel ramo
     * `v-else-if`, quindi una riga che offre credito ha sempre residuo ≤ 0 e il ciclo la scarta
     * da sé. Se un giorno quei due rami diventassero indipendenti, questo test si accende — ed
     * è l'unico posto che ricorda che il ciclo si appoggia su quella mutua esclusione.
     */
    test('una riga che offre credito non ha mai anche il campo importo', async () => {
        const RIGA_GRUPPO = {
            ...COMPENSAZIONE_MISTA,
            id: 950, rata_padre_id: 950, descrizione: 'Rata n.9',
            residuo: 300, importo_totale: 300,
            dettaglio_quote: [
                {
                    unita: 'Int. 5', anagrafica: 'Rossi Mario', anagrafica_id: 5, ruolo: 'P',
                    residuo: -700, residuo_originale: -700, is_credito: true,
                    componente_saldo: -700, componente_spesa: 0,
                },
                {
                    unita: 'Int. 5', anagrafica: 'Bianchi Anna', anagrafica_id: 9, ruolo: 'I',
                    residuo: 1000, residuo_originale: 1000, is_credito: false,
                    componente_saldo: 0, componente_spesa: 1000,
                },
            ],
        };

        const wrapper = await apriConDebiti([RIGA_GRUPPO, DEBITO_APERTO]);

        for (const descrizione of ['Rata n.9', 'Rata n.3']) {
            const cella = cellaAzione(riga(wrapper, descrizione));
            const haPulsante = cella.findAll('button').some((b: any) => /credito/i.test(b.text()));
            const haCampo = cella.findAll('input').length > 0;

            expect(
                haPulsante && haCampo,
                `«${descrizione}»: pulsante e campo importo non possono coesistere — il ciclo di `
                + 'applicazione del credito si appoggia su questa esclusione.',
            ).toBe(false);
        }
    });
});

describe('togliere il credito rimette le cose come stavano', () => {
    /**
     * Il ramo di deselezione azzerava la sola riga a credito. Le righe a debito restavano
     * coperte da un credito che non c'era più: il payload dichiarava 1000 di allocato contro
     * 300 di versato, «Conferma incasso» restava attivo e il server rispondeva
     * `TotaleIncassoNonCorrispondente` — senza che niente collegasse l'errore al click di
     * annullamento. Per uscirne l'amministratore ritoccava a mano la cella, cadendo nel difetto
     * del contante gonfiato.
     */
    test('il secondo click sul pulsante scopre di nuovo i debiti che aveva coperto', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);
        (wrapper.vm as any).mode = 'manual';
        await flushPromises();

        const bottone = () => cellaAzione(riga(wrapper, 'Rata n.2'))
            .findAll('button').filter((b: any) => /credito/i.test(b.text()))[0];

        await bottone().trigger('click');
        await flushPromises();

        const debitoDopoApplicazione = (wrapper.vm as any).rateList
            .find((r: any) => r.descrizione === 'Rata n.3').da_pagare;
        expect(debitoDopoApplicazione).toBe(250);

        // Ci ripensa.
        await bottone().trigger('click');
        await flushPromises();

        const rate = (wrapper.vm as any).rateList;
        expect(
            rate.find((r: any) => r.descrizione === 'Rata n.3').da_pagare,
            'Tolto il credito, il debito che copriva torna scoperto.',
        ).toBe(0);
        expect((wrapper.vm as any).form.dettaglio_pagamenti).toHaveLength(0);
    });
});

describe('il totale versato non può scendere sotto zero', () => {
    /**
     * `handleManualChange` somma tutte le righe, negative comprese — giusto come definizione di
     * netto. Ma se l'allocazione a mano scende **sotto** il credito già impegnato, il netto
     * diventa negativo: `form.importo_totale` è anche il campo «Importo versato» a schermo, e
     * il server lo valida `min:0` (StoreIncassoRateRequest:49). L'amministratore riceve un 422
     * su un campo che non ha toccato, dopo aver cliccato Conferma.
     *
     * Manca il terzo termine: quando l'allocazione scende, a ridursi dev'essere il **credito
     * impegnato**, non il contante. Sotto quella soglia il netto non è denaro — è credito in
     * eccesso.
     */
    test('abbassare un debito sotto il credito impegnato riduce il credito, non il contante', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        (wrapper.vm as any).toggleMode();
        await flushPromises();

        // Il condòmino porta 100 in contanti, non 150: si abbassa la riga sotto i 250 di credito.
        const rigaDebito = (wrapper.vm as any).rateList.find((r: any) => r.descrizione === 'Rata n.3');
        (wrapper.vm as any).handleManualChange(rigaDebito, 100);
        await flushPromises();

        const totale = Number((wrapper.vm as any).form.importo_totale);
        expect(totale, 'Il server rifiuta un totale negativo con un 422.').toBeGreaterThanOrEqual(0);

        const pagamenti = (wrapper.vm as any).form.dettaglio_pagamenti;
        const somma = pagamenti.reduce((s: number, x: any) => s + x.importo, 0);
        expect(
            Math.round((somma + Number((wrapper.vm as any).form.eccedenza)) * 100),
            'E l\'identità che il server verifica deve continuare a tornare.',
        ).toBe(Math.round(totale * 100));
    });
});

describe('il credito si può sempre togliere', () => {
    /**
     * Arrivando dal link del widget la pagina auto-include le righe a credito della gestione
     * bersaglio anche se non selezionate — serve a precompilare. Ma il filtro non guardava una
     * deselezione **esplicita**: cliccando il pulsante verde per togliere il credito,
     * `spalmaCredito` lo rimetteva subito e il pulsante tornava verde da solo. Nessuna via
     * d'uscita nella pagina.
     */
    test('anche arrivando da un link il click di annullamento vale', async () => {
        const wrapper = await apriConDebiti(
            [COMPENSAZIONE_MISTA, DEBITO_APERTO],
            '?prefill_anagrafica_id=5&prefill_rata_id=91',
        );

        const bottone = () => cellaAzione(riga(wrapper, 'Rata n.2'))
            .findAll('button').filter((b: any) => /credito/i.test(b.text()))[0];

        // Precompilato: il credito è già impegnato.
        expect((wrapper.vm as any).form.dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(true);

        await bottone().trigger('click');
        await flushPromises();
        await flushPromises();

        expect(
            (wrapper.vm as any).form.dettaglio_pagamenti.some((p: any) => p.importo < 0),
            'L\'amministratore ha detto di non usarlo: la pagina non può rimetterlo da sola.',
        ).toBe(false);
    });
});

describe('«Usa credito» quando non c\'è niente da compensare', () => {
    /**
     * Se il contante già copre tutti i debiti, il credito non ha niente da fare. Prima il click
     * commutava comunque la pagina in automatica — cancellando la ripartizione decisa a mano,
     * per esempio dopo «Paga scadute» — e non applicava nulla: l'unico effetto osservabile era
     * la perdita del lavoro dell'amministratore, senza un messaggio.
     */
    test('non cancella la ripartizione fatta a mano e lo dice', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        // Modalità manuale con il contante che copre tutto il debito (400).
        (wrapper.vm as any).toggleMode();
        const rigaDebito = (wrapper.vm as any).rateList.find((r: any) => r.descrizione === 'Rata n.3');
        (wrapper.vm as any).handleManualChange(rigaDebito, 400);
        await flushPromises();

        expect((wrapper.vm as any).mode).toBe('manual');

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        expect((wrapper.vm as any).mode, 'Niente da compensare: non si commuta.').toBe('manual');
        expect(rigaDebito.da_pagare, 'La ripartizione a mano resta.').toBe(400);
        expect(wrapper.text()).toMatch(/nulla da compensare|già copert/i);
    });
});

describe('passare in manuale rilascia il credito', () => {
    /**
     * La pagina ha due meccanismi di allocazione — automatico con il credito, manuale riga per
     * riga — e non compongono. Finché una riga a credito resta impegnata mentre si edita a mano,
     * ogni combinazione produce un numero sbagliato: il campo importo è `:lazy="false"`, quindi
     * `handleManualChange` gira a OGNI TASTO, e qualunque valore transitorio più basso del
     * credito impegnato lo consumava per sempre. Ridigitando la cifra giusta il credito non
     * tornava, e il contante dichiarato includeva denaro mai ricevuto — con l'identità del
     * server che tornava lo stesso.
     *
     * La regola è quindi una sola: in manuale non ci sono righe a credito impegnate. Chi vuole
     * il credito torna in automatica e lo riapplica.
     */
    test('entrando in manuale il credito impegnato viene rilasciato', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();
        expect((wrapper.vm as any).form.dettaglio_pagamenti.some((p: any) => p.importo < 0)).toBe(true);

        (wrapper.vm as any).toggleMode();
        await flushPromises();

        expect(
            (wrapper.vm as any).form.dettaglio_pagamenti.some((p: any) => p.importo < 0),
            'In manuale non restano righe a credito impegnate.',
        ).toBe(false);
    });

    test('digitare a mano non produce mai un totale che il server rifiuta', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        (wrapper.vm as any).toggleMode();
        await flushPromises();

        // La sequenza vera di chi corregge un importo: svuota, poi ridigita. Ogni tasto passa
        // da `handleManualChange`.
        const rigaDebito = (wrapper.vm as any).rateList.find((r: any) => r.descrizione === 'Rata n.3');
        for (const valore of [0, 2, 25, 250]) {
            (wrapper.vm as any).handleManualChange(rigaDebito, valore);
            await flushPromises();
            expect(Number((wrapper.vm as any).form.importo_totale)).toBeGreaterThanOrEqual(0);
        }

        expect(
            Number((wrapper.vm as any).form.importo_totale),
            'Ha digitato 250 e in cassa entrano 250: nessun credito residuo che falsi il conto.',
        ).toBe(250);
    });
});

describe('rilasciando il credito si rilascia anche quello che copriva', () => {
    /**
     * Trovato alla verifica a video, e nessun test lo aveva preso.
     *
     * Entrando in manuale il credito viene rilasciato, ma l'importo che quel credito copriva
     * restava scritto sulla riga a debito: contante 0, riga a 150, «Allocato: € 150,00» e
     * «Conferma incasso» attivo. L'identità che il server pretende — `importo_totale = somma
     * righe + eccedenza` — non torna, e il salvataggio viene rifiutato.
     *
     * Rilasciare il credito senza rilasciare la sua copertura è mezzo lavoro: quello che resta
     * allocato dev'essere solo ciò che il CONTANTE copre.
     */
    test('entrando in manuale resta allocato solo quello che il contante copre', async () => {
        const wrapper = await apriConDebiti([COMPENSAZIONE_MISTA, DEBITO_APERTO]);

        const cella = cellaAzione(riga(wrapper, 'Rata n.2'));
        await cella.findAll('button').filter((b: any) => /credito/i.test(b.text()))[0].trigger('click');
        await flushPromises();

        const debito = () => (wrapper.vm as any).rateList.find((r: any) => r.descrizione === 'Rata n.3');
        expect(debito().da_pagare).toBe(250);

        // Nessun contante: passando in manuale non deve restare niente allocato.
        (wrapper.vm as any).toggleMode();
        await flushPromises();

        expect(
            debito().da_pagare,
            'Il credito è stato rilasciato: quei 250 non li copre più niente.',
        ).toBe(0);

        const pagamenti = (wrapper.vm as any).form.dettaglio_pagamenti;
        const somma = pagamenti.reduce((s: number, p: any) => s + p.importo, 0);
        expect(
            Math.round((somma + Number((wrapper.vm as any).form.eccedenza)) * 100),
            'E l\'identità del server deve tornare, o «Conferma» porta a un rifiuto.',
        ).toBe(Math.round(Number((wrapper.vm as any).form.importo_totale) * 100));
    });
});

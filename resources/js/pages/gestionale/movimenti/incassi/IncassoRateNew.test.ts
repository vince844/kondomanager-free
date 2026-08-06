// @vitest-environment jsdom

/**
 * Il banner «Richiesta di compensazione» della schermata di incasso rate.
 *
 * Da dove arriva quel banner: quando un condomino segnala un pagamento chiedendo di usare
 * il proprio credito, `PaymentReportingController` costruisce per l'amministratore un link
 * con `intent_usa_credito=true`. È l'unico pezzo di contesto che sopravvive al salto fra la
 * segnalazione e questa pagina: senza, l'amministratore vede una schermata di incasso
 * qualunque e non sa perché ci è arrivato.
 *
 * Il difetto: il flag che accende il banner viene **spento** dal watcher sulla lista rate,
 * ma solo nel ramo in cui un credito viene davvero trovato e auto-selezionato. Lo stato
 * stabile risulta quindi rovesciato rispetto a ciò che il banner dice:
 *
 * - c'è un credito → viene applicato da solo e il banner sparisce, cioè il contesto svanisce
 *   proprio nell'istante in cui c'era qualcosa da confermare;
 * - non c'è nessun credito → il flag non viene mai spento e il banner resta acceso a dire
 *   «clicca sul tasto "usa credito"», indicando un pulsante che in quello stato non è a
 *   schermo: il template lo disegna solo sulle righe con residuo negativo, e sulle altre
 *   scrive «N/D».
 *
 * Il secondo caso è il difetto vero: un'istruzione impossibile da eseguire manda
 * l'amministratore a cercare un bottone che non esiste.
 *
 * Perché il test monta il componente invece di provare una funzione: la decisione vive fra
 * un `ref` di `<script setup>`, un watcher e un `v-if` del template — non c'è nessuna
 * funzione pura da interrogare, e il messaggio sbagliato si vede solo arrivando fino al
 * testo che l'amministratore legge. È lo stesso motivo di `SaldiDetailPanel.test.ts`.
 *
 * Il denaro qui è in EURO decimali, non in centesimi: questa pagina formatta con
 * `useCurrencyFormatter({ fromCents: false })` e i residui arrivano già convertiti.
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
// `scaduta`), che è parte di ciò che il watcher poi legge.
vi.mock('axios', () => ({
    default: { get: vi.fn(async () => ({ data: { rate: server.rate } })) },
}));

/**
 * `<Head>` pretende il contesto di una pagina Inertia vera, che qui non esiste e non serve.
 * Non basta elencarlo negli `stubs`: in `<script setup>` i componenti sono riferimenti
 * risolti nello scope del modulo, non nomi che Vue cerca in un registro.
 *
 * `useForm` resta invece quello autentico: è il form su cui il componente appoggia
 * `pagante_id`, ed è la sua modifica a innescare il caricamento dei debiti.
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
 * (breadcrumb, `back-url`, URL dei debiti), non solo nel template: deve quindi esistere
 * davvero su `globalThis`, e non basta passarlo fra i `mocks` del contesto di rendering.
 */
(globalThis as any).route = (name: string) => `/${name}`;

const mocks = { route: (name: string) => `/${name}` };

/**
 * Della schermata interessa solo la fascia di avvisi sopra la tabella: layout, intestazione
 * e caselle di importo diventano stub trasparenti. `PageHeaderGuide` in particolare va
 * neutralizzato perché le sue schede di aiuto contengono anch'esse la stringa «Usa credito»,
 * e falserebbero le asserzioni sul testo.
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

function rata(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        descrizione: 'Rata 1',
        residuo: 200,
        importo_totale: 200,
        data_scadenza: '2026-03-31',
        scadenza_human: '31/03/2026',
        gestione_id: GESTIONE.id,
        gestione: GESTIONE.nome,
        intestatario: 'Rossi Mario',
        unita: 'Int. 1',
        is_emitted: true,
        is_published: true,
        // Il dettaglio c'è sempre nel payload vero (SituazioneDebitoriaController:235), ed è la
        // fonte da cui `creditoRiga` capisce DI CHI è il credito. Una fixture senza dettaglio
        // descriverebbe una risposta che il server non produce.
        dettaglio_quote: [
            {
                unita: 'Int. 1', anagrafica: 'Rossi Mario', anagrafica_id: 5, ruolo: 'P',
                residuo: (overrides.residuo as number) ?? 200,
                is_credito: ((overrides.residuo as number) ?? 200) < 0,
            },
        ],
        ...overrides,
    };
}

/** La riga di credito: residuo negativo, cioè il «salvadanaio» del condomino. */
const CREDITO = rata({
    id: 9,
    descrizione: 'Saldo iniziale 2026',
    residuo: -150,
    importo_totale: 150,
    data_scadenza: '2026-01-01',
    scadenza_human: '01/01/2026',
});

const DEBITO = rata();

/**
 * Monta la schermata come ci arriva l'amministratore dal link della segnalazione: la query
 * string è l'unico canale con cui l'intento viaggia, e in jsdom si imposta da `history`.
 */
async function apriDaSegnalazione(rate: any[], query: string) {
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

/**
 * Il testo del banner, o `null` se il banner non è a schermo. Si cerca il div più interno
 * che contiene il titolo, perché ogni antenato fino alla radice lo contiene a sua volta.
 */
function testoBanner(wrapper: any): string | null {
    const nodi = wrapper.findAll('div').filter((d: any) => d.text().includes('Richiesta di compensazione'));
    return nodi.length ? nodi[nodi.length - 1].text() : null;
}

/** Il pulsante che il banner nomina. Esiste solo sulle righe con residuo negativo. */
function hasPulsanteUsaCredito(wrapper: any): boolean {
    return wrapper.findAll('button').some((b: any) => b.text().toLowerCase().includes('usa credito'));
}

const INTENTO = '?intent_usa_credito=true&prefill_anagrafica_id=5';

describe('il contesto della richiesta di compensazione', () => {
    /**
     * L'amministratore è su questa pagina per una ragione precisa, e quella ragione deve
     * restare leggibile anche — anzi soprattutto — dopo che il sistema ha fatto da solo la
     * mossa che gli veniva chiesta. Oggi accade il contrario: il credito viene selezionato
     * e il banner si spegne nello stesso passaggio, lasciando una schermata muta con una
     * riga già valorizzata e nessuna spiegazione del perché.
     */
    test('con un credito in lista il banner resta, e dice che il credito è già selezionato', async () => {
        const wrapper = await apriDaSegnalazione([CREDITO, DEBITO], INTENTO);

        const testo = testoBanner(wrapper);

        expect(testo).not.toBeNull();
        expect(testo).toMatch(/credito[\s\S]*(selezionat|applicat)/i);
        // Il credito è già lì: mandare a cliccare è un'istruzione a vuoto, resta solo da
        // verificare e confermare.
        expect(testo!.toLowerCase()).not.toContain('clicca');
    });

    /**
     * Il difetto vero. Il condomino ha chiesto la compensazione, ma di credito in lista non
     * ce n'è: nessuna riga ha residuo negativo. Il banner resta acceso — perché il watcher
     * lo spegne solo trovando un credito — e continua a istruire l'amministratore a cliccare
     * «usa credito» su una riga dove il template disegna un «N/D».
     *
     * Il messaggio corretto deve dire che il credito non c'è: è un'informazione utile
     * (magari il condomino si sbaglia, o il credito è su un'altra gestione filtrata via),
     * mentre l'istruzione impossibile è solo una caccia al bottone.
     */
    test('senza credito in lista il banner lo dice, e non manda a cercare un pulsante inesistente', async () => {
        const wrapper = await apriDaSegnalazione([DEBITO], INTENTO);

        const testo = testoBanner(wrapper);

        expect(testo).not.toBeNull();
        expect(testo!.toLowerCase()).not.toContain('usa credito');
        expect(testo).toMatch(/nessun credito|non risulta|non è disponibile|non ci sono crediti/i);
    });

    /**
     * La premessa del test precedente, verificata invece di essere data per buona: nello
     * stato «nessun credito» il pulsante che il messaggio attuale nomina non è a schermo.
     *
     * Le due metà vanno lette insieme, altrimenti l'assenza del pulsante potrebbe essere
     * solo una tabella che non si è mai disegnata: con una riga a residuo negativo lo
     * stesso montaggio il pulsante lo produce eccome.
     */
    test('il pulsante «usa credito» c\'è solo dove c\'è un credito', async () => {
        const conCredito = await apriDaSegnalazione([CREDITO, DEBITO], '?prefill_anagrafica_id=5');
        expect(conCredito.findAll('tbody tr')).toHaveLength(2);
        expect(hasPulsanteUsaCredito(conCredito)).toBe(true);

        const senzaCredito = await apriDaSegnalazione([DEBITO], INTENTO);
        expect(senzaCredito.findAll('tbody tr')).toHaveLength(1);
        expect(hasPulsanteUsaCredito(senzaCredito)).toBe(false);
    });

    /**
     * Il contrappeso: un avviso che compare sempre è un avviso che si impara a non leggere.
     * Chi apre la schermata di incasso di sua iniziativa non deve vedere niente.
     */
    test('senza intento nell\'URL non compare nessun banner', async () => {
        const wrapper = await apriDaSegnalazione([CREDITO, DEBITO], '?prefill_anagrafica_id=5');

        expect(testoBanner(wrapper)).toBeNull();
    });
});

describe('il banner della richiesta non deve mentire sullo stato della selezione', () => {
    /**
     * Il difetto della beta.46, cioè il contrario di quello che la beta.46 correggeva.
     *
     * La correzione aveva separato «la richiesta» (un fatto, permanente) dall'«azione
     * compiuta», ma il testo mostrato continuava a dedurre l'azione da `creditoInLista` —
     * cioè dalla sola presenza del credito in elenco. Basta però un gesto qualunque che
     * ricostruisca la lista — la spunta «Mostra scadute», un cambio di gestione, il passaggio
     * persona/immobile — perché `getRateListByGestione` cloni le righe da `rawRateList` e
     * `useDebitiLoader` le rimetta tutte a `selezionata: false`. Il credito resta in elenco,
     * la selezione no, e il banner continua a dire «è già stato selezionato qui sotto»
     * davanti a zero importi da verificare.
     *
     * Il banner deve quindi guardare la selezione vera, non un flag che ricorda di averla
     * fatta una volta.
     */
    test('quando la selezione viene azzerata il banner smette di darla per fatta', async () => {
        const wrapper = await apriDaSegnalazione(
            [rata({ id: 1, descrizione: 'Saldo pregresso', residuo: -150 }), rata({ id: 2, residuo: 200 })],
            '?intent_usa_credito=true&prefill_anagrafica_id=5',
        );

        expect(testoBanner(wrapper)).toMatch(/selezionat|applicat/i);

        // Il gesto: la lista si ricostruisce e le righe tornano non selezionate.
        (wrapper.vm as any).rateList.forEach((r: any) => { r.selezionata = false; });
        await flushPromises();

        const testo = testoBanner(wrapper);
        expect(testo).not.toBeNull();
        expect(testo!.toLowerCase()).not.toContain('già stato selezionato');
        // Il credito c'è ancora: il banner deve dire come usarlo, non che è già fatto.
        expect(testo).toMatch(/usa credito|selezionalo|clicca/i);
    });

    test('mentre le rate si caricano il banner non dichiara che il credito non esiste', async () => {
        server.rate = [rata({ id: 1, descrizione: 'Saldo pregresso', residuo: -150 })];
        window.history.replaceState({}, '', '/gestionale/incassi/nuovo?intent_usa_credito=true&prefill_anagrafica_id=5');

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

        // Prima che la fetch dei debiti risponda: la lista è vuota, ma «vuota» non significa
        // «nessun credito». Dire al posto sbagliato che la richiesta è insoddisfacibile è il
        // modo in cui una segnalazione vera viene chiusa per errore.
        const testo = testoBanner(wrapper);
        if (testo !== null) {
            expect(testo.toLowerCase()).not.toContain('non risulta alcun credito');
        }

        wrapper.unmount();
    });

    test('cambiando il condòmino pagante la richiesta non lo segue', async () => {
        const wrapper = await apriDaSegnalazione(
            [rata({ id: 1, descrizione: 'Saldo pregresso', residuo: -150 }), rata({ id: 2, residuo: 200 })],
            '?intent_usa_credito=true&prefill_anagrafica_id=5',
        );

        expect(testoBanner(wrapper)).not.toBeNull();

        // L'amministratore si accorge di aver sbagliato persona.
        (wrapper.vm as any).form.pagante_id = 9;
        await flushPromises();
        await flushPromises();

        expect(
            testoBanner(wrapper),
            'La richiesta era di un altro condòmino: non può restare accesa su questa schermata.',
        ).toBeNull();
    });
});

describe('il link precompilato non arma da solo una compensazione fra gestioni', () => {
    /**
     * Il difetto che il link del widget ha portato con sé.
     *
     * `CreditoService::compensabile()` esclude di proposito l'attraversamento fra gestioni:
     * il motore lo consente ma pretende una spunta esplicita dell'amministratore, e un
     * suggerimento automatico non può darla per acquisita — è un contratto fissato da
     * `CompensabileCreditoTest::il_consiglio_non_attraversa_le_gestioni_da_solo`.
     *
     * Il link generato da quello stesso servizio però porta `prefill_rata_id`, che accende
     * `isInboxMode`, e in quella modalità venivano incluse **tutte** le righe a credito anche
     * senza un click — comprese quelle di un'altra gestione. Risultato: il backend consiglia
     * 100 € sull'ordinaria e la schermata si apre con 400 € già impegnati, 300 dei quali
     * attraversano le gestioni. Fra il click e lo spostamento resterebbe solo una casella di
     * conferma che l'amministratore non sa di dover leggere, perché quella compensazione non
     * l'ha chiesta lui.
     */
    test('la precompilazione impegna solo il credito della gestione bersaglio', async () => {
        const STRAORDINARIA = { id: 77, nome: 'Straordinaria 2026' };

        const wrapper = await apriDaSegnalazione(
            [
                rata({ id: 10, rata_padre_id: 100, descrizione: 'Rata 1 ordinaria', residuo: 500 }),
                rata({ id: 20, rata_padre_id: 200, descrizione: 'Credito ordinaria', residuo: -100 }),
                rata({
                    id: 30, rata_padre_id: 300, descrizione: 'Credito straordinaria', residuo: -300,
                    gestione_id: STRAORDINARIA.id, gestione: STRAORDINARIA.nome,
                }),
            ],
            '?prefill_anagrafica_id=5&prefill_rata_id=100',
        );

        const impegnate = (wrapper.vm as any).form.dettaglio_pagamenti as any[];
        const gestioniImpegnate = impegnate
            .filter((p) => p.importo < 0)
            .map((p) => (wrapper.vm as any).rateList.find((r: any) => r.id === p.rata_id)?.gestione_id);

        expect(
            gestioniImpegnate,
            'Nessuna riga della straordinaria deve essere impegnata senza che l\'amministratore l\'abbia scelta.',
        ).not.toContain(STRAORDINARIA.id);
    });
});

describe('il banner guarda tutto il credito, non solo quello a schermo', () => {
    /**
     * `creditoInLista` e `creditoSelezionato` leggevano `rateList`, che è la lista **filtrata**
     * — per gestione e per «mostra scadute». Bastava un filtro perché il banner dichiarasse
     * «non risulta alcun credito disponibile: la richiesta non può essere soddisfatta» mentre
     * il credito c'era ed era solo nascosto dal filtro.
     *
     * È la stessa frase categorica che `CreditoService::frase()` è appena stato corretto per
     * non dire più, e porta allo stesso esito: una segnalazione legittima chiusa per errore.
     */
    test('con il credito nascosto da un filtro il banner non lo dichiara inesistente', async () => {
        const wrapper = await apriDaSegnalazione(
            [
                // Non «saldo pregresso»: quella descrizione è riconosciuta da `isRataZero` e il
                // filtro la tiene comunque. Qui serve un credito che il filtro nasconda davvero
                // — una rata strapagata, non scaduta.
                rata({ id: 1, descrizione: 'Rata 3', residuo: -150, data_scadenza: '2099-01-01', scadenza_human: '01/01/2099' }),
                rata({ id: 2, residuo: 200 }),
            ],
            '?intent_usa_credito=true&prefill_anagrafica_id=5',
        );

        expect(testoBanner(wrapper)).not.toBeNull();

        // Il filtro «solo scadute» toglie dalla lista la riga del credito, che scade nel 2099.
        (wrapper.vm as any).showOnlyOverdue = true;
        await flushPromises();
        await flushPromises();

        const testo = testoBanner(wrapper);
        expect(testo).not.toBeNull();
        expect(
            testo!.toLowerCase(),
            'Il credito esiste: è il filtro a nasconderlo, e il banner non può dichiararlo inesistente.',
        ).not.toContain('non risulta alcun credito');
    });
});

describe('cambiare intestatario non spegne la distribuzione', () => {
    /**
     * Il watch sul pagante azzera le righe e ricalcola l'eccedenza, ma non rilanciava la
     * distribuzione. In ricerca per PERSONA non si notava, perché subito dopo `fetchDebiti`
     * ricarica la lista e il watcher su `rawRateList` ridistribuisce. In ricerca per IMMOBILE
     * quel caricamento non avviene — la guardia è `searchMode === 'persona'` — e restava tutto
     * a zero: importo digitato ancora lì, nessuna riga allocata, «Conferma incasso» spento
     * senza un messaggio. Le rate del nuovo intestatario erano in elenco e allocabili.
     */
    test('con la lista già in mano il nuovo intestatario ottiene la sua distribuzione', async () => {
        const wrapper = await apriDaSegnalazione(
            [rata({ id: 1, residuo: 300 }), rata({ id: 2, residuo: 200 })],
            '?prefill_anagrafica_id=5',
        );

        (wrapper.vm as any).form.importo_totale = 500;
        await flushPromises();
        expect((wrapper.vm as any).form.dettaglio_pagamenti.length).toBeGreaterThan(0);

        // Cambio intestatario senza che arrivi una lista nuova (è il caso «per immobile»).
        (wrapper.vm as any).searchMode = 'immobile';
        (wrapper.vm as any).form.pagante_id = 9;
        await flushPromises();
        await flushPromises();

        expect(
            (wrapper.vm as any).form.dettaglio_pagamenti.length,
            'L\'importo è ancora lì e le rate pure: la distribuzione va rifatta, non spenta.',
        ).toBeGreaterThan(0);
    });
});

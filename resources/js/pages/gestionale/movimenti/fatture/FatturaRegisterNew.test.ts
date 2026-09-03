// @vitest-environment jsdom

/**
 * La lettura XML in schermata (beta.14, decisione 1 di apertura — «due porte, una
 * stanza»). Il campo Allegato esisteva già («PDF, XML, P7M» era già nel placeholder):
 * questi test coprono il cablaggio nuovo, non l'intero modulo di registrazione, già
 * grande e non toccato da questa beta se non nel punto preciso dell'importazione.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

/**
 * L'invio del modulo, intercettato invece che simulato.
 *
 * ⚠️ **`useForm` resta quello vero**, e non è un dettaglio: tutta la reattività del
 * modulo — totali, stato del budget, disabilitazioni — passa di lì, e un mock inerte
 * renderebbe finti i 38 test che già ci sono. Si sostituisce **solo** `post()`, che è
 * l'unica cosa che uscirebbe in rete.
 *
 * In cambio il test ottiene la cosa che serve per provare il ciclo di vita della coda:
 * `onSuccess` in mano, da far scattare nel momento scelto. Fra il `post()` e la risposta
 * passano secondi veri, e in quei secondi l'amministratore può fare altro — è
 * esattamente lo spazio in cui vivono i reperti 8 e 9.
 */
const inviato = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const originale = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...originale,
        Head: { template: '<span />' },
        usePage: () => ({
            props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
        }),
        useForm: ((...args: unknown[]) => {
            const form = (originale.useForm as (...a: unknown[]) => Record<string, unknown>)(...args);

            // `processing` va alzato a mano: nel form vero lo fa il giro di rete che qui
            // non parte, ed è la prop da cui dipendono le disabilitazioni durante l'invio.
            form.post = (url: string, opzioni: Record<string, unknown>) => {
                form.processing = true;
                inviato.post(url, opzioni);
            };

            return form;
        }) as unknown as typeof originale.useForm,
    };
});

import FatturaRegisterNew from './FatturaRegisterNew.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    // Stub minimo ma non muto: espone pageTitle/pageSubtitle/guides come testo, così
    // i test su titolo e schede (fase scelta vs revisione, segnalato da Vincenzo il
    // 03/09/2026) misurano qualcosa di vero invece di uno slot vuoto.
    PageHeaderGuide: {
        props: ['pageTitle', 'pageSubtitle', 'guides', 'condominio'],
        template: `<div>
            <h1>{{ pageTitle }}</h1>
            <p>{{ pageSubtitle }}</p>
            <div v-if="condominio">{{ condominio.nome }}</div>
            <div v-for="g in guides" :key="g.title">{{ g.title }}</div>
            <slot name="actions" />
            <slot />
        </div>`,
    },
    // ⚠️ Stub, ma non muto: `ModalImportaXml` usa `<Teleport to="body">` e il suo
    // markup finisce fuori dal wrapper, invisibile a `wrapper.text()`. Qui si
    // ricostruisce solo il **confine** fra pagina e modale — le prop che riceve e gli
    // eventi che emette — così restano sotto test le cose della pagina: la lettura dei
    // file, l'indipendenza fra un file e l'altro, il passaggio al modulo. Il markup
    // vero della modale ha i suoi test in ModalImportaXml.test.ts.
    // ⚠️ Lo stub riproduce **tutti** i comandi del modale vero, non solo quelli che
    // servivano al primo test scritto: il cestino c'è su **ogni** voce — compresa quella
    // aperta nel modulo — e la chiusura con Esc/X esiste. Uno stub che offre meno comandi
    // del componente vero rende irraggiungibili nel test gli stati che a video si
    // raggiungono benissimo, ed è la ragione per cui i reperti 9 e 10 non erano coperti.
    ModalImportaXml: {
        props: ['show', 'files'],
        emits: ['update:show', 'aggiungi', 'rimuovi', 'seleziona'],
        template: `<div v-if="show" data-test="modale-xml">
            <input type="file" multiple @change="$emit('aggiungi', $event.target.files)" />
            <div v-for="v in files" :key="v.file.name + v.file.size">
                <span>{{ v.stato === 'pronto' && v.esito ? v.esito.fornitore.letto_da_xml.denominazione + ' — n. ' + v.esito.documento.numero_documento : v.file.name }}</span>
                <span>{{ v.erroreMessaggio }}</span>
                <button v-if="v.stato === 'pronto'" @click="$emit('seleziona', v)">Rivedi e registra</button>
                <button :aria-label="'Togli ' + v.file.name + ' dall\\'elenco'" @click="$emit('rimuovi', v)">Togli</button>
            </div>
            <button data-test="chiudi-modale-xml" @click="$emit('update:show', false)">Chiudi</button>
        </div>`,
    },
    // `ConfirmDialog` monta un AlertDialog di reka-ui, che teleporta fuori dal wrapper:
    // lo stub ne rende titolo e contenuto così i test possono verificare **che
    // all'utente venga chiesto**, senza dipendere dagli interni della libreria.
    //
    // ⚠️ **L'ordine degli eventi al clic su «conferma» è parte del contratto, non un
    // dettaglio.** `AlertDialogAction` di reka-ui chiude il dialogo da sé, quindi
    // `update:modelValue(false)` parte **prima** di `confirm`. Uno stub che emette solo
    // `confirm` è più gentile della realtà e lascia passare il difetto della beta.9
    // descritto in `useConfermaEliminazione.ts` — è esattamente quello che è successo
    // qui: test verdi, e a video il pulsante non faceva niente.
    ConfirmDialog: {
        props: ['modelValue', 'title', 'confirmText', 'cancelText', 'variant'],
        emits: ['update:modelValue', 'confirm', 'cancel'],
        template: `<div v-if="modelValue" data-test="conferma">
            <h4>{{ title }}</h4>
            <slot />
            <button @click="$emit('update:modelValue', false); $emit('confirm')">{{ confirmText }}</button>
            <button @click="$emit('cancel'); $emit('update:modelValue', false)">{{ cancelText }}</button>
        </div>`,
    },
    FatturaRegistrazioneGuide: true,
    WidgetDoubleLock: true,
    ModalSpesaImprevista: true,
    ModalOverrideBudget: true,
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: {
        name: 'MoneyInput',
        props: ['modelValue'],
        template: '<input :value="modelValue" />',
    },
};

const FORNITORE_A = { id: 10, ragione_sociale: 'Alfa Servizi Srl', soggetto_ritenuta: false, partita_iva: '01234567897' };
const FORNITORE_B = { id: 20, ragione_sociale: 'Beta Forniture Srl', soggetto_ritenuta: false, partita_iva: '01234567897' };

/**
 * Stesso id di FORNITORE_A (l'unico candidato in ESITO_TROVATO), ma con default
 * dell'anagrafica DIVERSI dai valori che l'XML dichiara — apposta, per distinguere
 * nei test «è arrivato dal file» da «è il default dell'anagrafica»: se il watcher
 * tornasse a sovrascrivere, i due valori si confonderebbero.
 */
const FORNITORE_CON_DEFAULT = {
    ...FORNITORE_A,
    iban_principale: 'IT99Y0000000000000000009999',
    modalita_pagamento_default: 'contanti',
    giorni_scadenza: 60,
    ultimo_conto_id: 55, // combacia con props.conti[0].id in render()
};

function render(fornitori: Record<string, unknown>[] = [FORNITORE_A], opts: { modalitaIngresso?: 'xml' | 'manuale' } = {}) {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            fornitori,
            conti: [{ id: 55, nome: 'Pulizie' }],
            banche: [],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
            modalita_ingresso: opts.modalitaIngresso,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

const ESITO_TROVATO = {
    documento: {
        tipo_documento: 'fattura',
        numero_documento: 'FT-XML-1',
        data_documento: '2026-06-10',
        data_scadenza: '2026-07-10',
        modalita_pagamento: 'bonifico',
        iban_fornitore: 'IT43X0100003245100000000001',
    },
    righe: [{ descrizione: 'Servizio letto da XML', importo_imponibile: 35, aliquota_iva: 22 }],
    fornitore: {
        esito: 'trovato',
        candidati: [{ id: FORNITORE_A.id, ragione_sociale: FORNITORE_A.ragione_sociale }],
        letto_da_xml: { denominazione: 'ALFA SERVIZI SRL', partita_iva: '01234567897', codice_fiscale: null },
    },
    avvisi: { lotto_con_altri_documenti: 0, righe_non_quadrano_col_riepilogo: false, scarto_righe_riepilogo_cents: 0 },
};

/** Simula la scelta di un file nel campo nascosto, come farebbe l'amministratore. */
async function selezionaFile(wrapper: ReturnType<typeof render>, nome: string, contenuto = '<xml/>') {
    const input = wrapper.find('input[type="file"]');
    const file = new File([contenuto], nome, { type: 'text/xml' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

/** Simula la scelta di uno o più file nella dropzone della modale (campo `multiple`). */
async function selezionaFileNellaDropzone(wrapper: ReturnType<typeof render>, files: File[]) {
    const input = wrapper.find('input[type="file"][multiple]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
    await flushPromises();
}

/**
 * Preme «Registra documento» e restituisce le opzioni passate a `post()`.
 *
 * ⚠️ Preme il **pulsante**, non chiama `handleSubmit()`: è la lezione della beta.14 —
 * chiamando la funzione si salta tutto ciò che sta fra il gesto e l'effetto, ed è lì che
 * si nascondono i difetti. Chi legge `onSuccess` dal risultato può poi farlo scattare
 * quando vuole, simulando la risposta che arriva **dopo** che l'utente ha fatto altro.
 */
async function premiRegistra(wrapper: ReturnType<typeof render>) {
    inviato.post.mockClear();

    const pulsante = wrapper.findAll('button').find((b) => b.text().includes('Registra Documento'));
    expect(pulsante, 'il pulsante «Registra Documento» deve esserci').toBeTruthy();
    await pulsante!.trigger('click');
    await wrapper.vm.$nextTick();

    const opzioni = inviato.post.mock.calls.at(-1)?.[1] as { onSuccess?: () => void } | undefined;
    expect(opzioni, 'l\'invio deve essere partito').toBeTruthy();
    return opzioni!;
}

async function flushPromises() {
    // ⚠️ Quattro giri, non due: bastavano per un solo importaXml() in corsa (la
    // forma originale di questo helper), ma gestisciFileMultipli() aggiunge un
    // Promise.all() e per ogni file una propria istanza di useImportaFatturaXml()
    // — un salto di microtask in più per il caso multi-file rispetto al caso
    // singolo che questo helper serviva quando è nato.
    for (let i = 0; i < 4; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

describe('un file non-XML resta un allegato semplice', () => {
    test('un PDF non chiama l\'endpoint di importazione', async () => {
        const wrapper = render();
        await selezionaFile(wrapper, 'documento.pdf');

        expect(axios.post).not.toHaveBeenCalled();
    });
});

describe('un XML viene letto e precompila il form', () => {
    test('numero, data, righe e importi arrivano dal file, in euro non in centesimi', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render();

        await selezionaFile(wrapper, 'fattura.xml');

        expect(axios.post).toHaveBeenCalledTimes(1);
        const moneyInput = wrapper.findAllComponents({ name: 'MoneyInput' })[0];
        expect(moneyInput.props('modelValue')).toBe(35);
        expect(wrapper.text()).toContain('Fornitore agganciato');
        expect(wrapper.text()).toContain(FORNITORE_A.ragione_sociale);
    });

    test('un fornitore trovato imposta form.fornitore_id senza che l\'amministratore lo scelga', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render();

        await selezionaFile(wrapper, 'fattura.xml');

        // Il banner di conferma è la prova indiretta che form.fornitore_id è stato
        // impostato: senza, `selectedFornitore` sarebbe undefined e il testo non
        // mostrerebbe la ragione sociale (vedi test sopra).
        expect(wrapper.text()).toContain(FORNITORE_A.ragione_sociale);
    });

    test('un campo assente nell\'XML non cancella un valore già scritto a mano', async () => {
        const esitoSenzaScadenza = {
            ...ESITO_TROVATO,
            documento: { ...ESITO_TROVATO.documento, data_scadenza: null, iban_fornitore: null },
        };
        axios.post.mockResolvedValueOnce({ data: esitoSenzaScadenza });
        const wrapper = render();

        // ⚠️ Trovato dalla revisione avversariale della beta.14: `input[type="date"]`
        // prende il PRIMO match, che è «Data documento» (form.data_documento, scritto
        // dall'XML senza guardia — non può mai restare vuoto). La scadenza è il
        // secondo campo `type="date"` della schermata: senza `[1]` questo test misura
        // un campo che non può fallire, qualunque cosa faccia l'importazione.
        const scadenza = wrapper.findAll('input[type="date"]')[1].element as HTMLInputElement;
        expect(scadenza).toBeTruthy();

        await selezionaFile(wrapper, 'fattura.xml');

        // Non asseriamo un valore specifico (dipende dal default del form): asseriamo
        // che l'importazione non l'abbia svuotato leggendo un null dall'XML.
        expect(scadenza.value).not.toBe('');
    });

    test('scadenza e IBAN letti dal file sopravvivono all\'aggancio del fornitore', async () => {
        // Trovato dalla revisione avversariale della beta.14, corretto in questa beta:
        // precompilaDaXml() scrive scadenza/IBAN dal file, POI form.fornitore_id per
        // agganciare il fornitore — che faceva scattare il watch e sovrascriveva quei
        // valori con i default dell'anagrafica un istante dopo averli scritti.
        // FORNITORE_CON_DEFAULT ha default DIVERSI da quelli del file: se il difetto
        // fosse tornato, questo test lo vedrebbe.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_CON_DEFAULT]);

        await selezionaFile(wrapper, 'fattura.xml');

        const [dataDocumento, scadenza] = wrapper.findAll('input[type="date"]').map((i) => (i.element as HTMLInputElement).value);
        const iban = (wrapper.find('input[placeholder="IT00 0000..."]').element as HTMLInputElement).value;

        expect(dataDocumento).toBe(ESITO_TROVATO.documento.data_documento);
        expect(scadenza).toBe(ESITO_TROVATO.documento.data_scadenza); // non 2026-08-09 (default anagrafica)
        expect(iban).toBe(ESITO_TROVATO.documento.iban_fornitore); // non IT99 (default anagrafica)
    });

    test('quando il file non dichiara scadenza/IBAN, i default dell\'anagrafica restano il fallback', async () => {
        // Controprova incrociata sul test sopra: la correzione è per campo, non un
        // blocco totale. Se il file tace su un campo, il comodo auto-fill
        // dall'anagrafica del fornitore deve continuare a funzionare come sempre.
        const esitoSenzaScadenzaIban = {
            ...ESITO_TROVATO,
            documento: { ...ESITO_TROVATO.documento, data_scadenza: null, iban_fornitore: null },
        };
        axios.post.mockResolvedValueOnce({ data: esitoSenzaScadenzaIban });
        const wrapper = render([FORNITORE_CON_DEFAULT]);

        await selezionaFile(wrapper, 'fattura.xml');

        const iban = (wrapper.find('input[placeholder="IT00 0000..."]').element as HTMLInputElement).value;
        expect(iban).toBe(FORNITORE_CON_DEFAULT.iban_principale);
    });

    test('le righe importate propongono il capitolo dell\'ultima fattura del fornitore', async () => {
        // Deciso con Vincenzo il 02/09/2026: 28 righe da assegnare su 11 fatture vere
        // è il costo di tempo più alto misurato (docs/lettura_xml_fatture_passive.md).
        // Stessa forma di ultima_aliquota_iva, già in uso per l'aliquota IVA.
        //
        // v-select è stubbato in questi test (riga stubs sopra): non è leggibile un
        // modelValue da uno stub `true`. Si osserva `riga.conto_id` indirettamente,
        // come già fa il resto di questo file (banner, MoneyInput) — se conto_id è
        // stato scritto, budgetImpacts (che aggrega per conto_id) smette di essere
        // vuoto e il pannello budget stampa il nome del capitolo.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_CON_DEFAULT]);

        expect(wrapper.text()).toContain('Nessuna voce ancora'); // prima dell'import: nessun conto_id

        await selezionaFile(wrapper, 'fattura.xml');

        expect(wrapper.text()).not.toContain('Nessuna voce ancora');
        expect(wrapper.text()).toContain('Pulizie'); // il nome del conto in props.conti
    });
});

describe('più di un fornitore con la stessa P.IVA', () => {
    test('mostra i candidati e la scelta imposta form.fornitore_id', async () => {
        const esitoAmbiguo = {
            ...ESITO_TROVATO,
            fornitore: {
                esito: 'ambiguo',
                candidati: [
                    { id: FORNITORE_A.id, ragione_sociale: FORNITORE_A.ragione_sociale },
                    { id: FORNITORE_B.id, ragione_sociale: FORNITORE_B.ragione_sociale },
                ],
                letto_da_xml: { denominazione: 'ALFA SERVIZI SRL', partita_iva: '01234567897', codice_fiscale: null },
            },
        };
        axios.post.mockResolvedValueOnce({ data: esitoAmbiguo });
        const wrapper = render([FORNITORE_A, FORNITORE_B]);

        await selezionaFile(wrapper, 'fattura.xml');

        expect(wrapper.text()).toContain(FORNITORE_A.ragione_sociale);
        expect(wrapper.text()).toContain(FORNITORE_B.ragione_sociale);

        const bottoni = wrapper.findAll('button').filter((b) => b.text() === FORNITORE_B.ragione_sociale);
        expect(bottoni).toHaveLength(1);
        await bottoni[0].trigger('click');

        // Scegliendo il candidato il banner di ambiguità sparisce.
        expect(wrapper.text()).not.toContain('quale intendevi');
    });
});

describe('nessun fornitore trovato', () => {
    test('mostra la denominazione letta dal file, senza inventare un aggancio', async () => {
        const esitoNonTrovato = {
            ...ESITO_TROVATO,
            fornitore: {
                esito: 'non_trovato',
                candidati: [],
                letto_da_xml: { denominazione: 'FORNITORE SCONOSCIUTO SRL', partita_iva: '99999999999', codice_fiscale: null },
            },
        };
        axios.post.mockResolvedValueOnce({ data: esitoNonTrovato });
        const wrapper = render();

        await selezionaFile(wrapper, 'fattura.xml');

        expect(wrapper.text()).toContain('Nessun fornitore trovato');
        expect(wrapper.text()).toContain('FORNITORE SCONOSCIUTO SRL');
    });
});

describe('un XML malformato', () => {
    test('mostra il messaggio di dominio del server, non un errore generico', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errore: 'File XML malformato: righe non chiuse' } } });
        const wrapper = render();

        await selezionaFile(wrapper, 'rotto.xml');

        expect(wrapper.text()).toContain('File XML malformato: righe non chiuse');
    });
});

describe('un lotto con più documenti', () => {
    test('avvisa che sono stati importati solo i dati del primo', async () => {
        const esitoLotto = { ...ESITO_TROVATO, avvisi: { ...ESITO_TROVATO.avvisi, lotto_con_altri_documenti: 2 } };
        axios.post.mockResolvedValueOnce({ data: esitoLotto });
        const wrapper = render();

        await selezionaFile(wrapper, 'lotto.xml');

        expect(wrapper.text()).toContain('altri 2 documenti');
    });
});

/**
 * La dropzone d'ingresso — riprogettazione della UI decisa con Vincenzo il
 * 02/09/2026, dopo che aveva fatto notare che «Importa XML» apriva la stessa
 * identica pagina di «Nuova fattura», con l'unico indizio dell'importazione in
 * fondo a un piccolo campo Allegato. `modalita_ingresso: 'xml'` (dalla rotta,
 * `?modo=xml` sulla toolbar) è ciò che distingue le due porte.
 */
describe('il reset fra un documento e il successivo', () => {
    test('dopo il reset la Gestione resta compilata', async () => {
        // ⚠️ Reperto 14 della Fase 1-bis. `gestione_id` non è scritto
        // dall'inizializzazione di useForm() ma da un watcher su `form.esercizio_id`.
        // `resettaFormPerNuovoDocumento()` riassegna `esercizio_id` allo STESSO valore,
        // quindi per Vue non è un cambiamento e il watcher non riparte — mentre
        // `gestione_id` è appena stato messo a null. Risultato: dal secondo documento
        // del lotto in poi la Gestione è vuota e il server rifiuta il salvataggio
        // («Il campo gestione id è richiesto»).
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        expect(vm.form.gestione_id).not.toBeNull(); // il watcher l'ha popolata al mount

        vm.resettaFormPerNuovoDocumento();
        await wrapper.vm.$nextTick();

        expect(vm.form.gestione_id).not.toBeNull();
    });
});

describe('ingresso XML: la modale sopra il modulo, non una pagina a parte', () => {
    test('con ?modo=xml la modale si apre da sola, ma il modulo resta la pagina', () => {
        // ⚠️ La differenza con la prima stesura (beta.14, poi rifatta il 03/09/2026):
        // allora `?modo=xml` mostrava la dropzone AL POSTO del modulo, e dal modulo non
        // si tornava più indietro. Ora apre solo la modale: la pagina sotto è, ed è
        // sempre, la registrazione fattura.
        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });

        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(true);
        expect(wrapper.find('input[type="date"]').exists()).toBe(true); // il modulo c'è
        expect(wrapper.text()).toContain('Registrazione fattura passiva');
    });

    test('senza ?modo=xml la modale è chiusa, ma la fascia per aprirla c\'è', () => {
        const wrapper = render([FORNITORE_A]);

        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Hai il file XML della fattura?');
        expect(wrapper.find('input[type="date"]').exists()).toBe(true);
    });

    test('il pulsante della fascia apre la modale', async () => {
        const wrapper = render([FORNITORE_A]);
        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(false);

        const apri = wrapper.findAll('button').find((b) => b.text() === 'Importa XML');
        expect(apri).toBeTruthy();
        await apri!.trigger('click');

        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(true);
    });

    test('titolo e schede sono sempre quelli della registrazione, in qualunque modo si entri', () => {
        // Con la fase è sparita anche la terna di schede alternativa: la pagina non
        // cambia più identità a seconda di come ci sei arrivato. Le tre spiegazioni
        // dell'importazione vivono nella guida dell'header (deciso con Vincenzo).
        for (const opts of [{}, { modalitaIngresso: 'xml' as const }]) {
            const wrapper = render([FORNITORE_A], opts);
            expect(wrapper.text()).toContain('Registrazione fattura passiva');
            expect(wrapper.text()).toContain('Panel + Ledger');
            expect(wrapper.text()).not.toContain('Importa fatture');
        }
    });

    test('il condominio è visibile anche entrando da ?modo=xml', () => {
        // Segnalato da Vincenzo (03/09/2026): PageHeaderGuide non riceveva
        // :condominio/:condomini/:esercizio/:esercizi — difetto preesistente di tutta
        // la pagina, che la schermata di importazione aveva solo reso evidente.
        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });

        expect(wrapper.text()).toContain('Condominio Demo KM');
    });

    test('un solo file letto senza errori riempie il modulo e chiude la modale', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });

        const file = new File(['<xml/>'], 'fattura.xml', { type: 'text/xml' });
        await selezionaFileNellaDropzone(wrapper, [file]);

        expect(wrapper.text()).toContain('Fornitore agganciato');
        expect(wrapper.text()).toContain(FORNITORE_A.ragione_sociale);
        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(false);
    });

    test('letto un file, la fascia dice da quale file arrivano i dati', async () => {
        // ⚠️ La fascia NON sparisce dopo la lettura, cambia stato: se sparisse
        // tornerebbe la porta a senso unico che questa riprogettazione ha chiuso.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });

        await selezionaFileNellaDropzone(wrapper, [new File(['<xml/>'], 'fattura.xml', { type: 'text/xml' })]);

        expect(wrapper.text()).toContain('Compilato dal file fattura.xml');
        expect(wrapper.text()).not.toContain('Hai il file XML della fattura?');
        // e resta un modo per riaprire il lettore
        expect(wrapper.findAll('button').some((b) => b.text() === 'Gestisci i file')).toBe(true);
    });

    test('la fascia non conta il documento che si sta compilando fra quelli «che restano»', async () => {
        // ⚠️ Trovato guardando lo schermo, non da un test: caricati DUE file e scelto il
        // primo, la fascia diceva «Restano 2 documenti» mentre ne restava uno solo —
        // `filesInCodaPronti` contava anche quello aperto nel modulo. La modale di
        // successo usa invece quel conteggio senza sottrazioni ed è giusto così, perché
        // là il registrato è già uscito dalla coda.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });

        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });
        await selezionaFileNellaDropzone(wrapper, [
            new File(['<xml/>'], 'a.xml', { type: 'text/xml' }),
            new File(['<xml/>'], 'b.xml', { type: 'text/xml' }),
        ]);

        // Con due file non si sceglie da soli: si sceglie a mano dall'elenco.
        const rivedi = wrapper.findAll('button').filter((b) => b.text() === 'Rivedi e registra');
        expect(rivedi.length).toBe(2);
        await rivedi[0].trigger('click');

        expect(wrapper.text()).toContain('Resta 1 altro documento');
        expect(wrapper.text()).not.toContain('Restano 2');
    });

    test('più file insieme restano nell\'elenco: nessuno sovrascrive lo stato di un altro', async () => {
        // Trovato dalla revisione avversariale della beta.14 sul percorso a un file
        // solo (useImportaFatturaXml senza guardia sulla corsa): qui non può
        // succedere perché ogni file ha la sua istanza del composable, e questo test
        // lo presidia facendo tornare le risposte in ordine INVERTITO.
        let risolviA: (v: unknown) => void;
        axios.post.mockImplementationOnce(() => new Promise((res) => { risolviA = res; }));
        axios.post.mockResolvedValueOnce({ data: { ...ESITO_TROVATO, fornitore: { ...ESITO_TROVATO.fornitore, letto_da_xml: { ...ESITO_TROVATO.fornitore.letto_da_xml, denominazione: 'BETA SERVIZI SRL' } } } });

        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });
        const a = new File(['<xml/>'], 'a.xml', { type: 'text/xml' });
        const b = new File(['<xml/>'], 'b.xml', { type: 'text/xml' });
        await selezionaFileNellaDropzone(wrapper, [a, b]);

        expect(wrapper.text()).toContain('BETA SERVIZI SRL'); // B è arrivato per primo
        risolviA!({ data: ESITO_TROVATO });
        await flushPromises();

        expect(wrapper.text()).toContain('ALFA SERVIZI SRL'); // A arrivato dopo
        expect(wrapper.text()).toContain('BETA SERVIZI SRL'); // ancora lì, non sovrascritto da A
    });

    test('un file in errore non blocca gli altri, e resta nell\'elenco col messaggio del server', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errore: 'File XML malformato: righe non chiuse' } } });
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });

        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });
        const rotto = new File(['<x>'], 'rotto.xml', { type: 'text/xml' });
        const buono = new File(['<xml/>'], 'buono.xml', { type: 'text/xml' });
        await selezionaFileNellaDropzone(wrapper, [rotto, buono]);

        expect(wrapper.text()).toContain('File XML malformato: righe non chiuse');
        expect(wrapper.text()).toContain('ALFA SERVIZI SRL');
    });

    test('chiudendo la modale senza scegliere niente resta il modulo vuoto, compilabile a mano', async () => {
        // Erede di «preferisco compilare a mano»: quel link serviva a uscire da una
        // fase che non esiste più. Adesso basta chiudere la modale — e il modulo c'era
        // già sotto, non va costruito.
        const chiamatePrima = axios.post.mock.calls.length;
        const wrapper = render([FORNITORE_A], { modalitaIngresso: 'xml' });

        await (wrapper.vm as any).$nextTick();
        (wrapper.vm as any).showImportaXmlModal = false;
        await (wrapper.vm as any).$nextTick();

        expect(wrapper.find('[data-test="modale-xml"]').exists()).toBe(false);
        expect(wrapper.find('input[type="date"]').exists()).toBe(true);
        expect(axios.post.mock.calls.length).toBe(chiamatePrima);
    });
});

describe('gli errori del server sono visibili', () => {
    // ⚠️ Il difetto che questi test presidiano è un **rifiuto muto**: il template
    // renderizza a fianco del campo solo `numero_documento` e `righe.N.descrizione`,
    // quindi qualunque altra chiave di validazione tornava dal server, popolava
    // form.errors e non compariva da nessuna parte — il pulsante si riabilitava e
    // basta. Trovato dal vivo il 03/09/2026 leggendo la risposta XHR di un
    // salvataggio che non produceva nessuna fattura: `righe.2.conto_id`.
    test('una chiave senza casella dedicata compare comunque, con la riga numerata da 1', async () => {
        const wrapper = render([FORNITORE_A]);

        (wrapper.vm as any).form.errors = {
            'righe.2.conto_id': 'Il capitolo di spesa è obbligatorio, oppure segna la riga come «fuori preventivo».',
        };
        await wrapper.vm.$nextTick();

        // «Riga 3», non «righe.2»: l'indice tecnico a zero non dice niente a chi
        // guarda un registro numerato da 1.
        expect(wrapper.text()).toContain('Riga 3: Il capitolo di spesa è obbligatorio');
    });

    test('più errori insieme sono elencati tutti, non solo il primo', async () => {
        const wrapper = render([FORNITORE_A]);

        (wrapper.vm as any).form.errors = {
            'righe.0.conto_id': 'Il capitolo di spesa è obbligatorio.',
            'righe.1.conto_id': 'Il capitolo di spesa è obbligatorio.',
            error: 'Integrità compromessa: manca l\'ancoraggio in Partita Doppia.',
        };
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Riga 1: Il capitolo di spesa è obbligatorio.');
        expect(wrapper.text()).toContain('Riga 2: Il capitolo di spesa è obbligatorio.');
        // La chiave 'error' — le eccezioni impreviste del controller — non ha un
        // prefisso di riga e resta il messaggio così com'è.
        expect(wrapper.text()).toContain('Integrità compromessa');
    });

    test('senza errori il banner non compare', () => {
        const wrapper = render([FORNITORE_A]);
        expect(wrapper.text()).not.toContain('Il capitolo di spesa è obbligatorio');
    });

    test('l\'etichetta «Riga N» c\'è anche per righe che il form non ha più', async () => {
        // ⚠️ Regressione vera, non ipotetica: la prima stesura costruiva le etichette
        // iterando su `form.righe`, quindi copriva solo le righe presenti in quel
        // momento. Un form con UNA riga che riceve un rifiuto su `righe.1.*` — perché
        // l'amministratore ne ha tolta una dopo aver premuto Registra — mostrava il
        // messaggio spoglio, senza sapere a quale riga si riferisse.
        const wrapper = render([FORNITORE_A]); // una riga sola nel form

        (wrapper.vm as any).form.errors = {
            'righe.1.conto_id': 'Il capitolo di spesa è obbligatorio.',
            'righe.4.descrizione': 'La descrizione è obbligatoria.',
        };
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Riga 2: Il capitolo di spesa è obbligatorio.');
        expect(wrapper.text()).toContain('Riga 5: La descrizione è obbligatoria.');
    });

    test('scegliendo il capitolo, l\'errore di quella riga sparisce da solo', async () => {
        // ⚠️ Il difetto che questo test presidia — segnalato da Vincenzo il 03/09/2026
        // guardando lo schermo: sceglieva il capitolo mancante e la riga rossa restava,
        // sia nel riepilogo in testa sia sotto il campo. `usePuliziaErrori` confrontava
        // `adesso['righe.0.conto_id']` con `precedente['righe.0.conto_id']`, cioè due
        // `undefined`: nessuna proprietà si chiama così: le chiavi degli array di Laravel
        // sono percorsi puntati e vanno risolti dentro i dati.
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        vm.form.errors = { 'righe.0.conto_id': 'Il capitolo di spesa è obbligatorio, oppure segna la riga come «fuori preventivo».' };
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Il capitolo di spesa è obbligatorio');

        // L'amministratore sceglie il capitolo che mancava.
        vm.form.righe[0].conto_id = 7;
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Il capitolo di spesa è obbligatorio');
    });

    test('correggere una riga non cancella l\'errore di un\'altra', async () => {
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        vm.form.righe.push({ descrizione: '', conto_id: null, immobile_id: null, importo_imponibile: 0, aliquota_iva: 22, is_sopravvenienza: false, concorre_base_ritenuta: true });
        await wrapper.vm.$nextTick();

        vm.form.errors = {
            'righe.0.conto_id': 'Il capitolo di spesa è obbligatorio, oppure segna la riga come «fuori preventivo».',
            'righe.1.conto_id': 'Il capitolo di spesa è obbligatorio, oppure segna la riga come «fuori preventivo».',
        };
        await wrapper.vm.$nextTick();

        vm.form.righe[0].conto_id = 7; // se ne corregge una sola
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        // La riga 1 è a posto, la riga 2 no: deve restare segnalata.
        expect(wrapper.text()).not.toContain('Riga 1:');
        expect(wrapper.text()).toContain('Riga 2:');
    });

    test('il riepilogo è lo stesso componente della scheda fornitore, non una copia', () => {
        // Se qualcuno rifà a mano un banner qui dentro, questo test non se ne accorge —
        // ma se il componente condiviso viene sganciato sì: è l'unico posto che
        // renderizza il conteggio «N campi da correggere» e il titolo di FormErrorSummary.
        const wrapper = render([FORNITORE_A]);
        (wrapper.vm as any).form.errors = { numero_documento: 'Il numero documento è obbligatorio.' };
        return wrapper.vm.$nextTick().then(() => {
            expect(wrapper.text()).toContain('Il salvataggio è stato rifiutato');
            expect(wrapper.text()).toContain('1 campo da correggere');
        });
    });
});

describe('un secondo XML non deve portarsi dietro il primo', () => {
    // ⚠️ **Il caso è raggiungibile solo da quando il lettore è una modale** apribile in
    // qualunque momento (03/09/2026): prima era una fase a senso unico e si importava
    // sempre su un modulo vuoto. `precompilaDaXml()` è rimasta scritta per quel mondo —
    // scrive i campi che il file dichiara e **lascia intatti gli altri**.
    //
    // Non è un difetto cosmetico. Dalla mappa delle catene (docs/catene_fra_moduli.md):
    // dal fornitore dipendono `soggetto_ritenuta`, `regime_forfetario`, il codice
    // tributo 1019/1020 e le percentuali — quindi netto da pagare, pagamento e **F24**.
    // Un fornitore rimasto appiccicato registra la fattura col regime fiscale di un altro.

    const ESITO_SENZA_FORNITORE = {
        ...ESITO_TROVATO,
        documento: { ...ESITO_TROVATO.documento, numero_documento: 'FT-XML-2', iban_fornitore: null, data_scadenza: null, modalita_pagamento: null },
        fornitore: { esito: 'non_trovato', candidati: [], letto_da_xml: { denominazione: 'BETA SRL', partita_iva: '99999999999', codice_fiscale: null } },
    };

    test('REPERTO 4 — il fornitore del primo file non resta agganciato al secondo', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        axios.post.mockResolvedValueOnce({ data: ESITO_SENZA_FORNITORE });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'primo.xml');
        expect(vm.form.fornitore_id).toBe(FORNITORE_A.id);

        await selezionaFile(wrapper, 'secondo.xml');

        // Il secondo file non riconosce nessun fornitore: il campo va lasciato da
        // scegliere, non ereditato dal documento precedente.
        expect(vm.form.fornitore_id).toBeNull();
    });

    test('REPERTO 5 — l\'IBAN del primo file non finisce sulla fattura del secondo', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        axios.post.mockResolvedValueOnce({ data: ESITO_SENZA_FORNITORE });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'primo.xml');
        expect(vm.form.iban_fornitore).toBe(ESITO_TROVATO.documento.iban_fornitore);

        await selezionaFile(wrapper, 'secondo.xml');

        // Un bonifico all'IBAN di un altro fornitore è denaro che parte verso il
        // destinatario sbagliato: meglio vuoto che ereditato.
        expect(vm.form.iban_fornitore).toBe('');
    });

    test('REPERTO 7 — la motivazione di sforo di un documento non passa a quello dopo', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        // Come se lo sforo fosse stato motivato e il salvataggio poi rifiutato per
        // altro: `override_budget` resta nel form, e niente lo azzera.
        vm.form.dati_extra.override_budget = { motivazione: 'Rottura urgente', importo_sforo: 12345 };
        await wrapper.vm.$nextTick();

        await selezionaFile(wrapper, 'nuovo.xml');

        // Una giustificazione legale è del documento che l'ha richiesta, non del form.
        expect(vm.form.dati_extra.override_budget).toBeNull();
    });
});

describe('l\'XML importato su un modulo già compilato', () => {
    test('REPERTO 15 — scelto il fornitore a mano, la scadenza del file sopravvive', async () => {
        // ⚠️ La guardia `campiXmlDaPreservare` viene letta SOLO se il fornitore cambia,
        // ma la scadenza si ricalcola anche quando cambia solo la data. Scegliendo prima
        // il fornitore e importando poi il suo XML, il fornitore NON cambia: la guardia
        // resta chiusa e i giorni dell'anagrafica vincono sulla data del documento.
        // FORNITORE_CON_DEFAULT ha giorni_scadenza 60; il file dichiara 2026-07-10.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_CON_DEFAULT]);
        const vm = wrapper.vm as any;

        vm.form.fornitore_id = FORNITORE_CON_DEFAULT.id;
        await wrapper.vm.$nextTick();

        await selezionaFile(wrapper, 'fattura.xml');

        expect(vm.form.data_scadenza).toBe('2026-07-10');
    });
});

describe('l\'XML non cancella il lavoro fatto a mano senza chiedere', () => {
    // ⚠️ Reperto 6. Che il file vinca sul compilato a mano è giusto — è la fonte
    // autorevole del documento. Il difetto è **perdere lavoro in silenzio**: si carica
    // l'XML e le righe scritte a mano spariscono senza che nessuno lo dica.
    //
    // La distinzione che conta: si protegge il lavoro **a mano**, non quello arrivato da
    // un altro file. Scegliere il secondo documento di un lotto non deve chiedere niente.

    test('con righe compilate a mano chiede conferma prima di sostituirle', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        vm.form.righe[0].descrizione = 'Scritto a mano dall\'amministratore';
        vm.form.righe[0].importo_imponibile = 250;
        await wrapper.vm.$nextTick();

        await selezionaFile(wrapper, 'fattura.xml');

        // Il file è stato letto, ma il modulo non è ancora stato riscritto.
        expect(vm.form.righe[0].descrizione).toBe('Scritto a mano dall\'amministratore');
        expect(wrapper.text()).toContain('sostituire quello che hai già scritto');
    });

    // ⚠️ Questo test **preme il pulsante**, non chiama `confermaSovrascrittura()`. La
    // differenza non è stilistica: chiamando la funzione si salta la chiusura del dialogo,
    // che è proprio ciò che azzerava il dato prima che la conferma lo leggesse. Con la
    // chiamata diretta il test era verde e a video il pulsante non faceva niente.
    test('confermando dal pulsante, le righe del file prendono il posto di quelle a mano', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        vm.form.righe[0].descrizione = 'Scritto a mano';
        await wrapper.vm.$nextTick();
        await selezionaFile(wrapper, 'fattura.xml');

        const conferma = wrapper.find('[data-test="conferma"]').findAll('button')
            .find((b) => b.text() === 'Sostituisci con il file');
        expect(conferma, 'il pulsante di conferma deve esserci').toBeTruthy();
        await conferma!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(vm.form.righe[0].descrizione).toBe(ESITO_TROVATO.righe[0].descrizione);
        expect(wrapper.text()).not.toContain('sostituire quello che hai già scritto');
    });

    test('su un modulo vuoto non chiede niente', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'fattura.xml');

        expect(vm.form.righe[0].descrizione).toBe(ESITO_TROVATO.righe[0].descrizione);
        expect(wrapper.text()).not.toContain('sostituire quello che hai già scritto');
    });

    test('passando al documento successivo di un lotto non chiede niente', async () => {
        // Il modulo è pieno, ma di roba arrivata da un file: non è lavoro da proteggere.
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        axios.post.mockResolvedValueOnce({ data: { ...ESITO_TROVATO, documento: { ...ESITO_TROVATO.documento, numero_documento: 'FT-XML-2' } } });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'primo.xml');
        await selezionaFile(wrapper, 'secondo.xml');

        expect(vm.form.numero_documento).toBe('FT-XML-2');
        expect(wrapper.text()).not.toContain('sostituire quello che hai già scritto');
    });
});

/**
 * Il ciclo di vita di `fileInLavorazione` (Fase 1-bis, reperti 8, 9 e 10).
 *
 * ⚠️ La radice è una sola: il ref viene **scritto** in due punti e **azzerato** in uno
 * solo, e nessuno lo tiene allineato né alla coda né alle operazioni asincrone in volo.
 * Fra il gesto e la risposta passano secondi veri, e in quei secondi l'amministratore
 * continua a lavorare: sono quei secondi il territorio di questi tre difetti.
 */
describe('il file in lavorazione ha un ciclo di vita', () => {
    /** Un esito XML uguale a ESITO_TROVATO ma riconoscibile dal numero di documento. */
    function esitoNumerato(numero: string) {
        return { ...ESITO_TROVATO, documento: { ...ESITO_TROVATO.documento, numero_documento: numero } };
    }

    function xml(nome: string) {
        return new File(['<xml/>'], nome, { type: 'text/xml' });
    }

    /** Riapre la modale dalla fascia in testa, come farebbe l'amministratore. */
    async function apriModaleXml(wrapper: ReturnType<typeof render>) {
        const pulsante = wrapper.findAll('button').find((b) => /Gestisci i file|Importa XML/.test(b.text()));
        expect(pulsante, 'il pulsante della fascia deve esserci').toBeTruthy();
        await pulsante!.trigger('click');
        await wrapper.vm.$nextTick();
    }

    /**
     * Preme «Rivedi e registra» sulla riga del documento `numero`.
     *
     * ⚠️ Si indirizza per **numero di documento**, non per posizione: la posizione dipende
     * da quali voci offrono un pulsante, cioè da una decisione di presentazione che può
     * cambiare, mentre il numero è quello che l'amministratore legge nella riga.
     */
    async function scegliDallaCoda(wrapper: ReturnType<typeof render>, numero: string) {
        const riga = wrapper.findAll('[data-test="modale-xml"] > div')
            .find((d) => d.text().includes(numero));
        expect(riga, `serve la riga del documento ${numero}`).toBeTruthy();

        const pulsante = riga!.findAll('button').find((b) => b.text() === 'Rivedi e registra');
        expect(pulsante, `la riga di ${numero} deve offrire «Rivedi e registra»`).toBeTruthy();
        await pulsante!.trigger('click');
        await wrapper.vm.$nextTick();
    }

    // ── Reperto 8 ────────────────────────────────────────────────────────────────
    // L'invio è un multipart con l'allegato: dura secondi. Il pulsante «Registra» è
    // disabilitato durante l'attesa, ma quello della fascia no — e da lì si può scegliere
    // un altro documento. Quando la risposta di A arriva, `onSuccess` rilegge
    // `fileInLavorazione`, che nel frattempo è diventato B.
    test('scegliendo B mentre il salvataggio di A è in volo, dalla coda esce A e non B', async () => {
        axios.post.mockResolvedValueOnce({ data: esitoNumerato('FT-A') });
        axios.post.mockResolvedValueOnce({ data: esitoNumerato('FT-B') });

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await apriModaleXml(wrapper);
        await selezionaFileNellaDropzone(wrapper, [xml('a.xml'), xml('b.xml')]);

        await scegliDallaCoda(wrapper, 'FT-A');
        expect(vm.form.numero_documento).toBe('FT-A');

        const opzioni = await premiRegistra(wrapper);

        // L'attesa: l'amministratore si porta avanti e apre il secondo documento.
        await apriModaleXml(wrapper);
        await scegliDallaCoda(wrapper, 'FT-B');
        expect(vm.form.numero_documento).toBe('FT-B');

        // Solo adesso arriva la risposta del server per A.
        opzioni.onSuccess!();
        await wrapper.vm.$nextTick();

        const rimasti = vm.filesPendenti.map((v: any) => v.file.name);
        expect(rimasti, 'A è registrato e va tolto; B non è ancora stato registrato').toEqual(['b.xml']);
    });

    // ── Reperto 9 ────────────────────────────────────────────────────────────────
    // La lettura è una chiamata HTTP con upload. Chiudere la modale durante l'attesa è
    // una via d'uscita che prima non esisteva (la dropzone era una pagina intera), e
    // l'auto-selezione a file singolo scatta dopo l'await senza rileggere se la modale
    // è ancora a schermo.
    test('chiusa la modale mentre legge, il file non si prende il modulo alle spalle', async () => {
        let concludiLettura!: () => void;
        axios.post.mockImplementationOnce(
            () => new Promise((resolve) => { concludiLettura = () => resolve({ data: ESITO_TROVATO }); }),
        );

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await apriModaleXml(wrapper);
        await selezionaFileNellaDropzone(wrapper, [xml('ripensamento.xml')]);
        expect(vm.filesPendenti[0].stato, 'la lettura deve essere ancora in corso').toBe('in_corso');

        // Ci ho ripensato: chiudo (la X, o Esc).
        await wrapper.find('[data-test="chiudi-modale-xml"]').trigger('click');
        await wrapper.vm.$nextTick();

        concludiLettura();
        await flushPromises();

        expect(vm.form.numero_documento, 'il modulo non deve riempirsi da solo').toBe('');
        expect(vm.fileInLavorazione).toBeNull();
        // Il file resta comunque in coda: scartare la modale non butta via la lettura.
        expect(vm.filesPendenti.map((v: any) => v.file.name)).toEqual(['ripensamento.xml']);
    });

    // È il test che distingue questa correzione da quella di un pelo più corta: con una
    // guardia scritta `if (!showImportaXmlModal.value) return` questo resta rosso, perché
    // alla fine della lettura una modale aperta c'è — solo che è un'altra.
    test('chiusa e riaperta la modale durante la lettura, il file non se la richiude sotto le dita', async () => {
        let concludiLettura!: () => void;
        axios.post.mockImplementationOnce(
            () => new Promise((resolve) => { concludiLettura = () => resolve({ data: ESITO_TROVATO }); }),
        );

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await apriModaleXml(wrapper);
        await selezionaFileNellaDropzone(wrapper, [xml('ripensamento.xml')]);

        await wrapper.find('[data-test="chiudi-modale-xml"]').trigger('click');
        await apriModaleXml(wrapper); // ci ho ripensato di nuovo: guardo l'elenco

        concludiLettura();
        await flushPromises();

        expect(wrapper.find('[data-test="modale-xml"]').exists(), 'la modale appena aperta deve restare aperta').toBe(true);
        expect(vm.form.numero_documento, 'il modulo non deve riempirsi da solo').toBe('');
        expect(
            wrapper.findAll('button').filter((b) => b.text() === 'Rivedi e registra').length,
            'il file è pronto e mi aspetta nell\'elenco',
        ).toBe(1);
    });

    // ── Reperto 10 ───────────────────────────────────────────────────────────────
    // Il cestino toglieva la voce dalla coda e basta: il puntatore le sopravviveva, e la
    // fascia continuava a promettere di riproporre un documento che non c'è più.
    //
    // ⚠️ L'asserto finale fissa anche una **decisione**: il cestino è un comando
    // dell'elenco, non del modulo. I dati e l'allegato restano dove sono.
    test('cestinato il documento aperto, la fascia non promette più di riproporlo', async () => {
        axios.post.mockResolvedValueOnce({ data: esitoNumerato('FT-A') });
        axios.post.mockResolvedValueOnce({ data: esitoNumerato('FT-B') });

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await apriModaleXml(wrapper);
        await selezionaFileNellaDropzone(wrapper, [xml('a.xml'), xml('b.xml')]);
        await scegliDallaCoda(wrapper, 'FT-A');
        expect(wrapper.text()).toContain('Resta 1 altro documento');

        // Ripensamento: butto dall'elenco proprio quello che ho aperto, e poi anche l'altro.
        await apriModaleXml(wrapper);
        await wrapper.find('button[aria-label="Togli a.xml dall\'elenco"]').trigger('click');
        await wrapper.find('button[aria-label="Togli b.xml dall\'elenco"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(vm.fileInLavorazione, 'il puntatore non sopravvive alla voce').toBeNull();
        expect(wrapper.text(), 'non c\'è più niente da riproporre').not.toContain('altro documento');
        expect(
            wrapper.findAll('button').some((b) => b.text() === 'Importa XML'),
            '«Gestisci i file» non deve aprire un elenco vuoto',
        ).toBe(true);

        // I dati restano dove sono: il cestino è un comando dell'elenco, non del modulo.
        expect(vm.form.numero_documento).toBe('FT-A');
        expect(wrapper.text()).toContain('Compilato dal file a.xml');
    });

    // La lettura dal riquadro «Allega documento» dura secondi come le altre, e in quei
    // secondi si può aprire un documento dalla coda: vinceva l'ultima risposta arrivata
    // invece dell'ultima richiesta.
    test('l\'XML allegato che finisce di leggere in ritardo non riscrive il documento aperto nel frattempo', async () => {
        let concludiAllegato!: () => void;
        axios.post.mockImplementationOnce(
            () => new Promise((resolve) => { concludiAllegato = () => resolve({ data: esitoNumerato('FT-LENTO') }); }),
        );
        axios.post.mockResolvedValueOnce({ data: esitoNumerato('FT-SVELTO') });

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'lento.xml'); // porta «Allega documento»
        expect(vm.form.numero_documento, 'la lettura è ancora in corso').toBe('');

        // Nell'attesa passo dall'altra porta e apro un documento vero.
        await apriModaleXml(wrapper);
        await selezionaFileNellaDropzone(wrapper, [xml('svelto.xml')]);
        expect(vm.form.numero_documento).toBe('FT-SVELTO');

        concludiAllegato();
        await flushPromises();

        expect(vm.form.numero_documento, 'la risposta in ritardo non deve riprendersi il modulo').toBe('FT-SVELTO');
        expect(wrapper.text()).toContain('Compilato dal file svelto.xml');
    });

    // ⚠️ Copre la riga `filesPendenti.includes(voce) ? voce : null` di
    // `applicaFilePendente()`, che senza questo test non è protetta da niente: l'ho
    // verificato togliendola, e restavano verdi tutti e 44 gli altri. Una guardia che
    // nessuno prova è una guardia che fra sei mesi qualcuno «semplifica».
    //
    // Il percorso è quello vero: dal riquadro «Allega documento», su un modulo con lavoro
    // a mano, la voce viene costruita al volo e passata al dialogo di conferma. Chi
    // conferma non deve ritrovarsi agganciato a un elemento che nella coda non c'è.
    test('confermando la sovrascrittura da un file allegato, non resta agganciato un puntatore fantasma', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });

        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        vm.form.righe[0].descrizione = 'Scritto a mano';
        await wrapper.vm.$nextTick();

        await selezionaFile(wrapper, 'allegato.xml');
        expect(wrapper.text()).toContain('sostituire quello che hai già scritto');

        const conferma = wrapper.find('[data-test="conferma"]').findAll('button')
            .find((b) => b.text() === 'Sostituisci con il file');
        await conferma!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(vm.form.righe[0].descrizione, 'il documento si applica comunque').toBe(ESITO_TROVATO.righe[0].descrizione);
        expect(vm.fileInLavorazione, 'la voce non è mai entrata in coda').toBeNull();
        expect(
            wrapper.findAll('button').some((b) => b.text() === 'Importa XML'),
            'senza elenco il pulsante non deve promettere un elenco',
        ).toBe(true);
    });
});

/**
 * ⚠️ Trovato verificando il reperto 10, ed è **più frequente** di come il reperto lo
 * descriveva: non serve nessun cestino. Chi importa dal riquadro «Allega documento» —
 * la porta più vecchia e più battuta — ottiene un `fileInLavorazione` che non è mai
 * entrato in `filesPendenti`. Il difetto l'ha portato dentro la correzione del reperto 6,
 * il 03/09: prima quel ramo non toccava `fileInLavorazione`.
 *
 * L'asserto è scritto come **invariante**, non come preferenza di disegno: comunque si
 * decida di sistemarlo, un pulsante che promette un elenco deve aprire un elenco.
 */
describe('la fascia non promette elenchi che non ci sono', () => {
    test('importando dal riquadro Allegato, il pulsante e la coda dicono la stessa cosa', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'allegato.xml');
        expect(vm.form.numero_documento, 'il file dev\'essere stato letto').toBe('FT-XML-1');

        const pulsante = wrapper.findAll('button').find((b) => /Gestisci i file|Importa XML/.test(b.text()));
        const prometteUnElenco = pulsante!.text().includes('Gestisci i file');

        await pulsante!.trigger('click');
        await wrapper.vm.$nextTick();

        const vociInElenco = wrapper.findAll('[data-test="modale-xml"] button')
            .filter((b) => b.text() === 'Rivedi e registra').length;

        if (prometteUnElenco) {
            expect(vociInElenco, '«Gestisci i file» deve aprire un elenco non vuoto').toBeGreaterThan(0);
        } else {
            expect(vociInElenco, 'senza elenco il pulsante deve dire «Importa XML»').toBe(0);
        }
    });
});

/**
 * ⚠️ **La riga che tiene in piedi l'F24, e il cablaggio a metà che la scollegava.**
 *
 * Nella beta.14 il contributo cassa previdenziale è diventato una riga di spesa, e il
 * server calcola per quella riga `concorre_base_ritenuta` leggendo il campo `<Ritenuta>`
 * che lo schema FatturaPA ha apposta (`ImportaFatturaXmlController::righeDaContributiCassa()`).
 * È la protezione chiesta da Vincenzo — «il nostro F24 non ha problemi giusto?».
 *
 * Il frontend però riscriveva `concorre_base_ritenuta: true` su **ogni** riga letta dal
 * file, buttando via proprio quel flag: il contributo entrava nella base della ritenuta
 * anche quando il file dice di no. Si trattiene al fornitore più del dovuto e si versa
 * all'Erario più del dovuto — la trappola 1 della catena in `docs/catene_fra_moduli.md`.
 *
 * Le righe normali il flag non ce l'hanno, e per loro `true` resta il default corretto:
 * è la ragione per cui si legge `!== false` e non `=== true`.
 */
describe('il flag di base ritenuta dichiarato dal file non si butta via', () => {
    const ESITO_CON_CASSA = {
        ...ESITO_TROVATO,
        righe: [
            { descrizione: 'Direzione lavori', importo_imponibile: 3200, aliquota_iva: 22 },
            {
                descrizione: 'Contributo cassa previdenziale 5% (TC03)',
                importo_imponibile: 160,
                aliquota_iva: 22,
                concorre_base_ritenuta: false,
            },
        ],
    };

    test('il contributo cassa che il file dichiara non soggetto resta fuori dalla base', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_CON_CASSA });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'parcella.xml');

        expect(vm.form.righe).toHaveLength(2);
        expect(vm.form.righe[0].concorre_base_ritenuta, 'la prestazione concorre').toBe(true);
        expect(
            vm.form.righe[1].concorre_base_ritenuta,
            'il contributo cassa NON concorre: lo dice il file',
        ).toBe(false);
    });

    test('una riga senza il flag concorre, perché è il default giusto', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'ordinaria.xml');

        expect(vm.form.righe[0].concorre_base_ritenuta).toBe(true);
    });
});

/**
 * Il confronto sulla ritenuta a schermo (Fase 1-bis, reperti 2 e 12).
 *
 * La logica del confronto ha i suoi test in `lib/gestionale/fatture/confrontoRitenuta.test.ts`,
 * caso per caso su tutta la matrice. Qui si prova il **cablaggio**: che il dato del file
 * arrivi fino al computed, e che a schermo compaia la frase giusta — o nessuna frase.
 */
describe('il confronto fra la ritenuta del file e quella del modulo', () => {
    const RITENUTA_DAL_FILE = { tipo: 'RT01', importo: 42, aliquota: 4, causale_pagamento: 'A' };

    const FORNITORE_CON_RITENUTA = {
        ...FORNITORE_A,
        soggetto_ritenuta: true,
        regime_forfetario: false,
        tipo_ritenuta: 'appalto_4',
        natura_percipiente: 'persona_fisica_irpef',
    };

    // ── Il caso del reperto 12 ────────────────────────────────────────────────────
    test('il file dichiara e il fornitore non è segnato: lo dice, e dice cosa fare', async () => {
        axios.post.mockResolvedValueOnce({ data: { ...ESITO_TROVATO, ritenuta: RITENUTA_DAL_FILE } });
        const wrapper = render([FORNITORE_A]); // soggetto_ritenuta: false
        await selezionaFile(wrapper, 'parcella.xml');

        const testo = wrapper.text();
        expect(testo).toContain('Il file dichiara una ritenuta, il modulo non ne trattiene nessuna');
        expect(testo).toContain('non è segnato come soggetto a ritenuta');
        // ⚠️ Lo spazio fra simbolo e cifra è un NBSP (` `), non uno spazio normale:
        // `useCurrencyFormatter` usa `spacing: 'nbsp'` apposta, perché € e importo non
        // devono andare a capo separati. Un asserto con lo spazio normale fallisce su un
        // testo perfettamente corretto.
        expect(testo, 'l\'importo va scritto col simbolo prima').toContain('€ 42,00');
        expect(testo, 'e va detto perché conta').toContain('versarla con l\'F24');
    });

    // ── Il verso opposto ─────────────────────────────────────────────────────────
    test('il modulo trattiene e il file tace: lo dice, ed è un danno speculare', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO }); // nessun blocco ritenuta
        const wrapper = render([FORNITORE_CON_RITENUTA]);
        await selezionaFile(wrapper, 'ordinaria.xml');

        const testo = wrapper.text();
        expect(testo).toContain('Il file non dichiara nessuna ritenuta, il modulo ne trattiene');
        expect(testo).toContain('sostituto d\'imposta');
    });

    // ── Il caso in cui deve TACERE, che è il più importante ───────────────────────
    test('fornitore non soggetto e file muto: non deve comparire nessun riquadro', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_TROVATO });
        const wrapper = render([FORNITORE_A]);
        await selezionaFile(wrapper, 'ordinaria.xml');

        const testo = wrapper.text();
        expect(testo).not.toContain('Il file dichiara una ritenuta');
        expect(testo).not.toContain('Il file non dichiara nessuna ritenuta');
        expect(testo).not.toContain('il modulo trattiene lo stesso importo');
    });

    test('senza nessun file importato non si segnala niente', async () => {
        const wrapper = render([FORNITORE_CON_RITENUTA]);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Il file non dichiara nessuna ritenuta');
    });

    // ── Il contributo previdenziale, che ritenuta non è (reperto 19) ──────────────
    test('un contributo previdenziale dichiarato dal file viene nominato a parte', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                ...ESITO_TROVATO,
                avvisi: { ...ESITO_TROVATO.avvisi, contributi_previdenziali_dichiarati: ['RT04'] },
            },
        });
        const wrapper = render([FORNITORE_A]);
        await selezionaFile(wrapper, 'con-enasarco.xml');

        const testo = wrapper.text();
        expect(testo).toContain('contributo previdenziale (RT04)');
        expect(testo).toContain('non è una ritenuta d\'acconto');
    });

    // ── Il residuo dell'importazione precedente non deve sopravvivere ─────────────
    test('allegando un PDF dopo un XML, il confronto del file precedente sparisce', async () => {
        axios.post.mockResolvedValueOnce({ data: { ...ESITO_TROVATO, ritenuta: RITENUTA_DAL_FILE } });
        const wrapper = render([FORNITORE_A]);
        await selezionaFile(wrapper, 'parcella.xml');
        expect(wrapper.text()).toContain('Il file dichiara una ritenuta');

        // Un PDF non passa nemmeno dal lettore: esce subito da gestisciFileSelezionato.
        const input = wrapper.find('input[type="file"]');
        const pdf = new File(['%PDF'], 'quietanza.pdf', { type: 'application/pdf' });
        Object.defineProperty(input.element, 'files', { value: [pdf], configurable: true });
        await input.trigger('change');
        await flushPromises();

        expect(wrapper.text(), 'il confronto era del file di prima').not.toContain('Il file dichiara una ritenuta');
    });
});

/**
 * ⚠️ Reperto 17. `erroreImportazione` è lo stato dell'istanza di `useImportaFatturaXml()`,
 * e `resettaFormPerNuovoDocumento()` azzerava tutti gli altri residui dell'importazione
 * tranne lui: il modulo per il documento nuovo si apriva con un messaggio rosso che parla
 * di un file non più allegato. L'amministratore cerca un problema che non esiste.
 */
describe('il reset non lascia in giro l\'errore di lettura del file precedente', () => {
    test('registrando un altro documento, il messaggio rosso del file rifiutato sparisce', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errore: 'Busta danneggiata.' } } });
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'rotto.xml');
        expect(wrapper.text(), 'il file rifiutato deve dirlo').toMatch(/Busta danneggiata|Impossibile leggere/);

        // ⚠️ Si passa dal GESTO: registra, e poi «Registra un'altra fattura» nella modale
        // di successo. Chiamare `resettaFormPerNuovoDocumento()` salterebbe tutto ciò che
        // sta fra il pulsante e l'effetto — è la lezione di questa beta.
        vm.form.righe[0].descrizione = 'Compilata a mano';
        vm.form.righe[0].importo_imponibile = 100;
        await wrapper.vm.$nextTick();

        const opzioni = await premiRegistra(wrapper);
        opzioni.onSuccess!();
        await flushPromises();

        // ⚠️ La modale di successo sta dentro un `<Teleport to="body">`: il suo markup
        // finisce FUORI da `wrapper.element` ed è invisibile a `wrapper.findAll`. Si cerca
        // nel documento, come farebbe l'utente che il pulsante lo vede eccome.
        const altra = Array.from(document.body.querySelectorAll('button'))
            .find((b) => (b.textContent ?? '').includes('Registra un\'altra'));
        expect(altra, 'la modale di successo deve offrire «Registra un\'altra»').toBeTruthy();
        altra!.click();
        await flushPromises();

        expect(wrapper.text()).not.toMatch(/Busta danneggiata|Impossibile leggere/);
    });
});

/**
 * Il reperto 12 per intero, dal gesto: fornitore non trovato, lo creo dal file, e la
 * ritenuta dichiarata dal documento deve **arrivare fino all'anteprima**.
 *
 * ⚠️ `ModalCreaFornitoreDaXml` NON è stubbato in questo file: si monta quello vero, quindi
 * il test attraversa davvero la proposta del regime, il salvataggio e il rientro nella
 * pagina. È l'unico modo di vedere il difetto, che stava proprio nelle giunzioni.
 */
describe('creando il fornitore dal file, la ritenuta non si perde più', () => {
    const ESITO_SENZA_FORNITORE = {
        ...ESITO_TROVATO,
        // € 42,00 di ritenuta al 4% su € 1.050,00 di imponibile: sono i numeri veri del
        // file 07 del collaudo, e il conto torna — quindi il regime si può proporre.
        righe: [{ descrizione: 'Manutenzione impianto', importo_imponibile: 1050, aliquota_iva: 22 }],
        ritenuta: { tipo: 'RT02', importo: 42, aliquota: 4, causale_pagamento: 'W' },
        fornitore: {
            esito: 'non_trovato',
            candidati: [],
            letto_da_xml: { denominazione: 'TERMOTECNICA OMEGA SRL', partita_iva: '01234567897', codice_fiscale: null },
        },
    };

    test('il regime proposto dall\'aliquota arriva fino alla trattenuta in anteprima', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_SENZA_FORNITORE });
        const wrapper = render([]);
        const vm = wrapper.vm as any;

        await selezionaFile(wrapper, 'parcella.xml');
        expect(wrapper.text(), 'il file dichiara e non c\'è ancora nessun fornitore').toContain('Il file dichiara una ritenuta');

        // Apre il modale di creazione, come farebbe l'amministratore dal riquadro «da creare».
        vm.showCreaFornitoreModal = true;
        await flushPromises();

        const spunta = Array.from(document.body.querySelectorAll('input[type=checkbox]'))
            .find((i) => (i.closest('label')?.textContent ?? '').includes('soggetto a ritenuta')) as HTMLInputElement | undefined;
        expect(spunta, 'la casella della ritenuta deve esserci').toBeTruthy();
        expect(spunta!.checked, 'e deve arrivare già proposta, perché i conti del file tornano').toBe(true);

        // Il server accetta: risponde con id e ragione sociale, come fa davvero.
        axios.post.mockResolvedValueOnce({ data: { id: 99, ragione_sociale: 'TERMOTECNICA OMEGA SRL' } });
        const salva = Array.from(document.body.querySelectorAll('button'))
            .find((b) => (b.textContent ?? '').trim().startsWith('Crea'));
        expect(salva, 'il modale deve avere il pulsante di creazione').toBeTruthy();
        salva!.click();
        await flushPromises();

        expect(vm.form.fornitore_id, 'il fornitore creato viene agganciato').toBe(99);

        const creato = vm.fornitoriDisponibili.find((f: any) => f.id === 99);
        expect(creato.soggetto_ritenuta, 'e non nasce più con false scritto a mano').toBe(true);
        expect(creato.tipo_ritenuta).toBe('appalto_4');

        // ⚠️ **La natura del percipiente decide 1019 contro 1020 nel modello F24**, e senza
        // di essa `GeneraDelegheF24Action` ripiega in silenzio su persona fisica: un
        // fornitore creato da un file RT02 (società) avrebbe fatto stampare 1019, mandando
        // il denaro all'Erario sotto un codice che non è il suo. Il file lo dice, quindi va
        // fino in fondo — non basta arrivare al fornitore, deve arrivare al tributo giusto.
        const inviato = axios.post.mock.calls.at(-1)![1] as Record<string, unknown>;
        expect(inviato.natura_percipiente, 'RT02 non è persona fisica: IRES → 1020').toBe('soggetto_ires');

        // ⚠️ Il punto d'arrivo che conta: la trattenuta compare davvero nell'anteprima.
        // € 1.050,00 × 4% = € 42,00, cioè esattamente quanto il file dichiarava.
        expect(vm.totali.ritenuta_cents).toBe(4200);
    });
});

/**
 * ⚠️ Reperto 16, e la diagnosi del reperto era sbagliata: il colpevole non è
 * `usePuliziaErrori` — che si comporta come promette — ma **`removeRiga`**.
 *
 * Gli indici delle voci sono **anche le chiavi degli errori**: `righe.1.conto_id` non
 * nomina *quella* voce, nomina la voce che in quel momento sta in seconda posizione.
 * Togliendone una più in alto le voci scalano e le chiavi no, quindi il percorso smette di
 * puntare allo stesso dato e il composable conclude che il campo è stato **corretto**.
 *
 * Il verso giusto non è un'invenzione: è letteralmente ciò che il server ridirà da sé al
 * salvataggio successivo, perché `StoreFatturaRequest` numera per posizione corrente.
 */
describe('cancellando una riga, gli errori seguono la voce e non la posizione', () => {
    async function conDueRigheEUnErrore(wrapper: ReturnType<typeof render>, chiavi: Record<string, string>) {
        const vm = wrapper.vm as any;
        vm.addRiga();
        await wrapper.vm.$nextTick();
        vm.form.setError(chiavi);
        await flushPromises();
    }

    async function togliRiga(wrapper: ReturnType<typeof render>, numero: number) {
        const cestino = wrapper.find(`button[aria-label="Togli la riga ${numero}"]`);
        expect(cestino.exists(), `il cestino della riga ${numero} deve esserci`).toBe(true);
        await cestino.trigger('click');
        await flushPromises();
    }

    test('tolta la prima voce, l\'errore della seconda resta e si rinumera', async () => {
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;
        await conDueRigheEUnErrore(wrapper, { 'righe.1.conto_id': 'Il capitolo di spesa è obbligatorio.' });

        expect(wrapper.text(), 'prima di cancellare l\'errore si vede').toContain('Il capitolo di spesa è obbligatorio.');

        await togliRiga(wrapper, 1);

        expect(vm.form.errors['righe.1.conto_id'], 'la vecchia chiave non deve sopravvivere').toBeUndefined();
        expect(vm.form.errors['righe.0.conto_id'], 'l\'errore segue la voce, che ora è la prima').toBeTruthy();
        expect(wrapper.text(), 'e resta visibile a schermo').toContain('Il capitolo di spesa è obbligatorio.');
    });

    // ⚠️ Il caso trovato dal critico avversariale nel disegno stesso: con DUE errori su
    // righe diverse e valori diversi, la sola rinumerazione non basta — `usePuliziaErrori`
    // cancella nello stesso tick la chiave appena rinumerata, perché confronta il valore
    // vecchio della chiave con quello nuovo e li trova diversi.
    test('con due errori su righe diverse, quello che resta non viene cancellato dalla pulizia', async () => {
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;
        vm.addRiga();
        await wrapper.vm.$nextTick();

        // Valori DIVERSI sulle due righe: è la condizione che smaschera il difetto.
        vm.form.righe[0].descrizione = 'prima';
        vm.form.righe[1].descrizione = '';
        vm.form.setError({
            'righe.0.descrizione': 'La descrizione è obbligatoria.',
            'righe.1.descrizione': 'La descrizione è obbligatoria.',
        });
        await flushPromises();

        await togliRiga(wrapper, 1);

        expect(vm.form.errors['righe.0.descrizione'], 'la voce rimasta ha ancora il suo errore').toBeTruthy();
        expect(vm.form.errors['righe.1.descrizione'], 'e non resta niente sotto la vecchia chiave').toBeUndefined();
    });

    // ⚠️ Falso positivo dichiarato dall'analisi, e va tenuto tale: cancellare una voce
    // SOTTO quella in errore non deve toccare niente. `splice` non scende sotto l'indice 0.
    test('tolta una voce sotto quella in errore, l\'errore non si muove', async () => {
        const wrapper = render([FORNITORE_A]);
        const vm = wrapper.vm as any;
        await conDueRigheEUnErrore(wrapper, { 'righe.0.conto_id': 'Il capitolo di spesa è obbligatorio.' });

        await togliRiga(wrapper, 2);

        expect(vm.form.errors['righe.0.conto_id'], 'l\'errore era già al posto giusto').toBeTruthy();
    });
});

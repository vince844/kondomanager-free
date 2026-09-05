// @vitest-environment jsdom

/**
 * L'anteprima di modifica ricalcolava la ritenuta di una NC nata da uno storno
 * sull'anagrafica ATTUALE del fornitore, mostrando un numero diverso da quello che il
 * salvataggio produce davvero (Coda 123, scoperta verificando a video la correzione
 * server-side il 04/09/2026).
 *
 * ## Il fatto
 *
 * `FatturaPassivaService::aggiornaFattura()` congela la ritenuta di una NC da storno
 * sull'importo REALE dell'originale, quando l'imponibile non è cambiato (vedi il
 * commento in quel file). Ma `FatturaRegisterEdit.vue` calcolava l'anteprima con
 * `risolviRegimeRitenuta(selectedFornitore, ...)`, che legge le percentuali
 * dell'anagrafica CORRENTE — se il fornitore diventa forfetario fra lo storno e la
 * modifica, l'anteprima mostrava «Nessuna Ritenuta € 0,00» e «Importo a credito
 * € 1.220,00», mentre il salvataggio produceva davvero -€ 40,00 di ritenuta e un netto
 * di € 1.180,00. Stesso difetto già chiuso per la posizione ritenuta (Coda 116):
 * l'anteprima deve rispondere «quanto verrà salvato», non «quanto direbbe la regola di
 * oggi». Misurato dal vivo: registrata una fattura reale (ritenuta 4% su € 1.000,00),
 * stornata, il fornitore reso forfetario, riaperta la NC in modifica.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import FatturaRegisterEdit from './FatturaRegisterEdit.vue';

(globalThis as any).route = (n: string) => `/${n}`;

const stubsComuni = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    FatturaRegistrazioneGuide: true,
    WidgetDoubleLock: true,
    ModalSpesaImprevista: true,
    ModalOverrideBudget: true,
    ModalImportaXml: true,
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: {
        name: 'MoneyInput',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
};

/** Il fornitore com'è ORA: diventato forfetario dopo lo storno — esclude la ritenuta per legge. */
const FORNITORE_ORA_FORFETARIO = {
    id: 8, ragione_sociale: 'Ditta pulizia de filippo',
    soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4', regime_forfetario: true,
};

const CONTO = { id: 55, nome: 'Pulizia e manutenzione scale', residuo_budget: 94200, gia_versato_cents: 0, ultimi_movimenti: [] };
const BANCA = { id: 7, nome: 'Conto Corrente', saldo_attuale: -7056 };

/** La NC come risulta davvero a database dopo lo storno: -1.000,00 € + IVA, -40,00 € di ritenuta congelata. */
function ncDaStorno(overrides: Record<string, unknown> = {}) {
    return {
        id: 240,
        fornitore_id: FORNITORE_ORA_FORFETARIO.id,
        fornitore: FORNITORE_ORA_FORFETARIO,
        esercizio_id: 41,
        gestione_id: 35,
        tipo_documento: 'nota_credito',
        is_pregresso: false,
        numero_documento: 'STORNO-TEST-123-VIDEO',
        numero_protocollo: 'NC-2026-0001',
        data_documento: '2026-09-04',
        data_scadenza: '2026-09-04',
        conto_corrente_id: BANCA.id,
        modalita_pagamento: 'bonifico',
        iban_fornitore: null,
        stato_approvazione: 'approvata',
        importo_imponibile: -100000,
        importo_iva: -22000,
        importo_ritenuta: -4000,
        totale_documento: -122000,
        netto_a_pagare: -118000,
        dati_extra: {
            fiscal: { ritenuta_details: { aliquota: 4, codice_tributo: '1020', imponibile_calcolo: 100000 } },
            competenza: null,
            override_budget: null,
            nota_storno: 'Storno automatico a compensazione della fattura ID: 239',
        },
        righe: [{
            id: 300,
            descrizione: '[STORNO] Appalto pulizia scale',
            conto_id: CONTO.id,
            immobile_id: null,
            importo_imponibile: -100000,
            importo_iva: -22000,
            aliquota_iva: '22.00',
            concorre_base_ritenuta: true,
        }],
        documenti: [],
        coperture: [],
        ...overrides,
    };
}

function renderEdit(fattura: Record<string, unknown>, notaStornoOriginale: Record<string, unknown> | null = null) {
    return mount(FatturaRegisterEdit, {
        props: {
            condominio: { id: 18, nome: 'Condominio test' },
            condomini: [{ id: 18, nome: 'Condominio test' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }],
            fornitori: [FORNITORE_ORA_FORFETARIO],
            conti: [CONTO],
            banche: [BANCA],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
            fattura,
            nota_storno_originale: notaStornoOriginale,
        },
        global: { stubs: stubsComuni, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('FatturaRegisterEdit — la ritenuta di una NC da storno resta congelata anche nell\'anteprima', () => {
    test('col fornitore ormai forfetario, l\'anteprima mostra comunque la ritenuta congelata, non zero', () => {
        const wrapper = renderEdit(ncDaStorno());
        const vm = wrapper.vm as any;

        // ⚠️ Prima della correzione: ritenuta_cents = 0 (ricalcolo sul forfetario attuale),
        // netto_cents = 122000. L'amministratore vedeva un numero diverso da quello salvato.
        expect(vm.totali.ritenuta_cents).toBe(4000);
        expect(vm.totali.netto_cents).toBe(118000);
    });

    test('l\'etichetta mostra "Ritenuta d\'Acconto", non "Nessuna Ritenuta"', () => {
        const wrapper = renderEdit(ncDaStorno());

        expect(wrapper.text()).toContain('Ritenuta d\'Acconto');
        expect(wrapper.text()).not.toContain('Nessuna Ritenuta');
    });

    test('il controesempio che tiene stretta la correzione: una NC GENUINA (non da storno) non congela nulla', () => {
        // Senza `dati_extra.nota_storno`, il ramo di congelamento non deve scattare: una NC
        // genuina su un fornitore soggetto a ritenuta resta a zero per default (design §8
        // punto 3), esattamente come oggi.
        const wrapper = renderEdit(ncDaStorno({
            dati_extra: { fiscal: {}, competenza: null, override_budget: null },
            importo_ritenuta: 0,
        }));
        const vm = wrapper.vm as any;

        expect(vm.totali.ritenuta_cents).toBe(0);
    });

    test('se l\'imponibile delle righe è stato alterato, il congelamento non si applica più', () => {
        // Specchio del caso anomalo lato server: un imponibile diverso da quello
        // dell'originale rompe la natura di specchio della NC da storno, e la preview torna
        // al ricalcolo dal vivo (qui: zero, perché il fornitore è forfetario).
        const wrapper = renderEdit(ncDaStorno());
        const vm = wrapper.vm as any;

        vm.form.righe[0].importo_imponibile = 2000; // era 1000 nell'originale

        expect(vm.totali.ritenuta_cents).toBe(0);
    });
});

describe('FatturaRegisterEdit — i controlli che il congelamento rende inerti sono disabilitati, non solo ignorati', () => {
    // ⚠️ **Trovato dalla revisione avversariale del 05/09/2026.** Qui il fornitore resta
    // soggetto a ritenuta e NON forfetario: l'anagrafica non è cambiata, ma la NC nasce
    // comunque da uno storno, quindi il congelamento si attiva lo stesso. In questo caso —
    // il più comune, quello SENZA alcun cambio di anagrafica — il riquadro "applica
    // ritenuta" e la casella "concorre alla base" restavano pienamente cliccabili: sembrava
    // un controllo funzionante e non lo era, perché il salvataggio li ignorava in silenzio.
    const FORNITORE_SOGGETTO_NON_FORFETARIO = {
        id: 8, ragione_sociale: 'Ditta pulizia de filippo',
        soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4', regime_forfetario: false,
    };

    function renderEditFornitoreAttivo(overrides: Record<string, unknown> = {}) {
        return renderEdit(ncDaStorno({
            fornitore_id: FORNITORE_SOGGETTO_NON_FORFETARIO.id,
            fornitore: FORNITORE_SOGGETTO_NON_FORFETARIO,
            ...overrides,
        }));
    }

    test('il congelamento è attivo anche senza alcun cambio di anagrafica', () => {
        const wrapper = renderEditFornitoreAttivo();
        const vm = wrapper.vm as any;

        expect(vm.congelamentoRitenutaAttivo).toBe(true);
        expect(vm.totali.ritenuta_cents).toBe(4000);
    });

    test('il toggle "applica ritenuta d\'acconto" è disabilitato, con la spiegazione al posto della domanda', () => {
        const wrapper = renderEditFornitoreAttivo();

        const checkbox = wrapper.find('input[type="checkbox"]');
        expect(checkbox.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('la ritenuta resta quella dell\'originale');
        // Il select del motivo di esclusione non deve comparire: non c'è nessuna scelta da
        // giustificare quando il toggle non produce alcun effetto.
        expect(wrapper.text()).not.toContain('Motivo dell\'esclusione');
    });

    test('la casella "concorre alla base ritenuta" della riga è disabilitata', () => {
        const wrapper = renderEditFornitoreAttivo();

        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        const concorreBase = checkboxes.find(c => c.element.parentElement?.textContent?.includes('Concorre alla base ritenuta'));

        expect(concorreBase).toBeDefined();
        expect(concorreBase!.attributes('disabled')).toBeDefined();
    });

    test('il riquadro resta visibile anche col fornitore ormai FORFETARIO, non solo con uno ancora soggetto', () => {
        // ⚠️ **Trovato verificando a video il fix qui sopra, il 05/09/2026 — non da un
        // rilievo della revisione avversariale.** `fornitoreRitenutaAttiva` è false per un
        // forfetario (`soggetto_ritenuta && !regime_forfetario`): l'intero riquadro — la
        // checkbox, il messaggio «la ritenuta resta quella dell'originale», ed entrambe le
        // checkbox di riga — spariva del tutto, proprio nello scenario più comune di questa
        // coda (il fornitore diventato forfetario dopo lo storno). Il numero nel riepilogo
        // dei totali restava corretto (non dipende da `fornitoreRitenutaAttiva`), ma senza
        // il riquadro l'amministratore lo vedeva senza alcuna spiegazione del perché una
        // ritenuta compare su un fornitore che sembra non più soggetto.
        const FORNITORE_ORA_FORFETARIO = {
            id: 8, ragione_sociale: 'Ditta pulizia de filippo',
            soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4', regime_forfetario: true,
        };
        const wrapper = renderEdit(ncDaStorno({
            fornitore_id: FORNITORE_ORA_FORFETARIO.id,
            fornitore: FORNITORE_ORA_FORFETARIO,
        }));
        const vm = wrapper.vm as any;

        expect(vm.congelamentoRitenutaAttivo).toBe(true);
        expect(wrapper.text()).toContain('Applica ritenuta');
        expect(wrapper.text()).toContain('la ritenuta resta quella dell\'originale');

        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        expect(checkboxes.length).toBeGreaterThan(0, 'Le checkbox non devono sparire solo perché il fornitore è forfetario.');
        checkboxes.forEach(c => expect(c.attributes('disabled')).toBeDefined());
    });

    test('il controesempio: su una fattura ORDINARIA (mai congelata) i due controlli restano attivi', () => {
        // Tiene stretta la correzione: se la guardia fosse troppo larga (es. "sempre
        // disabilitato quando il fornitore è soggetto a ritenuta"), una fattura normale
        // perderebbe un controllo che oggi funziona e serve davvero.
        const wrapper = renderEdit({
            id: 91,
            fornitore_id: FORNITORE_SOGGETTO_NON_FORFETARIO.id,
            fornitore: FORNITORE_SOGGETTO_NON_FORFETARIO,
            esercizio_id: 41,
            gestione_id: 35,
            tipo_documento: 'fattura',
            is_pregresso: false,
            numero_documento: 'FT-2026-0099',
            numero_protocollo: 'FTP-2026-00099',
            data_documento: '2026-09-04',
            data_scadenza: '2026-09-14',
            conto_corrente_id: BANCA.id,
            modalita_pagamento: 'bonifico',
            iban_fornitore: null,
            stato_approvazione: 'approvata',
            importo_imponibile: 100000,
            importo_iva: 22000,
            importo_ritenuta: 4000,
            totale_documento: 122000,
            netto_a_pagare: 118000,
            dati_extra: { fiscal: { ritenuta_details: { aliquota: 4, codice_tributo: '1020', imponibile_calcolo: 100000 } }, competenza: null, override_budget: null },
            righe: [{
                id: 300, descrizione: 'Appalto', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 100000, importo_iva: 22000, aliquota_iva: '22.00', concorre_base_ritenuta: true,
            }],
            documenti: [],
            coperture: [],
        });
        const vm = wrapper.vm as any;

        expect(vm.congelamentoRitenutaAttivo).toBe(false);
        const checkbox = wrapper.find('input[type="checkbox"]');
        expect(checkbox.attributes('disabled')).toBeUndefined();
    });
});

describe('FatturaRegisterEdit — il tipo documento è un\'etichetta di sola lettura, non un selettore', () => {
    // ⚠️ Trovato dalla revisione avversariale del 05/09/2026: `tipo_documento` è immutabile
    // lato server in modifica (UpdateFatturaRequest non lo accetta, aggiornaFattura lo
    // rilegge dal database), ma il vecchio toggle restava cliccabile e pilotava tutta la
    // simulazione di Coda 122 — un click spegneva o accendeva sforo, cassa ed etichette su
    // un documento il cui tipo reale non sarebbe mai cambiato. Prima correzione: due caselle
    // disabilitate. Su segnalazione diretta di Vincenzo (visivamente ancora un controllo, non
    // uno stato): sostituita con un'unica etichetta, sullo stesso pattern del campo Fornitore.
    test('non ci sono più pulsanti cliccabili per cambiare tipo_documento', () => {
        const wrapper = renderEdit(ncDaStorno());

        const bottoniTipo = wrapper.findAll('button').filter(b =>
            /^(Fattura|Nota di credito)$/.test(b.text().trim())
        );

        expect(bottoniTipo.length).toBe(0);
    });

    test('il tipo mostrato riflette comunque quello vero del documento', () => {
        const wrapper = renderEdit(ncDaStorno());

        expect(wrapper.text()).toContain('Nota di credito');
    });
});

describe('FatturaRegisterEdit — la tagliola dei due salvataggi, anche nell\'anteprima', () => {
    // ⚠️ **Trovato verificando a video la correzione backend, il 05/09/2026 — non da un
    // rilievo della revisione avversariale, ma dal video stesso.** Il fix server-side legge
    // l'originale immutabile (`stornata_da_id`), ma il frontend confrontava contro
    // `props.fattura` — cioè la NC stessa, mutabile. Riproducendo dal vivo la tagliola (un
    // primo salvataggio con l'imponibile sbagliato azzera la ritenuta sulla NC; il secondo,
    // che rimette l'imponibile giusto, dovrebbe far tornare il congelamento): il DATO
    // SALVATO era corretto (il backend legge l'originale), ma l'ANTEPRIMA prima di quel
    // secondo salvataggio mostrava ancora «Nessuna Ritenuta», perché confrontava il nuovo
    // imponibile (giusto) contro quello della NC (ormai corrotto dal primo salvataggio),
    // non contro l'originale. Corretto passando `nota_storno_originale` dal controller.
    test('con l\'imponibile della NC corrotto dal primo salvataggio, l\'anteprima legge comunque l\'originale', () => {
        const nc = ncDaStorno({
            // La NC così com'è DOPO il primo salvataggio della tagliola: imponibile
            // alterato a 1.200,00 (mismatch), ritenuta già persa (il ramo dal vivo l'ha
            // azzerata perché il fornitore era forfetario in quel momento).
            importo_imponibile: -120000,
            importo_iva: -26400,
            importo_ritenuta: 0,
            dati_extra: { fiscal: {}, competenza: null, override_budget: null, nota_storno: 'Storno automatico a compensazione della fattura ID: 239' },
        });
        const originale = {
            // L'originale, mai toccato: imponibile e ritenuta VERI.
            importo_imponibile: 100000,
            importo_ritenuta: 4000,
            ritenuta_details: { aliquota: 4, codice_tributo: '1020', imponibile_calcolo: 100000 },
        };

        const wrapper = renderEdit(nc, originale);
        const vm = wrapper.vm as any;

        // L'amministratore rimette l'imponibile giusto, 1.000,00.
        vm.form.righe[0].importo_imponibile = 1000;

        expect(vm.congelamentoRitenutaAttivo).toBe(true, 'Il confronto deve riuscire contro l\'originale (1.000,00), non contro la NC corrotta (1.200,00).');
        expect(vm.totali.ritenuta_cents).toBe(4000);
        expect(wrapper.text()).toContain('Ritenuta d\'Acconto');
        expect(wrapper.text()).not.toContain('Nessuna Ritenuta');
    });

    test('senza un originale raggiungibile, il ripiego sulla NC stessa non esplode — solo non protegge dalla tagliola', () => {
        // Il controesempio che documenta il limite noto: una nota nata prima che lo storno
        // scrivesse `stornata_da_id`, o il cui originale è stato comunque eliminato.
        const nc = ncDaStorno();
        const wrapper = renderEdit(nc, null);
        const vm = wrapper.vm as any;

        expect(vm.congelamentoRitenutaAttivo).toBe(true);
        expect(vm.totali.ritenuta_cents).toBe(4000);
    });
});

// @vitest-environment jsdom

/**
 * I collegamenti del gestionale verso le pagine del fornitore (Coda 402).
 *
 * Le pagine del fornitore chiedono i permessi delle anagrafiche (`FornitorePolicy`): la scheda di modifica
 * (`fornitori.edit`) «Modifica anagrafiche», la creazione (`fornitori.store`) «Crea anagrafiche». Il gestionale si apre
 * con il solo accesso al pannello, e i suoi moduli offrono il collegamento o il pulsante solo a chi ha il permesso della
 * rotta: «Completa l'anagrafica» (codice tributo indeterminabile, nella registrazione e nella modifica di una fattura),
 * «Apri l'anagrafica del fornitore» (il file e l'anagrafica non coincidono sulla ritenuta), «Crea fornitore da questo
 * file» (il fornitore del file non c'è), e il nome del fornitore da classificare nella pagina dell'F24. Senza, la frase
 * resta vera e non indica un comando che non c'è. I ruoli di serie hanno i due permessi.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';

// Ogni pagina montata si smonta alla fine del suo test: una risposta che arriva dopo non tocca più il documento del test successivo.
enableAutoUnmount(afterEach);

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { flash: {}, auth: { user: { roles: ['collaboratore'], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const FatturaRegisterNew = (await import('./FatturaRegisterNew.vue')).default;
const FatturaRegisterEdit = (await import('./FatturaRegisterEdit.vue')).default;
const F24List = (await import('../f24/F24List.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa,
    PageHeaderGuide: passa,
    FatturaRegistrazioneGuide: true,
    WidgetDoubleLock: true,
    ModalSpesaImprevista: true,
    ModalOverrideBudget: true,
    ModalImportaXml: {
        props: ['show', 'files'],
        emits: ['update:show', 'aggiungi', 'rimuovi', 'seleziona'],
        template: '<div />',
    },
    ModalCreaFornitoreDaXml: true,
    ConfirmDialog: true,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const CONTO = { id: 55, nome: 'Pulizie', codice: 'A.1', conto_contabile_id: 141 };
/** Un fornitore soggetto a ritenuta, con il regime e senza la natura del percipiente: il codice tributo non si decide. */
const SENZA_NATURA = {
    id: 10, ragione_sociale: "Studio D'Àngelo & C.", partita_iva: '01234567897', soggetto_ritenuta: true, regime_forfetario: false,
    tipo_ritenuta: 'professionisti_20', natura_percipiente: null, codice_tributo: null, perc_ritenuta: 20, perc_imponibile_ritenuta: 100,
};

const propsComuni = {
    condominio: { id: 28, nome: 'Condominio Demo KM' },
    condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
    esercizio: { id: 41, nome: '2026', stato: 'aperto' },
    esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
    conti: [CONTO],
    banche: [],
    immobili: [],
    debiti_patrimoniali: [],
    fatture_pregresse_registrate: [],
    fondi_riserva: [],
    capienza_rata_zero: 0,
    incassato_rata_zero: 0,
};

function nuova(fornitori: Record<string, unknown>[]) {
    return mount(FatturaRegisterNew, {
        props: { ...propsComuni, gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }], fornitori } as never,
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

function modifica(fornitore: Record<string, unknown>) {
    const fattura = {
        id: 91, fornitore_id: fornitore.id, fornitore, esercizio_id: 41, gestione_id: 35, tipo_documento: 'fattura', is_pregresso: false,
        numero_documento: 'FT-2026-0044', numero_protocollo: 'FTP-2026-00009', data_documento: '2026-07-10', data_scadenza: '2026-08-09',
        conto_corrente_id: null, modalita_pagamento: 'bonifico', iban_fornitore: null, stato_approvazione: 'approvata',
        importo_imponibile: 100_000, importo_iva: 22_000, importo_ritenuta: 20_000, totale_documento: 122_000, netto_a_pagare: 102_000,
        dati_extra: { fiscal: {}, competenza: null, override_budget: null },
        righe: [{ id: 300, descrizione: 'Consulenza', conto_id: CONTO.id, immobile_id: null, importo_imponibile: 100_000, importo_iva: 22_000, aliquota_iva: '22.00', concorre_base_ritenuta: true }],
        documenti: [], coperture: [],
    };

    return mount(FatturaRegisterEdit, {
        props: { ...propsComuni, gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }], fornitori: [fornitore], fattura } as never,
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

/** Il file XML letto: come lo restituisce la lettura del server, con il fornitore trovato o no. */
async function leggiIlFile(wrapper: ReturnType<typeof nuova>, esito: Record<string, unknown>) {
    axios.post.mockResolvedValueOnce({ data: esito });
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [new File(['<xml/>'], 'fattura.xml', { type: 'text/xml' })], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

const ESITO = {
    documento: {
        tipo_documento: 'fattura', numero_documento: 'FT-XML-1', data_documento: '2026-06-10', data_scadenza: '2026-07-10',
        modalita_pagamento: 'bonifico', iban_fornitore: 'IT43X0100003245100000000001',
    },
    righe: [{ descrizione: 'Manutenzione impianto', importo_imponibile: 1050, aliquota_iva: 22 }],
    ritenuta: { tipo: 'RT02', importo: 42, aliquota: 4, causale_pagamento: 'W' },
    avvisi: { lotto_con_altri_documenti: 0, righe_non_quadrano_col_riepilogo: false, scarto_righe_riepilogo_cents: 0 },
};
const NON_SOGGETTO = { id: 20, ragione_sociale: 'Alfa Servizi Srl', partita_iva: '01234567897', soggetto_ritenuta: false };

const MODIFICA = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche'];
const CREA = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];
const SOLO_PANNELLO = ['Accesso pannello amministratore'];
const CONSULTA = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];

beforeEach(() => {
    chiGuarda.permessi = [];
    axios.post.mockReset();
    document.body.innerHTML = '';
});

describe('«Completa l\'anagrafica» con il codice tributo indeterminabile — chiede «Modifica anagrafiche»', () => {
    test('nella registrazione, con «Modifica anagrafiche» il collegamento porta alla scheda del fornitore', async () => {
        chiGuarda.permessi = MODIFICA;
        const pagina = nuova([SENZA_NATURA]);
        (pagina.vm as unknown as { form: { fornitore_id: number } }).form.fornitore_id = 10;
        await flushPromises();

        expect(pagina.text()).toContain('Codice tributo indeterminabile.');
        expect(pagina.find('a[href="/admin.fornitori.edit"]').exists()).toBe(true);
        expect(pagina.text()).toContain("Completa l'anagrafica oppure conferma per procedere comunque.");
    });

    test('nella registrazione, con il solo accesso al gestionale la frase chiede di confermare, senza il collegamento', async () => {
        chiGuarda.permessi = SOLO_PANNELLO;
        const pagina = nuova([SENZA_NATURA]);
        (pagina.vm as unknown as { form: { fornitore_id: number } }).form.fornitore_id = 10;
        await flushPromises();

        expect(pagina.text()).toContain('Codice tributo indeterminabile.');
        expect(pagina.find('a[href="/admin.fornitori.edit"]').exists()).toBe(false);
        expect(pagina.text()).not.toContain("Completa l'anagrafica");
        expect(pagina.text()).toContain('Conferma per procedere comunque.');
    });

    test('con la consultazione delle anagrafiche e «Crea anagrafiche», senza «Modifica», il collegamento non c\'è', async () => {
        chiGuarda.permessi = CONSULTA;
        const pagina = nuova([SENZA_NATURA]);
        (pagina.vm as unknown as { form: { fornitore_id: number } }).form.fornitore_id = 10;
        await flushPromises();

        expect(pagina.text()).toContain('Codice tributo indeterminabile.');
        expect(pagina.find('a[href="/admin.fornitori.edit"]').exists()).toBe(false);
        expect(pagina.text()).toContain('Conferma per procedere comunque.');
    });

    test('nella modifica di una fattura, il collegamento c\'è con «Modifica anagrafiche» e non con il solo accesso', async () => {
        chiGuarda.permessi = MODIFICA;
        const conPermesso = modifica(SENZA_NATURA);
        await flushPromises();
        expect(conPermesso.text()).toContain('Codice tributo indeterminabile.');
        expect(conPermesso.find('a[href="/admin.fornitori.edit"]').exists()).toBe(true);

        chiGuarda.permessi = SOLO_PANNELLO;
        const senza = modifica(SENZA_NATURA);
        await flushPromises();
        expect(senza.text()).toContain('Codice tributo indeterminabile.');
        expect(senza.find('a[href="/admin.fornitori.edit"]').exists()).toBe(false);
        expect(senza.text()).toContain('Conferma per procedere comunque.');
    });
});

describe('«Apri l\'anagrafica del fornitore» quando il file e il modulo non coincidono — chiede «Modifica anagrafiche»', () => {
    const trovato = { ...ESITO, fornitore: { esito: 'trovato', candidati: [{ id: 20, ragione_sociale: 'Alfa Servizi Srl' }], letto_da_xml: { denominazione: 'ALFA SERVIZI SRL', partita_iva: '01234567897', codice_fiscale: null } } };

    test('con «Modifica anagrafiche» il collegamento c\'è', async () => {
        chiGuarda.permessi = MODIFICA;
        const pagina = nuova([NON_SOGGETTO]);
        await leggiIlFile(pagina, trovato);

        expect(pagina.text()).toContain('Il file dichiara una ritenuta');
        expect(pagina.text()).toContain("Apri l'anagrafica del fornitore");
    });

    test('con il solo accesso al gestionale il confronto resta, senza il collegamento', async () => {
        chiGuarda.permessi = SOLO_PANNELLO;
        const pagina = nuova([NON_SOGGETTO]);
        await leggiIlFile(pagina, trovato);

        expect(pagina.text()).toContain('Il file dichiara una ritenuta');
        expect(pagina.text()).not.toContain("Apri l'anagrafica del fornitore");
    });
});

describe('«Apri l\'anagrafica del fornitore» quando il file e il regime del fornitore danno importi diversi — chiede «Modifica anagrafiche»', () => {
    const SOGGETTO = { ...SENZA_NATURA, id: 30, natura_percipiente: 'persona_fisica_irpef' };
    const trovato = { ...ESITO, fornitore: { esito: 'trovato', candidati: [{ id: 30, ragione_sociale: SOGGETTO.ragione_sociale }], letto_da_xml: { denominazione: 'STUDIO D ANGELO', partita_iva: '01234567897', codice_fiscale: null } } };

    test('con «Modifica anagrafiche» il collegamento c\'è, e senza resta il confronto', async () => {
        chiGuarda.permessi = MODIFICA;
        const conPermesso = nuova([SOGGETTO]);
        await leggiIlFile(conPermesso, trovato);
        expect(conPermesso.text()).toContain("Il file e l'anagrafica non coincidono.");
        expect(conPermesso.text()).toContain("Apri l'anagrafica del fornitore");

        chiGuarda.permessi = SOLO_PANNELLO;
        const senza = nuova([SOGGETTO]);
        await leggiIlFile(senza, trovato);
        expect(senza.text()).toContain("Il file e l'anagrafica non coincidono.");
        expect(senza.text()).not.toContain("Apri l'anagrafica del fornitore");
    });
});

describe('«Crea fornitore da questo file» — chiede «Crea anagrafiche»', () => {
    const nonTrovato = { ...ESITO, fornitore: { esito: 'non_trovato', candidati: [], letto_da_xml: { denominazione: 'TERMOTECNICA OMEGA SRL', partita_iva: '01234567897', codice_fiscale: null } } };

    test('con «Crea anagrafiche» la pagina offre di crearlo dal file', async () => {
        chiGuarda.permessi = CREA;
        const pagina = nuova([NON_SOGGETTO]);
        await leggiIlFile(pagina, nonTrovato);

        expect(pagina.text()).toContain('Nessun fornitore trovato per TERMOTECNICA OMEGA SRL');
        expect(pagina.text()).toContain('o crearlo ora senza lasciare questa pagina.');
        expect(pagina.findAll('button').some((b) => b.text() === 'Crea fornitore da questo file')).toBe(true);
    });

    test('senza «Crea anagrafiche» la pagina dice di sceglierlo, senza il pulsante che risponderebbe 403', async () => {
        chiGuarda.permessi = MODIFICA;
        const pagina = nuova([NON_SOGGETTO]);
        await leggiIlFile(pagina, nonTrovato);

        expect(pagina.text()).toContain('Nessun fornitore trovato per TERMOTECNICA OMEGA SRL');
        expect(pagina.text()).toContain('Puoi selezionarlo qui sopra se esiste già con un altro nome.');
        expect(pagina.text()).not.toContain('crearlo ora');
        expect(pagina.findAll('button').some((b) => b.text() === 'Crea fornitore da questo file')).toBe(false);
    });
});

describe('l\'F24: i fornitori da classificare portano alla loro scheda con «Modifica anagrafiche»', () => {
    function f24() {
        return mount(F24List, {
            props: {
                condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], esercizio: { id: 41, nome: '2026' },
                deleghe: { data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } },
                riepilogo: { da_versare_cents: 0, versato_cents: 0, scadute: 0, prossima_scadenza: null },
                soglie: { appalti_cents: 0, professionisti_cents: 0 }, da_calcolare: { numero: 0, totale_cents: 0 },
                fornitori_da_classificare: [{ id: 10, ragione_sociale: "Studio D'Àngelo & C." }, { id: null, ragione_sociale: 'Fornitore cancellato' }],
            } as never,
            global: {
                stubs: {
                    ...stubs, DataTable: true, Alert: true, PageHeaderGuide: true, MovimentiLayout: passa, RitenuteF24Guide: true,
                    TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: passa,
                },
                mocks: { route: (n: string) => `/${n}` },
            },
        });
    }

    test('con «Modifica anagrafiche» il nome è il collegamento alla scheda', () => {
        chiGuarda.permessi = MODIFICA;
        const pagina = f24();

        const collegamento = pagina.find('a[href="/admin.fornitori.edit"]');
        expect(collegamento.exists()).toBe(true);
        expect(collegamento.text()).toBe("Studio D'Àngelo & C.");
    });

    test('con il solo accesso al gestionale il nome resta, senza il collegamento', () => {
        chiGuarda.permessi = SOLO_PANNELLO;
        const pagina = f24();

        expect(pagina.find('a[href="/admin.fornitori.edit"]').exists()).toBe(false);
        expect(pagina.text()).toContain("Studio D'Àngelo & C.");
        expect(pagina.text()).toContain('Fornitore cancellato');
    });
});

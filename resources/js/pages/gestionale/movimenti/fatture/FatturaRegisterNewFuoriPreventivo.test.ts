// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 693 (decisioni 532 e 533): la pagina di registrazione con il pulsante «Fuori preventivo», fra la finestra «Configura nuova voce di spesa», la
 * finestra dello sforo e l'invio.
 *
 * - (a) Dopo un rifiuto del server sui dati della finestra, «Registra» la riapre, con i dati di prima: prima i dati legali risultavano già scritti e la
 *   pagina rimandava gli stessi dati, rifiutati di nuovo.
 * - (c) La finestra riceve la gestione della fattura (nome e tipo).
 * - (d) Con l'urgenza, un'altra riga della stessa fattura che sfora una voce apre la finestra dello sforo, e lo sforo registrato è quello di tutta la
 *   fattura (l'imprevisto e le voci), con la strategia scelta per le voci; la strategia dell'imprevisto resta nella finestra «Fuori preventivo».
 * - Seconda passata: la finestra «Fuori preventivo» riceve il segno delle righe addebitate a un'unità, e spegne «Usa fondo riserva» (`ModalSpesaImprevista.test.ts`).
 * - (e) Una riga addebitata a un'unità non conta sul capitolo che ha scelto: non accende lo sforo né «Autorizza e Registra» (il servizio non la mette su
 *   quella voce); e il valore della parte comune che la finestra mostra, e che la pagina registra come sforo, non somma le righe imprevisto di un'unità.
 *
 * La pagina è quella vera, con `useForm` vero; si sostituisce solo `post()`, come in `FatturaRegisterNew.test.ts`. Le due finestre sono stub che
 * ricevono le prop della pagina ed emettono `confirm` come le vere.
 *
 * Cosa resta scoperto: le finestre vere (`ModalSpesaImprevista.test.ts`; la finestra dello sforo non cambia); il server (`FuoriPreventivoFinestraTest`);
 * l'aspetto a video.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

const inviato = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const originale = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...originale,
        Head: { template: '<span />' },
        usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
        useForm: ((...args: unknown[]) => {
            const form = (originale.useForm as (...a: unknown[]) => Record<string, unknown>)(...args);
            form.post = (url: string, opzioni: Record<string, unknown>) => {
                inviato.post(url, opzioni);
            };

            return form;
        }) as unknown as typeof originale.useForm,
    };
});

import FatturaRegisterNew from './FatturaRegisterNew.vue';

(globalThis as any).route = (n: string) => `/${n}`;
// Dopo un rifiuto la pagina porta in vista il riepilogo degli errori: jsdom non scorre.
(Element.prototype as any).scrollIntoView = () => undefined;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    FatturaRegistrazioneGuide: true, WidgetDoubleLock: true, ModalImportaXml: true,
    ModalSpesaImprevista: {
        name: 'ModalSpesaImprevista',
        props: ['show', 'fornitoreNome', 'condominioId', 'fondiRiserva', 'importoImprevisto', 'gestione', 'precedente', 'mode', 'hasSpesePrivate'],
        emits: ['update:show', 'confirm'],
        template: '<div v-if="show" data-test="finestra-fuori-preventivo" />',
    },
    ModalOverrideBudget: {
        name: 'ModalOverrideBudget',
        props: ['show', 'sforoTotale', 'vociInSforo', 'hasSpesePrivate', 'fondiRiserva', 'isProcessing'],
        emits: ['update:show', 'confirm'],
        template: '<div v-if="show" data-test="finestra-sforo" />',
    },
    Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' }, 'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" />' },
};

// «Pulizie scale» ha € 100,00 di residuo: una riga da € 200,00 + IVA 22 % (€ 244,00) la sfora di € 144,00.
const VOCE = { id: 55, nome: 'Pulizie scale', residuo_budget: 10_000, gia_versato_cents: 0, ultimi_movimenti: [] };
const UNITA = { id: 7, nome: 'Interno 1', etichetta: 'Interno 1' };

function render(gestione = { id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }) {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio' }, condomini: [{ id: 28, nome: 'Condominio' }],
            esercizio: { id: 41, nome: 'Esercizio 2026', stato: 'aperto' }, esercizi: [{ id: 41, nome: 'Esercizio 2026', stato: 'aperto' }],
            gestioni: [gestione],
            fornitori: [{ id: 10, ragione_sociale: 'Idraulica Srl', soggetto_ritenuta: false, regime_forfetario: false }],
            conti: [VOCE], banche: [], immobili: [UNITA], debiti_patrimoniali: [], fatture_pregresse_registrate: [],
            fondi_riserva: [{ id: 90, nome: 'Fondo di riserva', saldo_attuale: 500_000 }], capienza_rata_zero: 0, incassato_rata_zero: 0,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

const RIGA_IMPREVISTO = { descrizione: 'Riparazione colonna', conto_id: null, immobile_id: null, importo_imponibile: 1000, aliquota_iva: 22, is_sopravvenienza: true, concorre_base_ritenuta: true };
const RIGA_VOCE = { descrizione: 'Pulizie straordinarie', conto_id: 55, immobile_id: null, importo_imponibile: 200, aliquota_iva: 22, is_sopravvenienza: false, concorre_base_ritenuta: true };

async function conRighe(w: VueWrapper, righe: Record<string, unknown>[]) {
    const vm = w.vm as any;
    vm.form.fornitore_id = 10;
    await flushPromises();
    vm.form.righe = righe.map((r) => ({ ...r }));
    await flushPromises();
}

const pulsanteRegistra = (w: VueWrapper) => w.findAll('button').find((b) => /Registra Documento|Autorizza e Registra/.test(b.text()))!;

async function premiRegistra(w: VueWrapper) {
    await pulsanteRegistra(w).trigger('click');
    await flushPromises();
}

/** I dati che la finestra «Fuori preventivo» emette, come quella vera dopo la correzione. */
const datiFinestra = (origine: 'gestione_corrente' | 'delibera_assembleare', strategia: string, fondo: number | null = null) => ({
    nome_voce: 'Riparazione colonna', tabella_millesimale_id: 3, percentuale_proprietario: 100, percentuale_inquilino: 0, percentuale_usufruttuario: 0,
    show_advanced_percentages: false, origine_decisionale: origine, data_assemblea: origine === 'delibera_assembleare' ? '2026-10-15' : '', strategia_rientro: strategia,
    fondo_patrimoniale_id: fondo, motivazione_urgenza: origine === 'gestione_corrente' ? 'Perdita improvvisa dalla colonna' : '', tipo_ripartizione: 'millesimale',
    is_ordinario: origine === 'gestione_corrente', richiede_copertura: true, motivazione_sforo: origine === 'gestione_corrente' ? 'Perdita improvvisa dalla colonna' : '',
});

const finestraFuoriPreventivo = (w: VueWrapper) => w.findComponent({ name: 'ModalSpesaImprevista' });
const finestraSforo = (w: VueWrapper) => w.findComponent({ name: 'ModalOverrideBudget' });

async function confermaFuoriPreventivo(w: VueWrapper, dati: Record<string, unknown>) {
    finestraFuoriPreventivo(w).vm.$emit('confirm', dati);
    await flushPromises();
}

describe('693 (d) — con l\'urgenza lo sforo di un\'altra riga', () => {
    test('l\'urgenza e una riga che sfora «Pulizie scale»: dopo la finestra «Fuori preventivo» si apre quella dello sforo, per le sole voci (€ 144,00)', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, RIGA_VOCE]);
        await premiRegistra(w);
        expect(finestraFuoriPreventivo(w).props('show')).toBe(true);

        inviato.post.mockClear();
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'fondo_riserva', 90));

        expect(inviato.post, 'la fattura parte senza la motivazione dello sforo della voce').not.toHaveBeenCalled();
        expect(finestraSforo(w).props('show')).toBe(true);
        expect(finestraSforo(w).props('sforoTotale')).toBe(14_400);
    });

    test('confermato lo sforo delle voci, lo sforo registrato è di tutta la fattura (€ 1.220,00 + € 144,00), con la strategia scelta per le voci', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, RIGA_VOCE]);
        await premiRegistra(w);
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'fondo_riserva', 90));
        inviato.post.mockClear();
        finestraSforo(w).vm.$emit('confirm', { strategia: 'conguaglio_fine_anno', fondoId: null, motivazione: 'Pulizie dopo il cantiere' });
        await flushPromises();

        expect(inviato.post).toHaveBeenCalledTimes(1);
        const vm = w.vm as any;
        expect(vm.form.dati_extra.override_budget).toMatchObject({
            importo_sforo: 136_400, strategia_rientro: 'conguaglio_fine_anno', fondo_patrimoniale_id: null,
            motivazione: 'Perdita improvvisa dalla colonna — Pulizie dopo il cantiere',
        });
        // La strategia dell'imprevisto resta la sua, nella finestra «Fuori preventivo».
        expect(vm.form.dati_extra.log_legale_sopravvenienza).toMatchObject({ strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: 90 });
    });

    test('controprova — l\'urgenza senza altre righe in sforo: parte subito, con lo sforo del solo imprevisto e la sua strategia', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO]);
        await premiRegistra(w);
        inviato.post.mockClear();
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'fondo_riserva', 90));

        expect(finestraSforo(w).props('show')).toBe(false);
        expect(inviato.post).toHaveBeenCalledTimes(1);
        expect((w.vm as any).form.dati_extra.override_budget).toMatchObject({
            importo_sforo: 122_000, strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: 90, motivazione: 'Perdita improvvisa dalla colonna',
        });
    });

    test('controprova — la delibera e una riga che sfora: la finestra dello sforo si apre, e lo sforo registrato è quello delle sole voci', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, RIGA_VOCE]);
        await premiRegistra(w);
        await confermaFuoriPreventivo(w, datiFinestra('delibera_assembleare', 'rata_integrativa'));
        expect(finestraSforo(w).props('show')).toBe(true);
        finestraSforo(w).vm.$emit('confirm', { strategia: 'rata_integrativa', fondoId: null, motivazione: 'Pulizie dopo il cantiere' });
        await flushPromises();

        expect((w.vm as any).form.dati_extra.override_budget).toMatchObject({ importo_sforo: 14_400, strategia_rientro: 'rata_integrativa', motivazione: 'Pulizie dopo il cantiere' });
    });
});

describe('693 (a) — dopo un rifiuto del server la finestra si riapre', () => {
    test('il server rifiuta la motivazione: «Registra» riapre la finestra «Fuori preventivo» con i dati di prima, invece di rimandare gli stessi dati', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO]);
        await premiRegistra(w);
        inviato.post.mockClear();
        const dati = datiFinestra('gestione_corrente', 'fondo_riserva', 90);
        await confermaFuoriPreventivo(w, dati);

        const opzioni = inviato.post.mock.calls.at(-1)?.[1] as { onError: (e: Record<string, string>) => void };
        const errori = { 'dati_extra.override_budget.motivazione': 'La motivazione è obbligatoria quando si supera il budget.' };
        (w.vm as any).form.setError(errori);
        opzioni.onError(errori);
        await flushPromises();

        inviato.post.mockClear();
        await premiRegistra(w);

        expect(inviato.post, 'la pagina rimanda gli stessi dati rifiutati').not.toHaveBeenCalled();
        expect(finestraFuoriPreventivo(w).props('show')).toBe(true);
        expect(finestraFuoriPreventivo(w).props('precedente')).toMatchObject({ strategia_rientro: 'fondo_riserva', motivazione_urgenza: 'Perdita improvvisa dalla colonna' });
    });

    test('dopo un rifiuto, tolta la riga «Fuori preventivo», la fattura non parte più con lo sforo dell\'imprevisto', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, { ...RIGA_VOCE, importo_imponibile: 50 }]);
        await premiRegistra(w);
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'conguaglio_fine_anno'));
        const opzioni = inviato.post.mock.calls.at(-1)?.[1] as { onError: (e: Record<string, string>) => void };
        const errori = { numero_documento: 'Il numero documento è obbligatorio.' };
        (w.vm as any).form.setError(errori);
        opzioni.onError(errori);
        await flushPromises();

        (w.vm as any).form.righe.splice(0, 1);
        await flushPromises();
        inviato.post.mockClear();
        await premiRegistra(w);

        expect(inviato.post).toHaveBeenCalledTimes(1);
        expect((w.vm as any).form.dati_extra.override_budget).toBeNull();
    });

    test('controprova — un rifiuto che non riguarda la finestra (il numero del documento) non la riapre: si corregge il numero e si rimanda', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO]);
        await premiRegistra(w);
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'conguaglio_fine_anno'));

        const opzioni = inviato.post.mock.calls.at(-1)?.[1] as { onError: (e: Record<string, string>) => void };
        const errori = { numero_documento: 'Il numero documento è obbligatorio.' };
        (w.vm as any).form.setError(errori);
        opzioni.onError(errori);
        await flushPromises();

        inviato.post.mockClear();
        await premiRegistra(w);
        expect(finestraFuoriPreventivo(w).props('show')).toBe(false);
        expect(inviato.post).toHaveBeenCalledTimes(1);
    });
});

describe('693 (c) — la finestra riceve la gestione della fattura', () => {
    test('la gestione scelta, con il nome e il tipo', async () => {
        const w = render({ id: 36, nome: 'Lavori balcone 2026', tipo: 'straordinaria', esercizio_ids: [41] });
        await conRighe(w, [RIGA_IMPREVISTO]);

        expect(finestraFuoriPreventivo(w).props('gestione')).toMatchObject({ nome: 'Lavori balcone 2026', tipo: 'straordinaria' });
    });
});

describe('693, seconda passata — la finestra sa delle righe addebitate a un\'unità', () => {
    test('con una riga di un\'unità la finestra «Fuori preventivo» lo sa (e spegne il fondo); senza, no', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, { ...RIGA_VOCE, conto_id: null, immobile_id: UNITA.id }]);
        expect(finestraFuoriPreventivo(w).props('hasSpesePrivate')).toBe(true);

        await conRighe(w, [RIGA_IMPREVISTO]);
        expect(finestraFuoriPreventivo(w).props('hasSpesePrivate')).toBe(false);
    });
});

describe('693 (e) — la riga addebitata a un\'unità', () => {
    test('una riga di un\'unità con il capitolo «Pulizie scale» non conta sul capitolo: niente sforo, niente «Autorizza e Registra»', async () => {
        const w = render();
        await conRighe(w, [{ ...RIGA_VOCE, immobile_id: UNITA.id }]);
        const vm = w.vm as any;

        expect(vm.budgetImpacts.find((i: any) => i.id === VOCE.id)?.speso_cents ?? 0).toBe(0);
        expect(vm.transactionStatus).not.toBe('CRITICAL_BUDGET');
        expect(pulsanteRegistra(w).text()).toContain('Registra Documento');
    });

    test('controprova — la stessa riga senza l\'unità sfora il capitolo e chiede «Autorizza e Registra»', async () => {
        const w = render();
        await conRighe(w, [RIGA_VOCE]);

        expect((w.vm as any).transactionStatus).toBe('CRITICAL_BUDGET');
        expect(pulsanteRegistra(w).text()).toContain('Autorizza e Registra');
    });

    test('il valore della parte comune da ripartire non somma la riga imprevisto di un\'unità; lo sforo registrato con l\'urgenza nemmeno', async () => {
        const w = render();
        await conRighe(w, [RIGA_IMPREVISTO, { ...RIGA_IMPREVISTO, descrizione: 'Rubinetto di Interno 1', importo_imponibile: 100, immobile_id: UNITA.id }]);

        expect(finestraFuoriPreventivo(w).props('importoImprevisto')).toBe(122_000);

        await premiRegistra(w);
        await confermaFuoriPreventivo(w, datiFinestra('gestione_corrente', 'conguaglio_fine_anno'));
        expect((w.vm as any).form.dati_extra.override_budget.importo_sforo).toBe(122_000);
    });
});

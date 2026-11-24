// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 6: la pagina di registrazione con la spunta «Debito esercizio precedente».
 *
 * - Coda 754 (5): con l'urgenza scelta nella finestra «Configura nuova voce di spesa» la fattura è da ratificare con l'importo vero, la parte non coperta
 *   dai saldi iniziali (€ 610,00): fino alla tappa 6 la pagina mandava la parte comune delle righe «Fuori preventivo», che la pregressa non ha (€ 0,00).
 *   Con la delibera, o con la pregressa tutta coperta dal saldo d'apertura, niente da ratificare. La finestra si apre in modo «pregressa».
 * - Coda 754 (6): il campo si chiama «Imponibile (senza IVA)», e sotto una riga dice il totale che il programma registrerà, mentre si scrive; nel piede,
 *   «Imponibile». Prima «Imponibile Lordo», e chi scriveva il totale della fattura (€ 610,00) otteneva un documento di € 744,20.
 *
 * La pagina è quella vera, con `useForm` vero; si sostituisce solo `post()`, come in `FatturaRegisterNewFuoriPreventivo.test.ts`. La finestra è uno stub che
 * riceve le prop della pagina ed emette `confirm` come la vera.
 *
 * Cosa resta scoperto: la finestra vera (`ModalSpesaImprevista.test.ts`); il server (`Beta14PregressaFondoTest`); l'aspetto a video.
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
(Element.prototype as any).scrollIntoView = () => undefined;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    FatturaRegistrazioneGuide: true, WidgetDoubleLock: true, ModalImportaXml: true, ModalOverrideBudget: true,
    ModalSpesaImprevista: {
        name: 'ModalSpesaImprevista',
        props: ['show', 'fornitoreNome', 'condominioId', 'fondiRiserva', 'importoImprevisto', 'gestione', 'precedente', 'mode', 'hasSpesePrivate'],
        emits: ['update:show', 'confirm'],
        template: '<div v-if="show" data-test="finestra-fuori-preventivo" />',
    },
    Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' }, 'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" />' },
};

/** Il debito d'apertura verso Idraulica Srl, in centesimi come lo manda il server (`importo_disponibile`). */
const DEBITO = { id: 4, importo_disponibile: 61_000, label: 'Debito d\'apertura Idraulica Srl' };

function render(debiti: Record<string, unknown>[] = []) {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio' }, condomini: [{ id: 28, nome: 'Condominio' }],
            esercizio: { id: 41, nome: 'Esercizio 2026', stato: 'aperto', data_inizio: '2026-01-01' },
            esercizi: [{ id: 41, nome: 'Esercizio 2026', stato: 'aperto', data_inizio: '2026-01-01' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            fornitori: [{ id: 10, ragione_sociale: 'Idraulica Srl', soggetto_ritenuta: false, regime_forfetario: false }],
            conti: [], banche: [], immobili: [], debiti_patrimoniali: debiti, fatture_pregresse_registrate: [],
            fondi_riserva: [{ id: 90, nome: 'Fondo di riserva', saldo_attuale: 500_000 }], capienza_rata_zero: 0, incassato_rata_zero: 0,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

/** La fattura del 2025 di € 500,00 + IVA 22 %, con il periodo dell'ottobre 2025. */
async function pregressa(w: VueWrapper, extra: Record<string, unknown> = {}) {
    const vm = w.vm as any;
    vm.form.fornitore_id = 10;
    await flushPromises();
    Object.assign(vm.form, {
        esercizio_id: 41, gestione_id: 35, numero_documento: '12', data_documento: '2025-10-31', is_pregresso: true, imponibile_pregresso: 500,
        aliquota_iva_pregressa: 22, competenza_dal: '2025-10-01', competenza_al: '2025-10-31', ...extra,
    });
    await flushPromises();

    return vm;
}

const finestra = (w: VueWrapper) => w.findComponent({ name: 'ModalSpesaImprevista' });

/** I dati che la finestra emette, come quella vera. */
const datiFinestra = (origine: 'gestione_corrente' | 'delibera_assembleare', strategia: string, fondo: number | null = null) => ({
    nome_voce: 'Debito 2025 Idraulica', tabella_millesimale_id: 3, percentuale_proprietario: 100, percentuale_inquilino: 0, percentuale_usufruttuario: 0,
    show_advanced_percentages: false, origine_decisionale: origine, data_assemblea: origine === 'delibera_assembleare' ? '2026-10-15' : '', strategia_rientro: strategia,
    fondo_patrimoniale_id: fondo, motivazione_urgenza: origine === 'gestione_corrente' ? 'Riparazione della colonna rimasta da pagare' : '', tipo_ripartizione: 'millesimale',
    is_ordinario: origine === 'gestione_corrente', richiede_copertura: true,
    motivazione_sforo: origine === 'gestione_corrente' ? 'Riparazione della colonna rimasta da pagare' : '', delibera_id: null,
});

async function registra(w: VueWrapper) {
    const pulsante = w.findAll('button').find((b) => /Registra Documento|Autorizza e Registra/.test(b.text()))!;
    await pulsante.trigger('click');
    await flushPromises();
}

async function conferma(w: VueWrapper, dati: Record<string, unknown>) {
    inviato.post.mockClear();
    finestra(w).vm.$emit('confirm', dati);
    await flushPromises();
}

describe('754 (5) — la pregressa da ratificare ha l\'importo vero', () => {
    test('la parte non coperta apre la finestra in modo «pregressa», con il suo importo (€ 610,00)', async () => {
        const w = render();
        await pregressa(w);
        await registra(w);

        expect(finestra(w).props('show')).toBe(true);
        expect(finestra(w).props('mode')).toBe('pregressa');
        expect(finestra(w).props('importoImprevisto')).toBe(61_000);
    });

    test('con l\'urgenza la fattura parte da ratificare per € 610,00, non per € 0,00', async () => {
        const w = render();
        await pregressa(w);
        await registra(w);
        await conferma(w, datiFinestra('gestione_corrente', 'conguaglio_fine_anno'));

        expect(inviato.post).toHaveBeenCalledTimes(1);
        expect((w.vm as any).form.dati_extra.override_budget).toMatchObject({
            importo_sforo: 61_000, strategia_rientro: 'conguaglio_fine_anno', motivazione: 'Riparazione della colonna rimasta da pagare',
        });
    });

    test('con l\'urgenza e il fondo lo sforo ha l\'importo vero e il fondo scelto', async () => {
        const w = render();
        await pregressa(w);
        await registra(w);
        await conferma(w, datiFinestra('gestione_corrente', 'fondo_riserva', 90));

        expect((w.vm as any).form.dati_extra.override_budget).toMatchObject({ importo_sforo: 61_000, strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: 90 });
        expect((w.vm as any).form.dati_extra.log_legale_sopravvenienza).toMatchObject({ strategia_rientro: 'fondo_riserva', fondo_patrimoniale_id: 90 });
    });

    test('controprova — con la delibera niente da ratificare: la fattura parte senza la giustificazione dello sforo', async () => {
        const w = render();
        await pregressa(w);
        await registra(w);
        await conferma(w, datiFinestra('delibera_assembleare', 'fondo_riserva', 90));

        expect(inviato.post).toHaveBeenCalledTimes(1);
        expect((w.vm as any).form.dati_extra.override_budget).toBeNull();
    });

    test('la pregressa tutta coperta dal saldo d\'apertura, con i dati della finestra rimasti nel modulo: niente da ratificare', async () => {
        const w = render([DEBITO]);
        const vm = await pregressa(w, { saldo_patrimoniale_id: DEBITO.id });
        // I dati di una finestra confermata prima di scegliere il debito d'apertura.
        vm.form.dati_extra.log_legale_sopravvenienza = datiFinestra('gestione_corrente', 'conguaglio_fine_anno');
        await flushPromises();
        expect(vm.eccedenzaPregressaCents).toBe(0);
        inviato.post.mockClear();

        await registra(w);

        expect(inviato.post).toHaveBeenCalledTimes(1);
        expect(vm.form.dati_extra.override_budget).toBeNull();
    });
});

describe('754 (6) — «Imponibile (senza IVA)» e il totale del documento', () => {
    const testo = (w: VueWrapper) => w.text().replace(/\s+/g, ' ');

    test('il campo dice «senza IVA», e sotto il totale che il programma registrerà: € 500,00 al 22 % sono € 610,00', async () => {
        const w = render();
        await pregressa(w);

        expect(testo(w)).toContain('Imponibile (senza IVA)');
        expect(w.find('[data-test="totale-pregressa"]').text().replace(/\s+/g, ' ')).toBe('Totale del documento: € 610,00');
    });

    test('il totale segue i campi mentre si scrive', async () => {
        const w = render();
        const vm = await pregressa(w);
        vm.form.imponibile_pregresso = 1000;
        vm.form.aliquota_iva_pregressa = 10;
        await flushPromises();

        expect(w.find('[data-test="totale-pregressa"]').text().replace(/\s+/g, ' ')).toBe('Totale del documento: € 1.100,00');
    });

    test('nessuna etichetta dice «lordo»: il piede dice «Imponibile»', async () => {
        const w = render();
        await pregressa(w);

        expect(testo(w)).not.toMatch(/Imponibile lordo/i);
        const piede = w.findAll('span').filter((s) => s.text().trim() === 'Imponibile');
        expect(piede.length).toBeGreaterThan(0);
    });

    test('la riga del totale è testo normale, almeno 12 px, con il tema scuro', async () => {
        const w = render();
        await pregressa(w);

        const classi = w.find('[data-test="totale-pregressa"]').classes().join(' ');
        expect(classi).not.toMatch(/uppercase|text-\[(9|10|11)px\]/);
        expect(classi).toMatch(/text-(xs|sm)/);
        expect(classi).toMatch(/dark:text-/);
    });
});

describe('754 (4) — il pannello della competenza dice il fondo che copre la pregressa', () => {
    test('confermata la finestra con il fondo e senza il periodo, il pannello dice «Quella parte la copre il fondo «Fondo di riserva».» e la fattura non parte', async () => {
        const w = render();
        await pregressa(w, { competenza_dal: '', competenza_al: '' });
        await registra(w);
        await conferma(w, datiFinestra('delibera_assembleare', 'fondo_riserva', 90));

        expect(inviato.post).not.toHaveBeenCalled();
        const testo = w.text().replace(/\s+/g, ' ');
        expect(testo).toContain('Quella parte la copre il fondo «Fondo di riserva».');
        expect(testo).not.toContain('Quella parte finisce in un piano rate');
    });

    test('controprova — con «Attesa conguaglio» il pannello dice ancora il piano rate', async () => {
        const w = render();
        await pregressa(w, { competenza_dal: '', competenza_al: '' });
        await registra(w);
        await conferma(w, datiFinestra('delibera_assembleare', 'conguaglio_fine_anno'));

        expect(w.text().replace(/\s+/g, ' ')).toContain('Quella parte finisce in un piano rate');
    });
});

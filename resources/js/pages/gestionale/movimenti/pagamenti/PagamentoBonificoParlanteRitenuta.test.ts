// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il bonifico parlante di una fattura registrata con la ritenuta (Coda 754 (2), decisione 696: «Fermarsi e dirlo»).
 *
 * Il server rifiuta il pagamento e dice di togliere prima la ritenuta dalla fattura (`BonificoParlanteConRitenutaException`); la pagina
 * lo dice prima dell'invio, con la stessa frase (che il server manda con le fatture da pagare, `bonifico_parlante_frase`, e con le
 * fatture del pagamento in modifica, `fatture_con_ritenuta`) e il collegamento alla modifica della fattura, o alla sua scheda per una
 * pregressa. Senza una fattura con la ritenuta resta l'avviso di prima («Al fornitore uscirà l'importo pieno»), che allora è vero.
 * Mentre il riquadro rosso è visibile «Registra Pagamento» (e in modifica «Salva Modifiche») resta spento; il server resta la guardia
 * vera. La guida del pagamento lo dice in una riga.
 *
 * Il lato del server è in `tests/Feature/Gestionale/Beta14BonificoParlanteConRitenutaTest.php`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { pendenze: [], has_netting: false, totale_nc: 0, totale_ft: 0 } })) } }));

import PagamentoNew from './PagamentoNew.vue';
import PagamentoEdit from './PagamentoEdit.vue';
import PagamentoFornitoreGuide from '@/components/guides/PagamentoFornitoreGuide.vue';

const rotta = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.fattura ? `/${parametri.fattura}` : ''}`;
(globalThis as any).route = rotta;
const mocks = { route: rotta };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const FRASE = 'La fattura n. 12 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e il '
    + 'condominio non la trattiene: togli prima la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»), poi registra il pagamento '
    + 'di € 1.220,00.';
const FRASE_PREGRESSA = 'La fattura n. P-7 di Edilnord è registrata con € 40,00 di ritenuta. Con il bonifico parlante la ritenuta la opera la banca e '
    + 'il condominio non la trattiene: la fattura è pregressa e non si modifica, quindi stornala e registrala di nuovo senza la ritenuta, con il '
    + 'motivo «Bonifico parlante»; poi registra il pagamento di € 1.220,00.';
const PIENO = 'Al fornitore uscirà l\'importo pieno, e nulla entrerà nell\'F24.';

const edilnord = { id: 3, ragione_sociale: 'Edilnord', soggetto_ritenuta: true, perc_ritenuta: 4, tipo_ritenuta: 'appalto_4' };

const fattura = (id: number, numero: string, ritenuta: number, frase: string | null, pregressa = false) => ({
    id, tipo_documento: 'fattura', numero_documento: numero, data_documento: '10/03/2026', importo_lordo: 122_000, netto_a_pagare: 122_000 - ritenuta,
    residuo: 122_000 - ritenuta, stato_pagamento: 'aperta', is_scaduta: false, is_nota_credito: false, gestione_id: 10, stato_approvazione: 'approvata',
    importo_ritenuta: ritenuta, is_pregresso: pregressa, bonifico_parlante_frase: frase,
    selezionata: true, importo_allocato: (122_000 - ritenuta) / 100, tipo_allocazione: 'pagamento',
});

let pagina: VueWrapper | null = null;

async function apriNuovo(pendenze: ReturnType<typeof fattura>[]) {
    pagina = mount(PagamentoNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Via Roma 12' },
            condomini: [{ id: 1, nome: 'Via Roma 12' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 4, nome: '2026', stato: 'aperto' }],
            fornitori: [edilnord],
            banche: [{ id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 900_000 }],
            gestioni: [{ id: 10, nome: 'Ordinaria', tipo: 'ordinaria' }],
            voci_commissione: [],
            voce_commissione_proposta: [],
        } as never,
        global: { stubs, mocks },
    });
    const stato = pagina.vm as any;
    stato.form.fornitore_id = 3;
    await nextTick();
    await new Promise((r) => setTimeout(r, 0));
    stato.pendenze = pendenze;
    stato.form.bonifico_parlante = true;
    await nextTick();
    await nextTick();

    return stato;
}

function apriModifica(fattureConRitenuta: Array<Record<string, unknown>>, giaParlante = false) {
    pagina = mount(PagamentoEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Via Roma 12' },
            condomini: [{ id: 1, nome: 'Via Roma 12' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            fornitori: [edilnord],
            banche: [{ id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 900_000 }],
            pagamento: {
                id: 7, fornitore: { id: 3, ragione_sociale: 'Edilnord' }, conto_corrente: { id: 11, nome: 'Banca Intesa' }, data_pagamento: '2026-03-20',
                metodo_pagamento: 'bonifico', importo_commissione: 0, importo_lordo: 122_000, importo_ritenuta: giaParlante ? 0 : 4_000,
                importo_netto: 118_000, bonifico_parlante: giaParlante, tipo_detrazione: giaParlante ? 'ristrutturazione' : undefined,
            },
            voci_commissione: [],
            commissione: { voce_id: null, gestione_id: 10, senza_voce: false },
            fatture_con_ritenuta: fattureConRitenuta,
        } as never,
        global: { stubs, mocks },
    });

    return pagina.vm as any;
}

const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il bonifico parlante nella registrazione del pagamento', () => {
    test('con la fattura registrata con la ritenuta la pagina dice la frase del rifiuto e manda alla modifica della fattura, non «l\'importo pieno»', async () => {
        await apriNuovo([fattura(1, '12', 4_000, FRASE)]);

        const avviso = pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]');
        expect(avviso.exists()).toBe(true);
        expect(avviso.text().replace(/\s+/g, ' ')).toContain(FRASE);
        expect(avviso.find('a').attributes('href')).toBe('/admin.gestionale.fatture.edit/1');
        expect(avviso.find('a').text().replace(/\s+/g, ' ')).toBe('Modifica la fattura n. 12');
        expect(testo()).not.toContain(PIENO);
    });

    test('per una pregressa manda alla scheda della fattura, dove si storna', async () => {
        await apriNuovo([fattura(5, 'P-7', 4_000, FRASE_PREGRESSA, true)]);

        const avviso = pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]');
        expect(avviso.text().replace(/\s+/g, ' ')).toContain(FRASE_PREGRESSA);
        expect(avviso.find('a').attributes('href')).toBe('/admin.gestionale.fatture.show/5');
        expect(avviso.find('a').text().replace(/\s+/g, ' ')).toBe('Apri la fattura n. P-7');
    });

    test('mentre il riquadro rosso è visibile «Registra Pagamento» è spento; togliendo la spunta si riaccende', async () => {
        const stato = await apriNuovo([fattura(1, '12', 4_000, FRASE)]);
        stato.syncAllocazioni();
        await nextTick();

        expect(stato.form.allocazioni.length).toBeGreaterThan(0);
        expect(pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]').exists()).toBe(true);
        expect(pagina!.find('[data-test="registra-pagamento"]').attributes('disabled')).toBeDefined();

        stato.form.bonifico_parlante = false;
        await nextTick();

        expect(pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]').exists()).toBe(false);
        expect(pagina!.find('[data-test="registra-pagamento"]').attributes('disabled')).toBeUndefined();
    });

    test('senza una fattura con la ritenuta resta l\'avviso di prima, che allora è vero', async () => {
        await apriNuovo([fattura(2, '13', 0, null)]);

        expect(pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]').exists()).toBe(false);
        expect(pagina!.find('[data-test="bonifico-parlante-importo-pieno"]').exists()).toBe(true);
        expect(testo()).toContain(PIENO);
        (pagina!.vm as any).syncAllocazioni();
        await nextTick();
        expect(pagina!.find('[data-test="registra-pagamento"]').attributes('disabled')).toBeUndefined();
    });
});

describe('il bonifico parlante nella modifica del pagamento', () => {
    test('la spunta messa sul pagamento di una fattura con la ritenuta mostra la frase del rifiuto e il collegamento alla fattura', async () => {
        const stato = apriModifica([{ id: 1, numero_documento: '12', importo_ritenuta: 4_000, importo_pieno: 122_000, is_pregresso: false, frase: FRASE }]);
        expect(pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]').exists()).toBe(false);

        stato.form.bonifico_parlante = true;
        await nextTick();

        const avviso = pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]');
        expect(avviso.exists()).toBe(true);
        expect(avviso.text().replace(/\s+/g, ' ')).toContain(FRASE);
        expect(avviso.find('a').attributes('href')).toBe('/admin.gestionale.fatture.edit/1');
        expect(pagina!.find('[data-test="salva-modifiche"]').attributes('disabled')).toBeDefined();

        stato.form.bonifico_parlante = false;
        await nextTick();

        expect(pagina!.find('[data-test="salva-modifiche"]').attributes('disabled')).toBeUndefined();
    });

    test('un pagamento registrato prima con la spunta non mostra il rifiuto: togliendola la ritenuta torna quella delle fatture', async () => {
        apriModifica([{ id: 1, numero_documento: '12', importo_ritenuta: 4_000, importo_pieno: 122_000, is_pregresso: false, frase: FRASE }], true);
        await nextTick();

        expect(pagina!.find('[data-test="bonifico-parlante-con-ritenuta"]').exists()).toBe(false);
        expect(pagina!.find('[data-test="salva-modifiche"]').attributes('disabled')).toBeUndefined();
        expect(testo()).toContain('Togliendo la spunta la ritenuta torna quella prevista dalle fatture.');
    });
});

describe('la guida del pagamento', () => {
    test('dice in una riga che la fattura con la ritenuta non si paga con il bonifico parlante finché la ritenuta non si toglie dalla fattura', () => {
        const passa = { template: '<div><slot /></div>' };
        const guida = mount(PagamentoFornitoreGuide as never, {
            props: { open: true } as never,
            global: { stubs: { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa, Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa } },
        });
        const t = guida.text().replace(/\s+/g, ' ');
        guida.unmount();

        expect(t).toContain('Se la fattura è registrata con la ritenuta, il pagamento con il bonifico parlante si ferma: togli prima la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»; una pregressa si storna e si registra di nuovo con il motivo), poi registra il pagamento dell\'importo pieno.');
    });
});

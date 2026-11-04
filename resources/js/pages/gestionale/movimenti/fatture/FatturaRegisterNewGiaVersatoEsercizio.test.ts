// @vitest-environment jsdom

/**
 * Coda 438 (decisione 183.1): la stima del modale dello sforo toglie il già versato che vale nell'esercizio della fattura.
 * Il già versato di Ugo (€ 300,00) vale per il 2026 (id 41); la fattura è del 2027 (id 42): la rata integrativa del 2027 non lo toglie, e la stima
 * non deve toglierlo. Con la fattura del 2026, sì.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import FatturaRegisterNew from './FatturaRegisterNew.vue';

(globalThis as any).route = (n: string) => `/${n}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    FatturaRegistrazioneGuide: true, WidgetDoubleLock: true, ModalSpesaImprevista: true, ModalOverrideBudget: true, ModalImportaXml: true,
    Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' }, 'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" />' },
};

// Il server manda il totale e il già versato per esercizio («tutti» = vale in tutti i piani della voce).
const CONTO = { id: 55, nome: 'Spese generali', residuo_budget: 120_000, gia_versato_cents: 30_000, gia_versato_per_esercizio: { '41': 30_000 }, ultimi_movimenti: [] };

function render(conto: typeof CONTO) {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio' }, condomini: [{ id: 28, nome: 'Condominio' }],
            esercizio: { id: 42, nome: 'Esercizio 2027', stato: 'aperto' },
            esercizi: [{ id: 41, nome: 'Esercizio 2026', stato: 'aperto' }, { id: 42, nome: 'Esercizio 2027', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria', tipo: 'ordinaria', esercizio_ids: [41, 42] }],
            fornitori: [{ id: 10, ragione_sociale: 'Ditta', soggetto_ritenuta: false, regime_forfetario: false }],
            conti: [conto], banche: [{ id: 7, nome: 'Banca', saldo_attuale: 1_000_000 }], immobili: [], debiti_patrimoniali: [],
            fatture_pregresse_registrate: [], fondi_riserva: [], capienza_rata_zero: 0, incassato_rata_zero: 0,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

async function fatturaDi(esercizioId: number, conto: typeof CONTO = CONTO) {
    const w = render(conto);
    const vm = w.vm as any;
    vm.form.esercizio_id = esercizioId;
    vm.form.fornitore_id = 10;
    await w.vm.$nextTick();
    vm.form.righe[0].conto_id = conto.id;
    vm.form.righe[0].importo_imponibile = 1500;
    vm.form.righe[0].aliquota_iva = 0;
    await w.vm.$nextTick();

    return vm.budgetImpacts.find((i: any) => i.id === conto.id);
}

describe('Coda 438 — la stima dello sforo e il già versato di un altro esercizio', () => {
    test('fattura del 2027, già versato del 2026: la stima non lo toglie (sforo € 300,00, residuo netto € 1.500,00)', async () => {
        const impatto = await fatturaDi(42);
        expect(impatto.isOk).toBe(false);
        expect(impatto.gia_versato_cents).toBe(0);
        expect(impatto.residuoNettoStimatoCents).toBe(150_000);
    });

    test('fattura del 2026, già versato del 2026: la stima lo toglie (€ 1.500,00 − € 300,00 = € 1.200,00)', async () => {
        const impatto = await fatturaDi(41);
        expect(impatto.gia_versato_cents).toBe(30_000);
        expect(impatto.residuoNettoStimatoCents).toBe(120_000);
    });

    // Il già versato senza esercizio (€ 100,00) vale in tutti i piani della voce, quindi in quello di ogni esercizio, e si somma a quello dell'esercizio.
    const CONTO_CON_TUTTI = { ...CONTO, gia_versato_cents: 40_000, gia_versato_per_esercizio: { tutti: 10_000, '41': 30_000 } };

    test('fattura del 2027 con un già versato per tutti gli esercizi (€ 100,00) e uno del 2026 (€ 300,00): la stima toglie solo il primo', async () => {
        const impatto = await fatturaDi(42, CONTO_CON_TUTTI);
        expect(impatto.gia_versato_cents).toBe(10_000);
        expect(impatto.residuoNettoStimatoCents).toBe(140_000);
    });

    test('fattura del 2026 con gli stessi due già versati: la stima li toglie tutti e due (€ 1.500,00 − € 400,00 = € 1.100,00)', async () => {
        const impatto = await fatturaDi(41, CONTO_CON_TUTTI);
        expect(impatto.gia_versato_cents).toBe(40_000);
        expect(impatto.residuoNettoStimatoCents).toBe(110_000);
    });

    test('una voce senza il campo del già versato per esercizio: vale il totale', async () => {
        const impatto = await fatturaDi(42, { id: 55, nome: 'Spese generali', residuo_budget: 120_000, gia_versato_cents: 30_000, ultimi_movimenti: [] } as any);
        expect(impatto.gia_versato_cents).toBe(30_000);
        expect(impatto.residuoNettoStimatoCents).toBe(120_000);
    });
});

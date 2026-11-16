// @vitest-environment jsdom

/**
 * L'accordo di rientro nel modulo dell'incasso. Il server manda per ogni riga la parte nell'accordo (`in_accordo`, in euro come `residuo`) e la posizione
 * nell'ordine dell'accordo (`ordine_accordo`: prima gli interessi e le spese, poi il capitale), e per la persona l'accordo in corso.
 *
 * - Senza la scelta il versamento va alle rate fuori dall'accordo: € 300,00 vanno alla rata 2, non agli interessi né alla rata 1.
 * - Con «Versamento dell'accordo» va alle parti coperte, nel loro ordine: € 75,31 → interessi € 0,92, sollecito € 25,00, rata 1 € 49,39.
 * - «Paga scadute» senza la scelta paga le scadute fuori dall'accordo (€ 300,00); con la scelta quello che è nell'accordo (€ 225,92).
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const server = vi.hoisted(() => ({ rate: [] as any[], accordo: null as any }));

vi.mock('axios', () => ({
    default: {
        get: vi.fn(async () => ({ data: { rate: server.rate, accordo: server.accordo } })),
        post: vi.fn(async () => ({ data: { esito: 'ordine_scadenze', rata_aperta: null, parte_in_piu: false } })),
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateNew from './IncassoRateNew.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const GESTIONE = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };

function riga(o: Record<string, unknown>) {
    const residuo = (o.residuo as number) ?? 100;
    return {
        rata_padre_id: o.id, importo_totale: Math.abs(residuo), gestione_id: GESTIONE.id, gestione: GESTIONE.nome, intestatario: 'Neri', unita: 'U1',
        is_emitted: true, is_published: true, pregresso: false, scaduta: true, in_accordo: 0, ordine_accordo: null,
        dettaglio_quote: [{ unita: 'U1', anagrafica: 'Neri', anagrafica_id: 5, ruolo: 'P', residuo, is_credito: residuo < 0 }],
        ...o,
        residuo,
    };
}

/** Le righe del caso, nell'ordine del server: gli interessi e il sollecito in testa, la rata 1 (31/03), la rata 2 (30/06) fuori dall'accordo. */
const RIGHE = () => [
    riga({ id: 11, descrizione: 'Interessi di mora al 29/06/2026', scadenza_human: '29/06/2026', residuo: 0.92, in_accordo: 0.92, ordine_accordo: 1, accessorio: true, in_testa: true }),
    riga({ id: 12, descrizione: 'Spese di sollecito', scadenza_human: '29/06/2026', residuo: 25, in_accordo: 25, ordine_accordo: 2, accessorio: true, in_testa: true }),
    riga({ id: 1, descrizione: 'Rata 1', scadenza_human: '31/03/2026', residuo: 200, in_accordo: 200, ordine_accordo: 3 }),
    riga({ id: 2, descrizione: 'Rata 2', scadenza_human: '30/06/2026', residuo: 300 }),
];
const ACCORDO = { id: 7, data_accordo: '2026-07-15', in_accordo: 22592, prossima: { numero: 1, data: '2026-07-31', mancano: 7531 }, rate_non_versate: 0 };

async function apri(accordo: any = ACCORDO) {
    server.rate = RIGHE();
    server.accordo = accordo;
    window.history.replaceState({}, '', '/gestionale/incassi/nuovo?prefill_anagrafica_id=5');
    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 28, nome: 'Via Verdi' },
            risorse: [{ id: 2, nome: 'Banca', tipo: 'banca' }],
            condomini: [{ id: 5, nome: 'Neri' }],
            immobili: [{ id: 1, interno: '1', descrizione: 'U1' }],
            gestioni: [GESTIONE],
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
    await flushPromises();
    await flushPromises();

    return wrapper;
}

const pagamenti = (wrapper: any) => Object.fromEntries((wrapper.vm as any).form.dettaglio_pagamenti.map((p: any) => [p.rata_id, p.importo]));
const versa = async (wrapper: any, euro: string) => {
    (wrapper.vm as any).form.importo_totale = euro;
    await flushPromises();
    await flushPromises();
};

describe('l\'accordo di rientro nel modulo dell\'incasso', () => {
    test('il riquadro dice l\'accordo e la prossima rata; «Versamento dell\'accordo» non è già scelto; le righe dicono la parte nell\'accordo', async () => {
        const wrapper = await apri();
        const riquadro = wrapper.find('[data-testid="accordo-incasso"]');
        expect(riquadro.text()).toContain('Accordo di rientro del 15/07/2026: nell\'accordo € 225,92; la prossima rata del calendario è la 1 del 31/07/2026, mancano € 75,31.');
        expect((riquadro.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);
        // La cifra con il formato della pagina (lo spazio dopo «€» non va a capo).
        expect(wrapper.findAll('[data-testid="parte-nell-accordo"]').map((e) => e.text().replace(/\u00a0/g, ' '))).toEqual([
            'nell\'accordo di rientro: € 0,92', 'nell\'accordo di rientro: € 25,00', 'nell\'accordo di rientro: € 200,00',
        ]);
    });

    test('senza la scelta € 300,00 vanno alla rata 2, fuori dall\'accordo', async () => {
        const wrapper = await apri();
        await versa(wrapper, '300.00');
        expect(pagamenti(wrapper)).toEqual({ 2: 300 });
    });

    test('con «Versamento dell\'accordo» € 75,31 vanno a interessi € 0,92, sollecito € 25,00, rata 1 € 49,39', async () => {
        const wrapper = await apri();
        await wrapper.find('[data-testid="accordo-incasso"] input[type="checkbox"]').setValue(true);
        await versa(wrapper, '75.31');
        expect(pagamenti(wrapper)).toEqual({ 11: 0.92, 12: 25, 1: 49.39 });
        expect((wrapper.vm as any).form.versamento_accordo).toBe(true);
    });

    test('con «Versamento dell\'accordo» la parte oltre l\'accordo segue l\'ordine di sempre: € 300,00 → l\'accordo intero, € 74,08 sulla rata 2', async () => {
        const wrapper = await apri();
        await wrapper.find('[data-testid="accordo-incasso"] input[type="checkbox"]').setValue(true);
        await versa(wrapper, '300.00');
        expect(pagamenti(wrapper)).toEqual({ 11: 0.92, 12: 25, 1: 200, 2: 74.08 });
        expect(Number((wrapper.vm as any).form.eccedenza)).toBe(0);
    });

    test('«Paga scadute» senza la scelta paga le scadute fuori dall\'accordo; con la scelta quello che è nell\'accordo', async () => {
        const wrapper = await apri();
        const pulsante = () => wrapper.findAll('button').find((b: any) => b.text() === 'Paga scadute')!;
        await pulsante().trigger('click');
        await flushPromises();
        expect(Number((wrapper.vm as any).form.importo_totale)).toBe(300);
        expect(pagamenti(wrapper)).toEqual({ 2: 300 });

        await wrapper.find('[data-testid="accordo-incasso"] input[type="checkbox"]').setValue(true);
        await flushPromises();
        await pulsante().trigger('click');
        await flushPromises();
        expect(Number((wrapper.vm as any).form.importo_totale)).toBe(225.92);
        expect(pagamenti(wrapper)).toEqual({ 11: 0.92, 12: 25, 1: 200 });
    });

    test('senza un accordo in corso il riquadro non c\'è', async () => {
        const wrapper = await apri(null);
        expect(wrapper.find('[data-testid="accordo-incasso"]').exists()).toBe(false);
    });
});

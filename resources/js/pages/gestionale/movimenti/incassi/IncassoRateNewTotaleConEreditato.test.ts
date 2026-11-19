// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 538 (3): nella pagina dell'incasso, accanto a «Totale allocato», lo spaccato quando il versamento va tutto o in parte al debito ereditato. A
 * video, Cecilia che versa € 32,79 tutti sul debito ereditato da Massimo Bagnara: «Totale allocato € 0,00», perché il totale contava solo le sue rate.
 *
 * Si monta la pagina vera: Cecilia senza rate, con la riga «Debito ereditato» da € 32,79; «Tutto» la riempie. Il conto delle due parti è in
 * `spaccatoDellAllocato` (`debitoEreditatoNellIncasso.test.ts` per la parte sulle rate e per i centesimi).
 *
 * Che cosa NON copre: la distribuzione sulle rate dell'erede insieme al debito ereditato nella pagina (il conto è della funzione); l'anteprima scura
 * («Allocato:»), che legge lo stesso valore; il disegno a 390 px e il tema scuro (a video).
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: { rate: [] } })), post: vi.fn() } }));
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

const CECILIA = 21;
const ORDINARIA = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };
const EREDITATO = { subentro_id: 51, gestione_id: ORDINARIA.id, gestione: ORDINARIA.nome, da: 'Massimo Bagnara', successione_del: '01/10/2026', aperto: 3279, aperto_formattato: '€ 32,79' };

async function apri() {
    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 16, nome: 'Via roma' },
            risorse: [{ id: 2, nome: 'Banca', tipo: 'banca' }],
            condomini: [{ id: CECILIA, nome: 'Cecilia Angeli' }],
            persone: [{ id: CECILIA, nome: 'Cecilia Angeli', indirizzo: null, codice_fiscale: null, ha_rate: false }],
            immobili: [],
            gestioni: [ORDINARIA],
            debiti_ereditati: { [CECILIA]: [EREDITATO] },
            successioni_dei_defunti: {},
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
    await flushPromises();
    (wrapper.vm as any).form.pagante_id = CECILIA;
    await flushPromises();
    await flushPromises();

    return wrapper;
}

const testo = (el: { text: () => string }) => el.text().replace(/[\s ]+/g, ' ').trim();

describe('«Totale allocato» con il debito ereditato', () => {
    test('€ 32,79 tutti sul debito ereditato: lo spaccato € 32,79 / € 0,00 e il totale € 32,79, non € 0,00', async () => {
        const w = await apri();
        (w.vm as any).form.importo_totale = '32,79';
        await flushPromises();
        const tutto = w.find('[data-testid="debito-ereditato"]').findAll('button').find((b) => b.text() === 'Tutto');
        await tutto!.trigger('click');
        await flushPromises();

        const spaccato = w.find('[data-testid="spaccato-allocato"]');
        expect(spaccato.exists()).toBe(true);
        expect(spaccato.findAll('div').map((d) => [testo(d.find('dt')), testo(d.find('dd'))])).toEqual([['Sul debito ereditato', '€ 32,79'], ['Sulle sue rate', '€ 0,00']]);
        expect(w.find('[data-testid="totale-allocato"]').findAll('span').map((x) => testo(x))).toEqual(['Totale allocato', '€ 32,79']);
    });

    test('senza una cifra sul debito ereditato: il totale allocato di sempre, senza spaccato', async () => {
        const w = await apri();

        expect(w.find('[data-testid="spaccato-allocato"]').exists()).toBe(false);
        expect(w.find('[data-testid="totale-allocato"]').findAll('span').map((x) => testo(x))).toEqual(['Totale allocato', '€ 0,00']);
    });
});

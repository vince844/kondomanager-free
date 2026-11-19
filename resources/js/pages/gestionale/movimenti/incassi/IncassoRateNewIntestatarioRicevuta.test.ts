// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 539: nella pagina dell'incasso, quando il pagante è un erede che versa sul debito ereditato, «Intestatario della ricevuta» è già su di lui
 * («Cecilia Angeli (chi ha pagato)»), con «Eredi di Massimo Bagnara» e «Massimo Bagnara (il defunto)»; la scelta parte al server con l'incasso.
 *
 * Si monta la pagina vera: Cecilia senza rate, con la riga «Debito ereditato» da € 32,79 di Massimo Bagnara. Il menu è il `vSelect` vero: se ne leggono
 * le voci, la scelta e la voce mostrata.
 *
 * Che cosa NON copre: il menu vero di `vue-select` (aperto con il mouse); la ricevuta che il server ne fa (`RicevutaDellEredeTest.php`); il disegno a 390 px
 * e il tema scuro (a video).
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: { rate: [] } })), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import vSelect from 'vue-select';
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
const EREDITATO = {
    subentro_id: 51, gestione_id: ORDINARIA.id, gestione: ORDINARIA.nome, da: 'Massimo Bagnara', defunto: 'Massimo Bagnara', catena: false,
    successione_del: '15/11/2026', aperto: 3279, aperto_formattato: '€ 32,79',
};

async function apri(righe: Array<Record<string, unknown>> = [EREDITATO]) {
    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 16, nome: 'Via roma' },
            risorse: [{ id: 2, nome: 'Banca', tipo: 'banca' }],
            condomini: [{ id: CECILIA, nome: 'Cecilia Angeli' }],
            persone: [{ id: CECILIA, nome: 'Cecilia Angeli', indirizzo: null, codice_fiscale: null, ha_rate: false }],
            immobili: [],
            gestioni: [ORDINARIA],
            debiti_ereditati: { [CECILIA]: righe },
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

async function tutto(w: Awaited<ReturnType<typeof apri>>) {
    const pulsante = w.find('[data-testid="debito-ereditato"]').findAll('button').find((b) => b.text() === 'Tutto');
    await pulsante!.trigger('click');
    await flushPromises();
}

describe('«Intestatario della ricevuta» per il versamento di un erede sul debito ereditato', () => {
    test('con una cifra sul debito ereditato il menu c\'è, già sull\'erede, con gli eredi e il defunto; parte al server con l\'incasso', async () => {
        const w = await apri();
        await tutto(w);

        const campo = w.find('[data-testid="intestatario-ricevuta"]');
        expect(campo.exists()).toBe(true);
        expect(campo.text()).toContain('Intestatario della ricevuta');
        const menu = campo.findComponent(vSelect);
        expect((menu.props('options') as Array<{ etichetta: string }>).map((o) => o.etichetta))
            .toEqual(['Cecilia Angeli (chi ha pagato)', 'Eredi di Massimo Bagnara', 'Massimo Bagnara (il defunto)']);
        expect(menu.props('modelValue')).toBe('erede');
        expect(campo.find('.vs__selected').text()).toBe('Cecilia Angeli (chi ha pagato)');
        expect(menu.props('clearable')).toBe(false);
        expect((w.vm as any).intestatarioDaMandare).toBe('erede');

        menu.vm.$emit('update:modelValue', 'eredi');
        await flushPromises();
        expect((w.vm as any).form.intestatario_ricevuta).toBe('eredi');
        expect(campo.find('.vs__selected').text()).toBe('Eredi di Massimo Bagnara');
        expect((w.vm as any).intestatarioDaMandare).toBe('eredi');
    });

    test('senza una cifra sul debito ereditato il menu non c\'è e non parte niente', async () => {
        const w = await apri();

        expect(w.find('[data-testid="intestatario-ricevuta"]').exists()).toBe(false);
        expect((w.vm as any).intestatarioDaMandare).toBeNull();
    });

    test('con la catena (il debito arriva da un erede morto a sua volta) il menu non c\'è: la ricevuta va all\'erede', async () => {
        const w = await apri([{ ...EREDITATO, da: 'Marco, che l\'aveva ereditato da Ettore Fabbri', defunto: 'Marco', catena: true }]);
        await tutto(w);

        expect(w.find('[data-testid="intestatario-ricevuta"]').exists()).toBe(false);
        expect((w.vm as any).intestatarioDaMandare).toBeNull();
    });

    test('cambiato il pagante, la scelta torna sull\'erede', async () => {
        const w = await apri();
        await tutto(w);
        (w.vm as any).form.intestatario_ricevuta = 'defunto';
        await flushPromises();
        (w.vm as any).form.pagante_id = null;
        await flushPromises();

        expect((w.vm as any).form.intestatario_ricevuta).toBe('erede');
    });
});

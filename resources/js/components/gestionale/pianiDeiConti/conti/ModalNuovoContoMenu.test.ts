// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra «Nuova voce» del piano dei conti: i menu «Fornitore suggerito» e «Natura spesa» con `v-select` (decisione 392, Coda 634), non con il
 * `<select>` del browser, che ha l'aspetto del sistema e nel tema scuro non si legge. Con il codice di prima i due menu erano `<select>`.
 *
 * `vue-select` è finto: riceve le stesse proprietà e manda la scelta come il vero, così la prova guarda che cosa la finestra gli dà e che cosa ne fa.
 *
 * Che cosa non copre: il disegno a video (390 px, tema scuro); la finestra «Modifica voce» (`ModalModificaConto.vue`), che ha ancora i suoi `<select>`
 * ed è nell'elenco della Coda 634.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
vi.mock('vue-select', () => ({
    default: {
        name: 'VSelectFinto',
        props: ['options', 'modelValue', 'label', 'reduce', 'clearable', 'inputId', 'placeholder', 'selectable', 'loading'],
        emits: ['update:modelValue', 'open'],
        template: '<div class="v-select-finto" :data-input-id="inputId"></div>',
    },
}));
vi.mock('@/composables/useTabelle', () => ({ useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }) }));
vi.mock('@/composables/useCapitoliConti', () => ({ useCapitoliConti: () => ({ capitoli: { value: [] }, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ModalNuovoConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue')).default;

let finestra: VueWrapper | null = null;

async function monta() {
    finestra = mount(ModalNuovoConto, {
        attachTo: document.body,
        props: { show: true, condominioId: 1, esercizioId: 1, pianoContoId: 1, fornitori: [{ id: 5, ragione_sociale: 'Idraulica Srl' }, { id: 8, ragione_sociale: 'Pulizie Rossi' }] },
    });
    await flushPromises();
}

const menu = (id: string) => finestra!.findAllComponents({ name: 'VSelectFinto' }).find((c) => c.props('inputId') === id)!;
const modulo = () => (finestra!.vm as any).form;
const ridotto = (id: string, opzione: unknown) => {
    const riduci = menu(id).props('reduce') as ((o: unknown) => unknown) | undefined;

    return riduci ? riduci(opzione) : opzione;
};

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('la finestra «Nuova voce»: i menu con v-select', () => {
    test('nella finestra non c\'è nessun <select> del browser', async () => {
        await monta();

        expect(document.body.querySelector('select')).toBeNull();
        expect(menu('fornitore')).toBeDefined();
        expect(menu('tipo_spesa')).toBeDefined();
    });

    test('«Fornitore suggerito» offre i fornitori per nome; la scelta scrive l\'id, svuotarlo lo toglie', async () => {
        await monta();
        const fornitore = menu('fornitore');

        expect(fornitore.props('options')).toEqual([{ id: 5, ragione_sociale: 'Idraulica Srl' }, { id: 8, ragione_sociale: 'Pulizie Rossi' }]);
        expect(fornitore.props('label')).toBe('ragione_sociale');
        expect(fornitore.props('clearable')).toBe(true);
        expect(fornitore.props('modelValue')).toBeNull();

        fornitore.vm.$emit('update:modelValue', ridotto('fornitore', { id: 8, ragione_sociale: 'Pulizie Rossi' }));
        await flushPromises();
        expect(modulo().default_fornitore_id).toBe(8);

        menu('fornitore').vm.$emit('update:modelValue', null);
        await flushPromises();
        expect(modulo().default_fornitore_id).toBeNull();
    });

    test('«Natura spesa» ha le quattro nature, parte da «Standard» e non si svuota', async () => {
        await monta();
        const natura = menu('tipo_spesa');

        expect((natura.props('options') as Array<{ value: string }>).map((o) => o.value)).toEqual(['standard', 'professionista', 'lavori', 'utenza']);
        expect(natura.props('modelValue')).toBe('standard');
        expect(natura.props('clearable')).toBe(false);

        natura.vm.$emit('update:modelValue', ridotto('tipo_spesa', { value: 'lavori', label: 'x' }));
        await flushPromises();
        expect(modulo().tipo_spesa).toBe('lavori');
    });
});

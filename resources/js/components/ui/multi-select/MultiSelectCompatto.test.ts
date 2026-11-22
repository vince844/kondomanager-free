// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il campo a etichette nella forma compatta: alto e scritto come le altre caselle dei filtri (32 pixel, scritta a 12), dove la pagina lo chiede con
 * `compatto`. Senza, resta com'era in tutte le altre pagine.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import MultiSelect from './MultiSelect.vue';

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });
const opzioni = [{ text: 'Cecilia Angeli', value: '7' }];

describe('il campo a etichette compatto', () => {
    test('con compatto è alto 32 pixel e scrive a 12', () => {
        w = mount(MultiSelect, { props: { options: opzioni, placeholder: 'Cerca per nome', compatto: true, modelValue: [] } as never });
        const radice = w.find('[data-testid="multi-select-campo"]');
        expect(radice.classes()).toEqual(expect.arrayContaining(['min-h-8', 'py-1', 'text-xs']));
    });

    test('senza, resta com\'era', () => {
        w = mount(MultiSelect, { props: { options: opzioni, placeholder: 'Cerca per nome', modelValue: [] } as never });
        const radice = w.find('[data-testid="multi-select-campo"]');
        expect(radice.classes()).toContain('py-2');
        expect(radice.classes()).not.toContain('min-h-8');
    });
});

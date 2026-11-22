// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scelta del bollo nella forma a barra delle finestre: quattro scelte uguali attaccate, con le parole corte, quella accesa in nero, la frase sotto.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import SceltaBollo from './SceltaBollo.vue';

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });
const voci = () => w!.findAll('[role="radio"]');

describe('la scelta del bollo a barra', () => {
    test('quattro scelte con le parole corte, quella accesa in nero, la frase sotto la barra; un clic dice stato e modo', async () => {
        w = mount(SceltaBollo, { props: { stato: 'assolta', modo: 'virtuale', nota: '1 ricevuta sopra € 77,47', frase: 'Lo decidi tu.' } });
        expect(w.get('[data-testid="scelta-bollo"]').attributes('data-forma')).toBe('barra');
        expect(voci().map((v) => v.text())).toEqual(['Non dovuta', 'Da assolvere', 'Contrassegno', 'Virtuale']);
        expect(voci()[3].classes()).toContain('bg-slate-900');
        expect(voci()[0].classes()).not.toContain('bg-slate-900');
        const html = w.html();
        expect(html.indexOf('Lo decidi tu.')).toBeGreaterThan(html.indexOf('Virtuale'));
        await voci()[2].trigger('click');
        expect(w.emitted('scegli')?.[0]).toEqual([{ stato: 'assolta', modo: 'contrassegno' }]);
    });
});

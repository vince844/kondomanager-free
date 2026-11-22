// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I punti della sala (decisione 664 (3), rilievo C50): fino a sei, i pulsanti «Punto N» con il loro esito; oltre, un selettore su una riga: «‹», il menu
 * di scelta («Punto 3 di 15 · Pulizia delle grondaie», troncato con i puntini; nelle voci ogni punto con il suo esito, lo stesso badge dei pulsanti), «›».
 * Le frecce vanno al punto prima e dopo, spente al primo e all'ultimo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import SceltaDelPunto from './SceltaDelPunto.vue';
import type { PuntoDellaSala } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
            setup(p, { emit, attrs, slots }) {
                const scelta = () => (p.options ?? []).find((o: PuntoDellaSala) => (p.reduce ? p.reduce(o) : o) === p.modelValue);
                return () => h('div', { 'data-testid': attrs['data-testid'], class: attrs.class }, [
                    h('div', { 'data-scelta': '' }, scelta() ? slots['selected-option']?.(scelta()) : []),
                    ...(p.options ?? []).map((o: PuntoDellaSala, i: number) => h('button', { type: 'button', 'data-opzione': i,
                        onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o) }, slots.option?.(o))),
                ]);
            },
        }),
    };
});

const punto = (n: number, esito: PuntoDellaSala['esito'] = null) => ({ id: 100 + n, numero: n, testo: n === 3 ? 'Pulizia delle grondaie' : `Punto di prova ${n}`,
    esito, votazioni: [] }) as unknown as PuntoDellaSala;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const testo = (el: Element | null | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('fino a sei punti, i pulsanti', () => {
    test('«Punto N» con l\'esito, e il clic sceglie', async () => {
        w = mount(SceltaDelPunto, { attachTo: document.body, props: { punti: [punto(1, 'approvata'), punto(2), punto(3)], modelValue: 101 } });
        expect(w.find('[data-testid="punto-1"]').text()).toContain('Approvata');
        expect(w.find('[data-testid="selettore-dei-punti"]').exists()).toBe(false);
        await w.find('[data-testid="punto-3"]').trigger('click');
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([103]);
    });
});

describe('oltre sei punti, il selettore su una riga', () => {
    const quindici = () => Array.from({ length: 15 }, (_, i) => punto(i + 1, i === 0 ? 'approvata' : i === 1 ? 'respinta' : null));

    test('«‹ Punto 3 di 15 · Pulizia delle grondaie ›», troncato; nelle voci ogni punto con il suo esito', () => {
        w = mount(SceltaDelPunto, { attachTo: document.body, props: { punti: quindici(), modelValue: 103 } });
        expect(w.find('[data-testid="punto-1"]').exists()).toBe(false);
        const selettore = w.get('[data-testid="selettore-dei-punti"]');
        expect(selettore.classes()).toContain('flex-nowrap');
        expect(testo(selettore.find('[data-scelta]').element)).toBe('Punto 3 di 15 · Pulizia delle grondaie');
        expect(selettore.find('[data-scelta] [data-testid="punto-scelto"]').classes()).toContain('truncate');
        const voce = (i: number) => Array.from(selettore.find(`[data-opzione="${i}"]`).element.querySelectorAll('span span')).map((x) => testo(x));
        expect(voce(0)).toEqual(['Punto 1 · Punto di prova 1', 'Approvata']);
        expect(voce(1)).toEqual(['Punto 2 · Punto di prova 2', 'Respinta']);
        expect(voce(2)).toEqual(['Punto 3 · Pulizia delle grondaie']);
    });

    test('le frecce vanno al punto prima e dopo; spente al primo e all\'ultimo', async () => {
        w = mount(SceltaDelPunto, { attachTo: document.body, props: { punti: quindici(), modelValue: 103 } });
        await w.get('[data-testid="punto-prima"]').trigger('click');
        await w.get('[data-testid="punto-dopo"]').trigger('click');
        expect(w.emitted('update:modelValue')).toEqual([[102], [104]]);
        await w.setProps({ modelValue: 101 });
        expect((w.get('[data-testid="punto-prima"]').element as HTMLButtonElement).disabled).toBe(true);
        expect((w.get('[data-testid="punto-dopo"]').element as HTMLButtonElement).disabled).toBe(false);
        await w.setProps({ modelValue: 115 });
        expect((w.get('[data-testid="punto-dopo"]').element as HTMLButtonElement).disabled).toBe(true);
        // Dal menu di scelta.
        await w.get('[data-opzione="6"]').trigger('click');
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([107]);
    });
});

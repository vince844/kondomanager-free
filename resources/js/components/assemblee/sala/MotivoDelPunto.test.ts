// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il motivo della spesa nel punto della sala (C8, decisione 581): sotto il titolo «Perché era urgente: …» (o «Perché supera il preventivo: …»), scritto dal
 * server; senza un motivo, niente riga.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { computed, defineComponent, h, provide, ref } from 'vue';
import { mount, type VueWrapper } from '@vue/test-utils';
import { CONTESTO_DELLA_SALA } from './contesto';
import { salaAperta } from './salaDiProva';
import VotoDelPunto from './VotoDelPunto.vue';
import type { SalaDellAssemblea } from '@/types/assemblee';

vi.mock('vue-select', async () => {
    const { defineComponent: dc, h: hh } = await import('vue');
    return { default: dc({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => hh('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

function monta(motivazione: string | null) {
    const base = salaAperta();
    const punto = { ...base.punti[1], motivazione };
    const sala: SalaDellAssemblea = { ...base, in_coda: [] };
    const Contenitore = defineComponent({
        setup() {
            provide(CONTESTO_DELLA_SALA, {
                sala: computed(() => sala) as never, inCoda: computed(() => []), tenuta: ref(null), dispositivo: 'computer',
                segna: async () => {}, adesso: () => new Date('2026-12-15T18:40:00Z'), puoGestire: computed(() => true), dalFoglio: ref(false),
                oraDelFoglio: ref('19:40'), oraDelGesto: () => '19:40', nomeDi: () => '', scriviLaDiscussione: () => {},
            } as never);
            return () => h(VotoDelPunto as never, { punto, presenti: base.barra.presenti, costituzione: null });
        },
    });
    w = mount(Contenitore, { attachTo: document.body });
}

describe('il motivo della spesa nel punto della sala', () => {
    test('sotto il titolo, con le parole del server', () => {
        monta('Perché era urgente: Perdita dalla colonna dell\'acqua.');
        const riga = document.querySelector('[data-testid="motivazione-del-punto"]');
        expect(riga?.textContent).toBe('Perché era urgente: Perdita dalla colonna dell\'acqua.');
        const titolo = document.querySelector('h3')!;
        expect(titolo.nextElementSibling).toBe(riga);
    });

    test('senza un motivo, niente riga', () => {
        monta(null);
        expect(document.querySelector('[data-testid="motivazione-del-punto"]')).toBeNull();
    });
});

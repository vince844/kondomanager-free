// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/** «Aggiungi una persona»: il punto votato due volte (rendiconto e riparto) è una sola voce del menu «Assente per». */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    usePage: () => ({ url: '/', props: { auth: { user: { roles: ['amministratore'], permissions: [] } }, flash: {} } }),
}));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return { default: defineComponent({ name: 'VSelect', props: ['modelValue', 'options', 'label'], setup: (p) => () => h('div', { class: 'vs-finto' },
        ((p.options ?? []) as Record<string, unknown>[]).map((o) => h('span', { class: 'vs-opzione' }, String(o[(p.label as string) ?? 'label'] ?? '')))) }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

import AggiungiUnaPersona from './AggiungiUnaPersona.vue';

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const opzioni = () => [...document.body.querySelectorAll('.vs-opzione')].map((x) => (x.textContent ?? '').trim());

describe('«Aggiungi una persona»: il menu «Assente per»', () => {
    test('il punto 1 votato in due delibere è una voce sola', async () => {
        const punti = [
            { punto_id: 41, numero: 1, testo: 'Rendiconto 2025 e stato di riparto', votazione: 1, oggetto: 'rendiconto', votazione_id: 401, delibera_id: 71 },
            { punto_id: 41, numero: 1, testo: 'Rendiconto 2025 e stato di riparto', votazione: 2, oggetto: 'riparto', votazione_id: 402, delibera_id: 72 },
            { punto_id: 42, numero: 2, testo: 'Il portone', votazione: 1, oggetto: null, votazione_id: 403, delibera_id: 73 },
        ];
        w = mount(AggiungiUnaPersona, { attachTo: document.body, props: { open: true, rotta: '/aggiungi',
            trasmissione: { videoconferenza: false, punti, persone: [], quanti: 0 } as never } });
        await flushPromises();
        expect(opzioni().filter((x) => x.includes('punto') || x.includes('Tutti'))).toEqual(['Tutti i punti', 'Il punto 1', 'Il punto 2']);
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Genera il verbale dal modello» (B9, terzo giro): la finestra si apre con il menu «Il modello» chiuso. Il primo elemento che prende il fuoco è il menu, e il
 * menu si apre al fuoco: la finestra non porta il fuoco al menu, lo lascia a chi la apre.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import GeneraDalModello from './GeneraDalModello.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('«Genera il verbale dal modello»', () => {
    test('si apre con il menu «Il modello» chiuso', async () => {
        const pagina = { assemblea: { titolo: 'Assemblea ordinaria del 15/12/2026' }, verbale: null, rotte: { genera: '/genera' },
            modelli: { proposto: 1, ultimo_usato: null, modelli: [{ id: 1, nome: 'Il verbale dello studio', gruppo: 'condominio', ultimo_usato: false },
                { id: null, nome: 'Testo del programma', gruppo: 'programma', ultimo_usato: false }] } };
        w = mount(GeneraDalModello, { attachTo: document.body, props: { open: true, pagina: pagina as never, sedutaId: 1, rigenera: false, discussioni: 'scritte in sala', versione: null } });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 50));
        const menu = document.querySelector('[data-testid="modello"]');
        expect(menu).not.toBeNull();
        expect(menu!.classList.contains('vs--open')).toBe(false);
        expect(document.querySelector('.vs__dropdown-menu')).toBeNull();
    });
});

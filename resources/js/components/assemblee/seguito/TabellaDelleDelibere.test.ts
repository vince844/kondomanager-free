// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tabella delle delibere del passo 6 con il rendiconto e il riparto votati a parte (Coda 750 B6): due delibere dello stesso punto, due righe che si
 * distinguono, «· il rendiconto» e «· il riparto», dal tablet in su e al telefono. Il punto votato una volta resta com'è.
 *
 * Che cosa non copre: il server che manda `oggetto` (`PagineDelSeguitoTest.php`); il resto della tabella (`PassoDelleDelibere.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { rigaDelPasso } from './seguitoDiProva';
import TabellaDelleDelibere from './TabellaDelleDelibere.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, generatePath: (p: string) => p, hasPermission: () => true }) }));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: { collaboratori: [] } })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const testo = (x: Element | null) => (x?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('il rendiconto e il riparto votati a parte', () => {
    test('due righe dello stesso punto: «· il rendiconto» e «· il riparto», nella tabella e al telefono; il portone senza oggetto', async () => {
        w = mount(TabellaDelleDelibere, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 9, puoGestire: false,
            righe: [rigaDelPasso({ delibera_id: 1, oggetto: 'rendiconto' }), rigaDelPasso({ delibera_id: 2, oggetto: 'riparto' }),
                rigaDelPasso({ delibera_id: 4, punto: 4, punto_id: 40, testo: 'Portone: Serramenti Marino, € 14.500,00' })] } });
        await flushPromises();
        const grande = [...document.body.querySelectorAll('[data-testid="tabella-grande"] [data-testid="riga-del-passo"]')]
            .map((r) => testo(r.children[1]));
        expect(grande).toEqual(['Rendiconto 2025 e stato di riparto · il rendiconto', 'Rendiconto 2025 e stato di riparto · il riparto',
            'Portone: Serramenti Marino, € 14.500,00']);
        const telefono = [...document.body.querySelectorAll('[data-testid="riga-al-telefono"]')].map((r) => testo(r.children[0]));
        expect(telefono).toEqual(['1Rendiconto 2025 e stato di riparto · il rendiconto', '1Rendiconto 2025 e stato di riparto · il riparto', '4Portone: Serramenti Marino, € 14.500,00']);
    });
});

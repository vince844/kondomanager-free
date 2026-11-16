// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Sei mesi per agire» nella dashboard del condominio, quando le rate scadute sono troppe per calcolare qui le date: il collegamento porta all'elenco delle
 * rate scadute in ordine di data dei sei mesi e lo dice, come la lista dello studio per lo stesso caso. Mai un rimando generico («Vedi le rate»).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: vi.fn(), post: vi.fn(), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, generatePath: (p: string) => `/${p}`, hasPermission: () => true }),
}));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Widget = (await import('./SeiMesiPerAgireWidget.vue')).default;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('«Sei mesi per agire» con troppe rate scadute', () => {
    test('il collegamento apre l\'elenco delle rate scadute in ordine di data dei sei mesi, e lo dice', () => {
        w = mount(Widget, { attachTo: document.body, props: { condominioId: 7, puoRispondere: true, seiMesi: { oggi: '2026-11-16', passo_proposto: true,
            troppe: { quote: 9000, frase: 'Questo condominio ha troppe rate scadute per calcolare qui i sei mesi: le date sono nell\'elenco «Rate scadute».' },
            righe: [], senza_data: null, rendiconti: [], compiti: [] } } as any });
        const link = [...document.body.querySelectorAll('a')].find((a) => /rate-scadute/.test(a.getAttribute('href') ?? ''))!;
        expect(link.getAttribute('href')).toContain('ordine=sei_mesi');
        expect(pulito(link.textContent)).not.toContain('Vedi le rate');
        expect(pulito(link.textContent)).toBe('Apri l\'elenco per data dei sei mesi →');
    });
});

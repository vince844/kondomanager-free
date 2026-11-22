// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'intestazione delle pagine di una sola scheda (un'assemblea, la sua sala, il suo verbale): cambiare il condominio nell'indirizzo porterebbe a una scheda che
 * non esiste e dà 404 (decisione 660). Con `condominio-fisso` il condominio si mostra come con uno solo, il riquadro con il nome e senza menu; senza, il menu c'è.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/7/assemblee/8/il-verbale', props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n }) }));

const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const props = (altro: Record<string, unknown> = {}) => ({
    pageTitle: 'Il verbale', guides: [], condominio: { id: 7, nome: 'Via Roma 12' },
    condomini: [{ id: 7, nome: 'Via Roma 12' }, { id: 9, nome: 'Via Milano 3' }], ...altro,
});

describe('il condominio fisso nell\'intestazione', () => {
    test('senza `condominio-fisso` il menu dei condomini c\'è', () => {
        w = mount(PageHeaderGuide, { props: props() as any });
        expect(w.find('[data-testid="scegli-condominio"]').exists()).toBe(true);
    });

    test('con `condominio-fisso` il condominio è il riquadro con il nome, senza menu', () => {
        w = mount(PageHeaderGuide, { props: props({ condominioFisso: true }) as any });
        expect(w.find('[data-testid="scegli-condominio"]').exists()).toBe(false);
        expect(w.get('[data-testid="nome-condominio"]').text()).toBe('Via Roma 12');
    });

    test('le tre pagine di una sola assemblea lo chiedono', () => {
        for (const f of ['Assemblea', 'Sala', 'Verbale']) {
            expect(readFileSync(join(__dirname, `../pages/gestionale/assemblee/${f}.vue`), 'utf8'), f).toContain('condominio-fisso');
        }
    });
});

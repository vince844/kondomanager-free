// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Lo spazio accanto al titolo: una pagina può mettere vicino al titolo ciò che descrive la scheda (lo stato di un'assemblea, in un badge) invece che fra i
 * pulsanti, che restano per le azioni e così stanno su una fila. Con lo spazio il titolo e ciò che gli sta accanto sono sulla stessa riga; senza, il titolo
 * resta com'era in tutte le altre pagine.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/7', props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n }) }));

const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const props = (altro: Record<string, unknown> = {}) => ({
    pageTitle: 'Assemblea ordinaria di Via Roma 12', guides: [], hasTextGuide: true, textGuideTitle: 'Guida',
    condominio: { id: 7, nome: 'Via Roma 12' }, condomini: [],
    ...altro,
});
const badge = { 'accanto-al-titolo': '<span data-testid="stato">In preparazione</span>' };

describe('lo spazio accanto al titolo', () => {
    test('con il percorso: ciò che la pagina mette accanto al titolo sta sulla riga del titolo, non fra i pulsanti', () => {
        w = mount(PageHeaderGuide, { props: props({ breadcrumbs: [{ title: 'Assemblee', href: '/a' }, { title: 'Ordinaria' }] }) as any, slots: badge });
        const riga = w.get('[data-testid="riga-del-titolo"]');
        expect(riga.find('h1').text()).toBe('Assemblea ordinaria di Via Roma 12');
        expect(riga.find('[data-testid="stato"]').exists()).toBe(true);
        expect(w.get('[data-testid="pulsanti-intestazione"]').find('[data-testid="stato"]').exists()).toBe(false);
    });

    test('senza il percorso vale lo stesso', () => {
        w = mount(PageHeaderGuide, { props: props() as any, slots: badge });
        const riga = w.get('[data-testid="riga-del-titolo"]');
        expect(riga.find('h1').exists()).toBe(true);
        expect(riga.find('[data-testid="stato"]').exists()).toBe(true);
    });

    test('senza niente accanto, il titolo resta com\'era: nessuna riga in più', () => {
        w = mount(PageHeaderGuide, { props: props({ breadcrumbs: [{ title: 'Assemblee', href: '/a' }, { title: 'Ordinaria' }] }) as any });
        expect(w.find('[data-testid="riga-del-titolo"]').exists()).toBe(false);
        expect(w.find('h1').text()).toBe('Assemblea ordinaria di Via Roma 12');
    });
});

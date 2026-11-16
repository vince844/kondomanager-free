// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'intestazione delle pagine con `una-fila`: i pulsanti (il condominio, l'esercizio, «Guida», «Dashboard social») stanno su una riga che non va a capo, alti
 * uguali, e il nome di un condominio lungo si tronca con i puntini invece di spingere la fila. Da `xl` (lo schermo del portatile e del fisso, con la barra
 * laterale aperta): sotto, con la barra laterale aperta i quattro pulsanti non ci stanno, e la fila va a capo invece di far scorrere la pagina di lato. Senza
 * `una-fila` le altre pagine restano come sono.
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
    pageTitle: 'Dashboard gestionale', guides: [], hasTextGuide: true, textGuideTitle: 'Guida',
    condominio: { id: 7, nome: 'Condominio Residenza dei Giardini di Via Giuseppe Garibaldi 128' }, condomini: [], esercizio: { id: 1, nome: 'Esercizio 2026' }, esercizi: [],
    ...altro,
});
const fila = () => w!.get('[data-testid="pulsanti-intestazione"]');

describe('l\'intestazione con una fila sola', () => {
    test('con una-fila i pulsanti non vanno a capo, e il nome del condominio si tronca', () => {
        w = mount(PageHeaderGuide, { props: props({ unaFila: true }) as any });
        expect(fila().classes()).toContain('xl:flex-nowrap');
        const nome = w.get('[data-testid="nome-condominio"]');
        expect(nome.classes()).toEqual(expect.arrayContaining(['truncate']));
        expect(nome.attributes('title')).toBe('Condominio Residenza dei Giardini di Via Giuseppe Garibaldi 128');
        expect(w.text()).toContain('Guida');
    });

    test('senza una-fila le altre pagine restano come sono', () => {
        w = mount(PageHeaderGuide, { props: props() as any });
        expect(fila().classes()).toContain('flex-wrap');
        expect(fila().classes()).not.toContain('xl:flex-nowrap');
        expect(w.get('[data-testid="nome-condominio"]').classes()).not.toContain('truncate');
    });
});

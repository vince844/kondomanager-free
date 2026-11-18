// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione, nel pannello e nel portale: il riquadro dei commenti ha `id="commenti"`.
 *
 * La pagina «Commenti» del pannello (Coda 657) porta alla scheda con `…#commenti`, e le email dei commenti puntano allo stesso
 * indirizzo (punto 8 della Coda 588). Le comunicazioni ce l'hanno già (`ComunicazioniView.vue`); le segnalazioni no.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => false, hasRole: () => false }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ViewPannello = (await import('./SegnalazioniView.vue')).default;
const ViewPortale = (await import('./user/SegnalazioniView.vue')).default;

const segnalazione = {
    id: 9, subject: 'Il cancello non chiude', description: 'Da due giorni', priority: 'media', stato: 'aperta',
    is_private: false, is_published: true, is_approved: true, is_locked: false, created_at: 'ieri', can_comment: true,
    created_by: { user: { name: 'Mario Rossi' } },
    condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
    anagrafiche: [], commenti: [], commentiInAttesa: [],
};
const commenti_config = { can_comment: true, can_create: true, can_moderate: false, can_publish: true };

describe('SegnalazioniView — l\'ancora dei commenti', () => {
    test.each([
        ['il pannello', ViewPannello],
        ['il portale', ViewPortale],
    ])('%s: il riquadro dei commenti ha id="commenti"', (_nome, Vista) => {
        const w = shallowMount(Vista as never, {
            props: { segnalazione, commenti_config } as never,
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });

        const ancora = w.find('#commenti');
        expect(ancora.exists()).toBe(true);
        expect(ancora.findComponent({ name: 'ListaCommenti' }).exists()).toBe(true);
    });
});

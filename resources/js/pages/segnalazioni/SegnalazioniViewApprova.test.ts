// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione nel pannello: «Approva» fra i comandi (giro 2).
 *
 * Fino a qui una segnalazione si approvava solo dallo scudo dell'elenco. Il pulsante (`button[data-azione="approva"]`)
 * c'è su una segnalazione non approvata, per chi approva le segnalazioni, e chiama lo stesso comando con
 * `is_approved: true`: così un doppio clic non toglie l'approvazione appena data.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const permessi = vi.hoisted(() => ({ puoApprovare: true }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => permessi.puoApprovare, hasRole: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 31, subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media', stato: 'aperta',
                is_private: false, is_published: true, is_approved: false, is_locked: false, can_comment: true, created_at: 'ieri',
                created_by: { user: { name: 'mrossi' }, anagrafica: { nome: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                anagrafiche: [], commenti: [], commentiInAttesa: [], assegnata_a: null,
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            assegnazione: { puo_assegnare: true, io: 1, assegnabili: [] },
        } as never,
        global: {
            renderStubDefaultSlot: true,
            mocks: { route: (n: string) => `/${n}` },
            stubs: { PageHeaderGuide: { template: '<div><slot name="actions" /></div>' } },
        },
    });
}

const approva = (pagina: ReturnType<typeof monta>) => pagina.find('button[data-azione="approva"]');

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});
afterEach(() => {
    permessi.puoApprovare = true;
});

describe('SegnalazioniView del pannello — «Approva»', () => {
    test('su una segnalazione non approvata c\'è, e chiama lo scudo con lo stato voluto', async () => {
        const pagina = monta();

        expect(approva(pagina).exists()).toBe(true);
        expect(approva(pagina).text()).toContain('segnalazioni.actions.approve');

        await approva(pagina).trigger('click');

        const chiamate = vi.mocked(router.put).mock.calls;
        expect(chiamate).toHaveLength(1);
        expect(String(chiamate[0][0])).toContain('segnalazioni.toggle-approval');
        expect(chiamate[0][1]).toEqual({ is_approved: true });
        // P7 della lente P (Fase 1-bis della beta.12): una visita parziale, non la scheda intera; con il codice di prima solo `preserveScroll`.
        expect(chiamate[0][2]).toMatchObject({ preserveScroll: true, preserveState: true,
            only: ['segnalazione', 'commenti_config', 'allegati', 'allegati_config', 'rendi_pubblica', 'inbox_count', 'flash'] });
    });

    test('su una segnalazione approvata non c\'è', () => {
        expect(approva(monta({ is_approved: true })).exists()).toBe(false);
        expect(approva(monta({ is_approved: 1 })).exists()).toBe(false);
    });

    test('chi non approva le segnalazioni non lo ha', () => {
        permessi.puoApprovare = false;

        expect(approva(monta()).exists()).toBe(false);
    });
});

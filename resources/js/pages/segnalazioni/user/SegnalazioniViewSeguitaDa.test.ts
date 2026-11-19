// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione nel portale del condòmino: «Seguita da …» (decisione 294, punto 3).
 *
 * Il server manda `segnalazione.seguita_da`, il nome di chi nello studio segue la segnalazione, solo se
 * l'amministratore ha acceso l'impostazione. La pagina lo mostra in un elemento con `data-campo="seguita-da"`; senza
 * il nome, l'elemento non c'è. Nel portale non c'è niente per assegnare.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 7, roles: ['utente'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => false, hasRole: () => false }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, argomenti?: Record<string, unknown>) => (argomenti ? `${chiave} ${JSON.stringify(argomenti)}` : chiave),
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;

function monta(campi: Record<string, unknown>) {
    return shallowMount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 31, subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media',
                stato: 'aperta', is_private: false, is_published: true, is_approved: true, is_locked: false, created_at: 'ieri',
                can_comment: true, created_by: { user: { name: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                commenti: [], commentiInAttesa: [],
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: false, can_publish: true },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('SegnalazioniView del portale — «Seguita da …»', () => {
    test('con il nome mandato dal server, la scheda dice chi segue la segnalazione', () => {
        const riga = monta({ seguita_da: 'Elena Studio' }).find('[data-campo="seguita-da"]');

        expect(riga.exists()).toBe(true);
        expect(riga.text()).toContain('segnalazioni.assignment.followed_by');
        expect(riga.text()).toContain('Elena Studio');
    });

    test('dice solo «Seguita da …»: niente titolo «Assegnata a», che è il linguaggio dello studio', () => {
        const riga = monta({ seguita_da: 'Elena Studio' }).find('[data-campo="seguita-da"]');

        expect(riga.text()).not.toContain('segnalazioni.assignment.label');
        expect(riga.text().match(/segnalazioni\.assignment\./g)).toHaveLength(1);
    });

    test.each([
        ['senza il campo', {}],
        ['con il campo vuoto', { seguita_da: null }],
    ])('%s, la scheda non dice niente', (_caso, campi) => {
        const pagina = monta(campi);

        expect(pagina.find('[data-campo="seguita-da"]').exists()).toBe(false);
        expect(pagina.text()).not.toContain('segnalazioni.assignment');
    });

    test('nel portale non c\'è niente per assegnare', () => {
        const pagina = monta({ seguita_da: 'Elena Studio' });

        expect(pagina.find('[data-azione="assegna-a-me"]').exists()).toBe(false);
        expect(pagina.find('#assegnatario').exists()).toBe(false);
        expect(pagina.find('[data-campo="assegnata-a"]').exists()).toBe(false);
    });
});

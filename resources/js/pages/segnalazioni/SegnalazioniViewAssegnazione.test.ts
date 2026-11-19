// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione nel pannello: il riquadro «Assegnata a» (decisione 294).
 *
 * La pagina riceve `assegnazione` dal server e passa al componente `AssegnazioneSegnalazione` l'id della segnalazione,
 * `segnalazione.assegnata_a` e `assegnazione`. Che cosa fa il riquadro lo prova il test del componente.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import AssegnazioneSegnalazione from '@/components/segnalazioni/AssegnazioneSegnalazione.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;

const elena = { id: 9, name: 'Elena Studio', attivo: true, da: 'Studio Bianchi', il: '10/11/2026', alle: '09:30' };
const assegnazione = { puo_assegnare: true, io: 1, assegnabili: [{ id: 9, name: 'Elena Studio' }, { id: 1, name: 'Studio Bianchi' }] };

function monta(campi: Record<string, unknown>, altreProps: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 31, subject: 'Il cancello non chiude', description: 'Da due giorni resta aperto', priority: 'media', stato: 'aperta',
                is_private: false, is_published: true, is_approved: true, is_locked: false, can_comment: true, created_at: 'ieri',
                created_by: { user: { name: 'mrossi' }, anagrafica: { nome: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                anagrafiche: [], commenti: [], commentiInAttesa: [],
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            ...altreProps,
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('SegnalazioniView del pannello — il riquadro «Assegnata a»', () => {
    test('passa al riquadro la segnalazione, chi la segue e ciò che il server dice di chi guarda', () => {
        const riquadro = monta({ assegnata_a: elena }, { assegnazione }).findComponent(AssegnazioneSegnalazione);

        expect(riquadro.exists()).toBe(true);
        expect(riquadro.props('segnalazioneId')).toBe(31);
        expect(riquadro.props('assegnataA')).toEqual(elena);
        expect(riquadro.props('assegnazione')).toEqual(assegnazione);
    });

    test('una segnalazione di nessuno arriva al riquadro come `null`', () => {
        const riquadro = monta({ assegnata_a: null }, { assegnazione }).findComponent(AssegnazioneSegnalazione);

        expect(riquadro.exists()).toBe(true);
        expect(riquadro.props('assegnataA')).toBeNull();
    });

    test('chi non può assegnare ha lo stesso il riquadro: decide il server, non la pagina', () => {
        const sola = { puo_assegnare: false, io: 1, assegnabili: [] };
        const riquadro = monta({ assegnata_a: elena }, { assegnazione: sola }).findComponent(AssegnazioneSegnalazione);

        expect(riquadro.exists()).toBe(true);
        expect(riquadro.props('assegnazione')).toEqual(sola);
    });
});

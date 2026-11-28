// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Apri lo storico» dal riquadro delle righe chiuse a quota zero (`RigheAQuotaZero`, `url_storico` con `?storico=1`) apre la pagina dei titolari dell'unità
 * con il pannello «Chi ha avuto questa unità» già aperto; senza il parametro il pannello resta chiuso.
 *
 * Cosa NON copre: il pannello stesso (`TitolaritaSheet`), le sue azioni, e il server che compone l'indirizzo (`RigheAQuotaZeroTest.php`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const AnagraficheList = (await import('./AnagraficheList.vue')).default;

function monta() {
    return shallowMount(AnagraficheList, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 5, nome: 'Interno 5', interno: '5', anagrafiche: [] } as never,
            storico: { passaggi: 0, periodi_chiusi: 1, righe: [], gruppi: [], subentri: [] } as never,
            oggi: '2026-11-24',
            prospettoOneri: null,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

const aperto = (w: ReturnType<typeof monta>) => w.findComponent({ name: 'TitolaritaSheet' }).props('open');

afterEach(() => window.history.replaceState(null, '', '/'));

describe('AnagraficheList — «Apri lo storico» con ?storico=1', () => {
    test('con ?storico=1 il pannello dello storico è già aperto', () => {
        window.history.replaceState(null, '', '/condomini/1/immobili/5/anagrafiche?storico=1');
        expect(aperto(monta())).toBe(true);
    });

    test('senza il parametro resta chiuso', () => {
        window.history.replaceState(null, '', '/condomini/1/immobili/5/anagrafiche');
        expect(aperto(monta())).toBe(false);
    });
});

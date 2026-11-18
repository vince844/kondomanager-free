// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il primo riquadro del riquadro «Commenti» della segnalazione comincia alla stessa altezza di quello degli allegati (Vincenzo,
 * 17/11/2026 sera: «il riquadro tratteggiato dei commenti non è alla stessa altezza del riquadro tratteggiato degli allegati»). Misurato
 * a video: il primo riquadro dei commenti stava 8 px più in basso (73 contro 65 dall'inizio della card); l'intestazione dei commenti perde
 * il `pb-1` e prende `mb-3`, così fra il titolo e il primo riquadro restano 12 px come negli allegati (`pb-3` dell'intestazione della card).
 * In Tailwind 4 `space-y-4` mette il margine in fondo ai figli, e `mb-3` lo sostituisce: la prima prova, `-mb-1`, portava il riquadro a 49 px.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => `admin.${n}`, hasPermission: () => true, hasRole: () => true, getRolePrefix: () => 'admin' }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const ListaCommenti = (await import('./ListaCommenti.vue')).default;

describe('ListaCommenti — il primo riquadro alla stessa altezza degli allegati', () => {
    test('l\'intestazione ha la linea sotto, larga quanto la card, e 16 px sopra e sotto', () => {
        const w = mount(ListaCommenti, {
            props: {
                segnalazioneId: 9, commenti: [], commentiInAttesa: [], currentUserId: 1,
                commentiConfig: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            },
            global: { mocks: { route: (n: string) => `/${n}` } },
        });
        const intestazione = w.get('[data-intestazione-commenti]').classes();

        expect(intestazione).not.toContain('pb-1');
        // Decisione 469: una linea sottile sotto l'intestazione, larga quanto la card (`-mx-6 px-6` nel `p-6` della card), 16 px sopra e sotto,
        // come l'intestazione della card degli allegati.
        expect(intestazione).toEqual(expect.arrayContaining(['border-b', '-mx-6', 'px-6', 'pb-4', 'mb-4']));
        expect(intestazione).not.toContain('mb-3');
        expect(intestazione).not.toContain('-mb-1');
    });
});

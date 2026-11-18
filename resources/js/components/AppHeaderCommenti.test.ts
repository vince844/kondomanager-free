// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu del pannello: la voce «Commenti» sta accanto ad «Allegati», con lo stesso permesso della pagina (Coda 657).
 *
 * La pagina la apre chi ha il pannello e legge i commenti delle segnalazioni o delle comunicazioni
 * (`RegoleCommenti::puoAprirePagina`): senza uno dei due permessi, o senza il pannello, la voce non c'è.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const utente = vi.hoisted(() => ({ permissions: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/dashboard', props: { auth: { user: { id: 1, name: 'Studio', roles: [], permissions: utente.permissions } }, system_update: null, allegati_in_attesa: 0 } }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const AppHeader = (await import('./AppHeader.vue')).default;

function voci(permessi: string[]): string[] {
    utente.permissions = permessi;
    const w = mount(AppHeader, {
        global: {
            stubs: { Sheet: true, SheetContent: true, SheetTrigger: true, DropdownMenu: true, HoverCard: true, Tooltip: true, TooltipProvider: { template: '<div><slot /></div>' }, Breadcrumbs: true, UserMenuContent: true, AppLogo: true, AppLogoIcon: true },
        },
    });

    return w.findAll('a').map((a) => a.attributes('href') ?? '');
}

describe('AppHeader — la voce «Commenti»', () => {
    test('c\'è, accanto ad «Allegati», con il pannello e il permesso di leggere i commenti', () => {
        const link = voci(['Accesso pannello amministratore', 'Visualizza commenti segnalazioni', 'Visualizza allegati segnalazioni']);

        expect(link).toContain('/admin/commenti');
        expect(link.indexOf('/admin/commenti')).toBe(link.indexOf('/admin/allegati') + 1);
        expect(voci(['Accesso pannello amministratore', 'Visualizza commenti comunicazioni'])).toContain('/admin/commenti');
    });

    test('non c\'è senza il pannello o senza un permesso di lettura dei commenti', () => {
        expect(voci(['Visualizza commenti segnalazioni', 'Visualizza commenti comunicazioni'])).not.toContain('/admin/commenti');
        expect(voci(['Accesso pannello amministratore', 'Visualizza segnalazioni'])).not.toContain('/admin/commenti');
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu del pannello: la voce «Impostazioni» c'è per chi può aprire almeno una scheda della pagina.
 *
 * Le schede e i loro permessi stanno nel server (`ImpostazioniController::SCHEDE`): «Gestisci impostazioni generali», «Visualizza
 * utenti», «Vede il registro delle modifiche» (mai per i ruoli del portale) e il ruolo amministratore. Il collaboratore, che vede
 * gli utenti, trova la voce; il condòmino no.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const utente = vi.hoisted(() => ({ permissions: [] as string[], roles: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/dashboard', props: { auth: { user: { id: 1, name: 'Studio', roles: utente.roles, permissions: utente.permissions } }, system_update: null, allegati_in_attesa: 0 } }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const AppHeader = (await import('./AppHeader.vue')).default;

function voci(permessi: string[], ruoli: string[] = []): string[] {
    utente.permissions = permessi;
    utente.roles = ruoli;
    const w = mount(AppHeader, {
        global: {
            stubs: { Sheet: true, SheetContent: true, SheetTrigger: true, DropdownMenu: true, HoverCard: true, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: true, TooltipProvider: { template: '<div><slot /></div>' }, Breadcrumbs: true, UserMenuContent: true, AppLogo: true, AppLogoIcon: true },
        },
    });
    return w.findAll('a').map((a) => a.attributes('href') ?? '');
}

describe('AppHeader — la voce «Impostazioni»', () => {
    test('c\'è per il collaboratore, che vede gli utenti', () => {
        expect(voci(['Accesso pannello amministratore', 'Visualizza utenti'], ['collaboratore'])).toContain('/impostazioni');
    });

    test('c\'è per chi gestisce le impostazioni generali, per chi vede il registro e per l\'amministratore', () => {
        expect(voci(['Accesso pannello amministratore', 'Gestisci impostazioni generali'], ['tecnico'])).toContain('/impostazioni');
        expect(voci(['Accesso pannello amministratore', 'Vede il registro delle modifiche'], ['revisore'])).toContain('/impostazioni');
        expect(voci([], ['amministratore'])).toContain('/impostazioni');
    });

    test('non c\'è per chi non può aprire nessuna scheda', () => {
        expect(voci(['Accesso pannello amministratore', 'Visualizza condomini'], ['osservatore'])).not.toContain('/impostazioni');
        // Il registro non vale per i ruoli del portale, come nella sua pagina.
        expect(voci(['Vede il registro delle modifiche'], ['utente'])).not.toContain('/impostazioni');
    });
});

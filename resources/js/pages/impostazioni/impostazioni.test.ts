// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina «Impostazioni» mostra le schede che il server le manda (`schede`, da `ImpostazioniController::SCHEDE`) e nessun'altra.
 * Il collaboratore riceve solo «utenti»; l'amministratore tutte. Il collegamento esterno di sostegno al progetto resta per tutti.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const pagina = vi.hoisted(() => ({ schede: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { schede: pagina.schede, backups_enabled: true, backup_running: false, system_update: null } }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

const Impostazioni = (await import('./impostazioni.vue')).default;

function indirizzi(schede: string[]): string[] {
    pagina.schede = schede;
    const w = mount(Impostazioni, { global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Heading: true } } });
    return w.findAll('a').map((a) => a.attributes('href') ?? '');
}

describe('impostazioni — le schede secondo i permessi', () => {
    test('il collaboratore vede solo «Utenti» (e il sostegno al progetto)', () => {
        expect(indirizzi(['utenti'])).toEqual(['/utenti', 'https://www.patreon.com/KondoManager']);
    });

    test('con tutte le schede, tutte le schede', () => {
        const tutte = ['generali', 'registro', 'stampe', 'assemblee', 'utenti', 'posta', 'cron', 'errori', 'backup', 'installazione', 'aggiornamento'];
        expect(indirizzi(tutte)).toEqual([
            '/impostazioni/generali', '/impostazioni/registro-modifiche', '/impostazioni/stampe', '/impostazioni/assemblee', '/utenti',
            '/impostazioni/mail', '/impostazioni/cron', '/logs', '/impostazioni/backups', '/impostazioni/installazione', '/system/upgrade',
            'https://www.patreon.com/KondoManager',
        ]);
    });
});

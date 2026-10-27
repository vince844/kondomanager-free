// @vitest-environment jsdom

/**
 * Il menù di una riga dell'elenco utenti offre solo ciò che il server accetterebbe (Coda 258, decisioni 134.1,
 * 136.1 e 136.3).
 *
 * Ogni comando chiede il permesso che chiede la sua rotta: «Modifica» e «Reinvita» «Modifica utenti»,
 * «Sospendi» e «Attiva» «Sospendi utenti», «Elimina» «Elimina utenti». E vale solo per un account nella
 * portata di chi guarda: `gestibile` lo calcola il server con la stessa regola che usa per rifiutare. Fuori
 * portata anche la scheda è solo dell'amministratore (decisione 136.1), quindi il menù non offre niente e non
 * compare.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { roles: ['Segreteria'], get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

const TUTTI = ['Modifica utenti', 'Sospendi utenti', 'Elimina utenti'];

function monta(utente: Record<string, unknown>) {
    return mount(DataTableRowActions, {
        props: {
            user: {
                id: '5', name: 'Anna', email: 'anna@example.com', suspended_at: null, email_verified_at: '2026-10-01',
                is_self: false, is_ultimo_amministratore: false, roles: [], permissions: [], anagrafica: null,
                ...utente,
            } as never,
        },
        global: {
            stubs: {
                DropdownMenu: { template: '<div class="menu"><slot /></div>' },
                DropdownMenuContent: { template: '<div><slot /></div>' },
                DropdownMenuTrigger: { template: '<div><slot /></div>' },
                DropdownMenuLabel: { template: '<div><slot /></div>' },
                DropdownMenuItem: { template: '<div class="voce"><slot /></div>' },
                ConfirmDialog: true,
                Button: { template: '<button><slot /></button>' },
            },
        },
    });
}

function voci(utente: Record<string, unknown>): string {
    return monta(utente).findAll('.voce').map((voce) => voce.text()).join('|');
}

beforeEach(() => {
    chiGuarda.permessi = [...TUTTI];
});

describe('DataTableRowActions degli utenti — i comandi seguono la portata (Coda 258, decisioni 134.1 e 136.1)', () => {
    test('nella portata il menù offre modificare, sospendere, reinvitare ed eliminare', () => {
        const menu = voci({ gestibile: true });

        expect(menu).toContain('users.actions.edit_user');
        expect(menu).toContain('users.actions.suspend_user');
        expect(menu).toContain('users.actions.invite_user');
        expect(menu).toContain('users.actions.delete_user');
    });

    test('fuori portata il menù non offre niente, nemmeno «Modifica», e non compare', () => {
        const riga = monta({ gestibile: false });

        expect(riga.findAll('.voce')).toHaveLength(0);
        expect(riga.find('.menu').exists()).toBe(false);
        expect(riga.find('button').exists()).toBe(false);
    });

    test('fuori portata un account sospeso non offre «Attiva»; nella portata sì', () => {
        expect(voci({ gestibile: false, suspended_at: '2026-10-20' })).toBe('');
        expect(voci({ gestibile: true, suspended_at: '2026-10-20' })).toContain('users.actions.activate_user');
    });
});

describe('DataTableRowActions degli utenti — ogni comando chiede il permesso della sua rotta (decisione 136.3)', () => {
    test('senza «Modifica utenti» il menù non offre «Modifica» né «Reinvita»', () => {
        chiGuarda.permessi = ['Sospendi utenti', 'Elimina utenti'];
        const menu = voci({ gestibile: true });

        expect(menu).not.toContain('users.actions.edit_user');
        expect(menu).not.toContain('users.actions.invite_user');
        expect(menu).toContain('users.actions.suspend_user');
        expect(menu).toContain('users.actions.delete_user');
    });

    test('con il solo «Modifica utenti» il menù offre «Modifica» e «Reinvita», e non sospendere né eliminare', () => {
        chiGuarda.permessi = ['Modifica utenti'];

        expect(voci({ gestibile: true })).toBe('users.actions.edit_user|users.actions.invite_user');
    });

    test('con i soli permessi di vedere gli utenti il menù non compare', () => {
        chiGuarda.permessi = ['Visualizza utenti'];
        const riga = monta({ gestibile: true });

        expect(riga.findAll('.voce')).toHaveLength(0);
        expect(riga.find('.menu').exists()).toBe(false);
    });
});

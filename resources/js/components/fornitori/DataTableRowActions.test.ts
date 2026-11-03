// @vitest-environment jsdom

/**
 * Il menù di una riga dell'elenco fornitori offre solo ciò che il server accetterebbe (Coda 402).
 *
 * `FornitorePolicy` chiede a ogni azione il suo permesso, con i permessi delle anagrafiche: «Modifica» apre il modulo di
 * modifica, che vuole «Modifica anagrafiche»; «Elimina» vuole «Elimina anagrafiche». Il collaboratore di serie ha
 * «Visualizza», «Crea» e «Modifica anagrafiche», non «Elimina anagrafiche»: il menù non gli offre l'eliminazione, come
 * nell'elenco delle anagrafiche. Chi ha la sola consultazione non ha comandi, e il menù non compare.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}/${parametri?.id ?? ''}`;
(globalThis as Record<string, unknown>).route = route;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

/** I permessi delle anagrafiche del collaboratore di serie (`App\Enums\Role::COLLABORATORE->permissions()`). */
const COLLABORATORE = ['Accesso pannello amministratore', 'Crea utenti', 'Modifica utenti', 'Visualizza utenti', 'Crea anagrafiche', 'Modifica anagrafiche', 'Visualizza anagrafiche'];
const ANAGRAFICHE = ['Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'];

function monta() {
    return mount(DataTableRowActions, {
        props: { fornitore: { id: 9, ragione_sociale: "Idraulica D'Àngelo S.r.l." } as never },
        global: {
            mocks: { route },
            stubs: {
                DropdownMenu: { template: '<div class="menu"><slot /></div>' },
                DropdownMenuContent: { template: '<div><slot /></div>' },
                DropdownMenuTrigger: { template: '<div><slot /></div>' },
                DropdownMenuLabel: { template: '<div><slot /></div>' },
                DropdownMenuItem: { template: '<div class="voce"><slot /></div>' },
                ConfirmDialog: true,
                Button: { template: '<button><slot /></button>' },
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
        },
    });
}

function voci(): string {
    return monta().findAll('.voce').map((voce) => voce.text().trim()).join('|');
}

beforeEach(() => {
    chiGuarda.ruoli = ['amministratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', ...ANAGRAFICHE];
});

describe('DataTableRowActions dei fornitori — ogni comando chiede il permesso della sua azione (Coda 402)', () => {
    test('con i quattro permessi delle anagrafiche il menù offre «Modifica» ed «Elimina»', () => {
        const riga = monta();

        expect(voci()).toBe('Modifica|Elimina');
        expect(riga.find('a').attributes('href')).toBe('/admin.fornitori.edit/9');
    });

    test('al collaboratore di serie il menù offre «Modifica» e non «Elimina»', () => {
        chiGuarda.ruoli = ['collaboratore'];
        chiGuarda.permessi = COLLABORATORE;

        expect(voci()).toBe('Modifica');
    });

    test('con «Elimina anagrafiche» senza «Modifica anagrafiche» il menù offre soltanto «Elimina»', () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Elimina anagrafiche'];

        expect(voci()).toBe('Elimina');
    });

    test('con la sola consultazione il menù non compare', () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Crea utenti'];
        const riga = monta();

        expect(riga.findAll('.voce')).toHaveLength(0);
        expect(riga.find('.menu').exists()).toBe(false);
        expect(riga.find('button').exists()).toBe(false);
    });
});

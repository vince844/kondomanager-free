// @vitest-environment jsdom

/**
 * Il menù di una riga dell'elenco anagrafiche offre solo ciò che il server accetterebbe (decisioni 137 e 138.1).
 *
 * `AnagraficaPolicy` chiede a ogni azione il suo permesso: «Modifica» apre il modulo di modifica, che vuole
 * «Modifica anagrafiche»; «Elimina» vuole «Elimina anagrafiche». Il collaboratore di serie ha «Visualizza», «Crea»
 * e «Modifica anagrafiche», non «Elimina anagrafiche» (come per condomìni e utenti): il menù non gli offre
 * l'eliminazione. Chi ha la sola consultazione non ha comandi, e il menù non compare.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}/${parametri?.id ?? ''}`;
(globalThis as Record<string, unknown>).route = route;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

/** I permessi del collaboratore di serie (`App\Enums\Role::COLLABORATORE->permissions()`), come li manda il server. */
const COLLABORATORE = [
    'Accesso pannello amministratore', 'Crea utenti', 'Modifica utenti', 'Visualizza utenti', 'Crea anagrafiche',
    'Modifica anagrafiche', 'Visualizza anagrafiche', 'Crea condomini', 'Modifica condomini', 'Visualizza condomini',
    'Crea comunicazioni', 'Pubblica comunicazioni', 'Modifica comunicazioni', 'Visualizza comunicazioni', 'Commenta comunicazioni',
    'Pubblica commenti comunicazioni', 'Modifica commenti comunicazioni', 'Visualizza commenti comunicazioni', 'Crea segnalazioni',
    'Pubblica segnalazioni', 'Modifica segnalazioni', 'Visualizza segnalazioni', 'Commenta segnalazioni', 'Modifica commenti segnalazioni',
    'Pubblica commenti segnalazioni', 'Visualizza commenti segnalazioni', 'Visualizza documenti archivio', 'Crea documenti archivio',
    'Modifica documenti archivio', 'Pubblica documenti archivio', 'Approva documenti archivio', 'Visualizza scadenze in agenda',
    'Crea scadenza in agenda', 'Modifica scadenza in agenda', 'Pubblica scadenze in agenda', 'Approva scadenze in agenda',
];
const ANAGRAFICHE = ['Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'];

function monta() {
    return mount(DataTableRowActions, {
        props: { anagrafica: { id: 7, nome: 'Mario Rossi' } as never },
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
    return monta().findAll('.voce').map((voce) => voce.text()).join('|');
}

beforeEach(() => {
    chiGuarda.ruoli = ['amministratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', ...ANAGRAFICHE];
});

describe('DataTableRowActions delle anagrafiche — ogni comando chiede il permesso della sua azione (decisioni 137 e 138.1)', () => {
    test('con i quattro permessi delle anagrafiche il menù offre «Modifica» ed «Elimina», come prima', () => {
        const riga = monta();

        expect(voci()).toBe('anagrafiche.actions.edit_resident|anagrafiche.actions.delete_resident');
        expect(riga.find('a').attributes('href')).toBe('/admin.anagrafiche.edit/7');
    });

    test('decisione 138 — il collaboratore di serie vede «Modifica» e non «Elimina» nel menù della riga', () => {
        chiGuarda.ruoli = ['collaboratore'];
        chiGuarda.permessi = [...COLLABORATORE];

        expect(voci()).toBe('anagrafiche.actions.edit_resident');
    });

    test('decisione 138 — il collaboratore di serie, montato come la tabella, non ha «Elimina» fra le voci', () => {
        chiGuarda.ruoli = ['collaboratore'];
        chiGuarda.permessi = [...COLLABORATORE];
        const riga = shallowMount(DataTableRowActions, {
            props: { anagrafica: { id: 7, nome: 'Da eliminare' } as never },
            global: { renderStubDefaultSlot: true, mocks: { route }, stubs: { Link: { template: '<a><slot /></a>' } } },
        });

        expect(riga.text()).toContain('anagrafiche.actions.edit_resident');
        expect(riga.text()).not.toContain('anagrafiche.actions.delete_resident');
    });

    test('con la sola consultazione il menù non offre niente, e non compare', () => {
        chiGuarda.ruoli = ['Consultazione'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];
        const riga = monta();

        expect(riga.findAll('.voce')).toHaveLength(0);
        expect(riga.find('.menu').exists()).toBe(false);
        expect(riga.find('button').exists()).toBe(false);
    });

    test('con «Elimina anagrafiche» senza «Modifica anagrafiche» il menù offre solo «Elimina»', () => {
        chiGuarda.ruoli = ['Archivio'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Elimina anagrafiche'];

        expect(voci()).toBe('anagrafiche.actions.delete_resident');
    });

    test('decisione 138 — «Crea anagrafiche» senza «Modifica anagrafiche» non basta per «Modifica»: nessun comando, e il menù non compare', () => {
        chiGuarda.ruoli = ['Inserimento'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];
        const riga = monta();

        expect(riga.findAll('.voce')).toHaveLength(0);
        expect(riga.find('.menu').exists()).toBe(false);
    });

    test('decisione 138 — con «Crea» ed «Elimina anagrafiche» senza «Modifica anagrafiche» il menù offre solo «Elimina»', () => {
        chiGuarda.ruoli = ['Inserimento'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Elimina anagrafiche'];

        expect(voci()).toBe('anagrafiche.actions.delete_resident');
    });

    test('«Modifica utenti» ed «Elimina utenti» non bastano: sono i permessi degli account, non delle anagrafiche', () => {
        chiGuarda.ruoli = ['Segreteria'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica utenti', 'Elimina utenti'];

        expect(monta().find('.menu').exists()).toBe(false);
    });
});

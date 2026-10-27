// @vitest-environment jsdom

/**
 * «Crea anagrafica» nella barra dell'elenco anagrafiche si mostra con «Crea anagrafiche» (decisioni 137 e 138.1).
 *
 * Il modulo nuovo e il salvataggio chiedono a `AnagraficaPolicy::create` il permesso «Crea anagrafiche». Il pulsante
 * guardava «Crea utenti», che è il permesso degli account: a chi ha il primo senza il secondo mancava, a chi ha il
 * secondo senza il primo portava a un rifiuto. I ruoli di serie hanno tutti e due, e per loro non cambia niente.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        url: '/admin/anagrafiche',
        props: { auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));
vi.mock('@/composables/useCondomini', async () => {
    const { ref } = await import('vue');

    return { useCondomini: () => ({ condomini: ref([]), isLoading: ref(false), loadCondomini: vi.fn() }) };
});
vi.mock('@/composables/useTabellaServer', () => ({ useTabellaServer: () => ({ filtra: vi.fn() }) }));

const route = (nome: string) => `/${nome}`;
(globalThis as Record<string, unknown>).route = route;

const DataTableToolbar = (await import('./DataTableToolbar.vue')).default;

function pulsanteNuova() {
    const barra = mount(DataTableToolbar, {
        props: { table: { getColumn: () => undefined, reset: vi.fn() } as never },
        global: {
            mocks: { route },
            stubs: {
                Input: true,
                DataTableFacetedFilter: true,
                Link: { props: ['href'], template: '<a class="nuova" :href="href"><slot /></a>' },
            },
        },
    });

    return barra.find('a.nuova');
}

beforeEach(() => {
    chiGuarda.ruoli = ['amministratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea utenti', 'Visualizza anagrafiche', 'Crea anagrafiche'];
});

describe('DataTableToolbar delle anagrafiche — «Crea anagrafica» chiede il permesso del modulo nuovo (decisioni 137 e 138.1)', () => {
    test('con «Crea anagrafiche» e «Crea utenti», come i ruoli di serie, il pulsante c\'è e apre il modulo nuovo', () => {
        const pulsante = pulsanteNuova();

        expect(pulsante.exists()).toBe(true);
        expect(pulsante.attributes('href')).toBe('/admin.anagrafiche.create');
        expect(pulsante.text()).toContain('anagrafiche.actions.new_resident');
    });

    test('decisione 138 — con «Crea anagrafiche» senza «Crea utenti» il pulsante c\'è', () => {
        chiGuarda.ruoli = ['Rubrica'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche'];

        expect(pulsanteNuova().exists()).toBe(true);
    });

    test('decisione 138 — con «Crea utenti» senza «Crea anagrafiche» il pulsante non c\'è', () => {
        chiGuarda.ruoli = ['Segreteria'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea utenti'];

        expect(pulsanteNuova().exists()).toBe(false);
    });

    test('decisione 138 — con «Modifica anagrafiche» senza «Crea anagrafiche» il pulsante non c\'è', () => {
        chiGuarda.ruoli = ['Correzioni'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Modifica anagrafiche'];

        expect(pulsanteNuova().exists()).toBe(false);
    });

    test('con la sola consultazione il pulsante non c\'è', () => {
        chiGuarda.ruoli = ['Consultazione'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche'];

        expect(pulsanteNuova().exists()).toBe(false);
    });
});

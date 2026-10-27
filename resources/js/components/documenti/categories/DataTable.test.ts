// @vitest-environment jsdom

/**
 * Le righe dell'elenco delle categorie dei documenti hanno per chiave l'id della categoria, non la posizione.
 *
 * Ordinare o cambiare pagina ricaricano l'elenco tenendo la tabella (`preserveState`): con la posizione per
 * chiave una riga restava la stessa riga con un'altra categoria dentro, e il suo menù «Modifica» ricordava la
 * categoria di prima. Con l'id la riga segue la sua categoria.
 */

import { expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { filters: {}, meta: { per_page: 10 }, sort: null, direction: null, auth: { user: { roles: [], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTable = (await import('./DataTable.vue')).default;

const colonne = [{ accessorKey: 'name', cell: ({ row }: { row: { original: { name: string } } }) => h('span', row.original.name) }];
const verbali = { id: 1, name: 'Verbali' };
const contratti = { id: 2, name: 'Contratti' };

test('dopo un ordinamento la riga di una categoria è la stessa riga, spostata', async () => {
    const tabella = mount(DataTable, {
        props: { columns: colonne as never, data: [verbali, contratti] as never, meta: { current_page: 1, per_page: 10, last_page: 1, total: 2 } },
        global: { stubs: { DataTableToolbar: true, DataTablePagination: true } },
    });

    const rigaDiVerbali = tabella.findAll('tbody tr')[0].element;
    expect(rigaDiVerbali.textContent).toBe('Verbali');

    await tabella.setProps({ data: [contratti, verbali] as never });

    const righe = tabella.findAll('tbody tr');
    expect(righe.map((r) => r.text())).toEqual(['Contratti', 'Verbali']);
    expect(righe[1].element).toBe(rigaDiVerbali);
});

// @vitest-environment jsdom

/**
 * Le righe dell'elenco utenti hanno per chiave l'id dell'utente, non la posizione (decisione 135.1).
 *
 * Ordinare, filtrare, cercare o cambiare pagina ricaricano l'elenco tenendo la tabella (`preserveState`): con
 * la posizione per chiave la prima riga restava la stessa riga con un altro utente dentro, e ogni cella che
 * ricordava qualcosa dell'utente di prima lo mostrava ancora. Con l'id la riga segue il suo utente.
 */

import { expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { filters: {}, meta: { per_page: 10 }, sort: null, direction: null, auth: { user: { roles: [], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTable = (await import('./DataTable.vue')).default;

const colonne = [{ accessorKey: 'name', cell: ({ row }: { row: { original: { name: string } } }) => h('span', row.original.name) }];
const anna = { id: 11, name: 'Anna' };
const bruno = { id: 12, name: 'Bruno' };

test('dopo un ordinamento la riga di un utente è la stessa riga, spostata', async () => {
    const tabella = mount(DataTable, {
        props: { columns: colonne as never, data: [anna, bruno] as never, meta: { current_page: 1, per_page: 10, last_page: 1, total: 2 } },
        global: { stubs: { DataTableToolbar: true, DataTablePagination: true } },
    });

    const rigaDiAnna = tabella.findAll('tbody tr')[0].element;
    expect(rigaDiAnna.textContent).toBe('Anna');

    await tabella.setProps({ data: [bruno, anna] as never });

    const righe = tabella.findAll('tbody tr');
    expect(righe.map((r) => r.text())).toEqual(['Bruno', 'Anna']);
    expect(righe[1].element).toBe(rigaDiAnna);
});

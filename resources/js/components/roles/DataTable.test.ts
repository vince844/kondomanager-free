// @vitest-environment jsdom

/**
 * Le righe dell'elenco dei ruoli hanno per chiave l'id del ruolo, non la posizione (decisione 135.1).
 *
 * Quando l'elenco cambia sotto la tabella (un ruolo eliminato o creato), con la posizione per chiave una riga
 * restava la stessa riga con un altro ruolo dentro, e ogni cella che ricordava qualcosa del ruolo di prima lo
 * mostrava ancora. Con l'id la riga segue il suo ruolo.
 */

import { expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const DataTable = (await import('./DataTable.vue')).default;

const colonne = [{ accessorKey: 'name', cell: ({ row }: { row: { original: { name: string } } }) => h('span', row.original.name) }];
const consigliere = { id: 5, name: 'Consigliere' };
const contabile = { id: 6, name: 'Contabile' };
const segreteria = { id: 7, name: 'Segreteria' };

test('eliminato un ruolo, la riga di ogni altro ruolo è la stessa riga', async () => {
    const tabella = mount(DataTable, {
        props: { columns: colonne as never, data: [consigliere, contabile, segreteria] as never },
        global: { stubs: { DataTableToolbar: true, DataTablePagination: true } },
    });

    const rigaDiSegreteria = tabella.findAll('tbody tr')[2].element;
    expect(rigaDiSegreteria.textContent).toBe('Segreteria');

    // «Contabile» eliminato: l'elenco ricaricato ha un ruolo in meno.
    await tabella.setProps({ data: [consigliere, segreteria] as never });

    const righe = tabella.findAll('tbody tr');
    expect(righe.map((r) => r.text())).toEqual(['Consigliere', 'Segreteria']);
    expect(righe[1].element).toBe(rigaDiSegreteria);
});

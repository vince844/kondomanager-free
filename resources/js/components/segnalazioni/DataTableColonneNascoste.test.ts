// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tabella dell'elenco delle segnalazioni del pannello: non disegna la colonna «Assegnata a» (giro 3).
 *
 * La colonna esiste per il filtro della barra, ma il nome di chi segue la segnalazione si legge sotto il titolo: la
 * tabella parte con quella colonna nascosta (`initialState.columnVisibility`, da `colonneNascoste` di `columns.ts`), e
 * la barra dei filtri la trova lo stesso.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('@/composables/useTabellaServer', () => ({
    useTabellaServer: () => ({ inCorso: ref(false), ordinamento: ref([]), suPaginazione: vi.fn(), suOrdinamento: vi.fn() }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const { columns } = await import('@/components/segnalazioni/columns');
const DataTable = (await import('@/components/segnalazioni/DataTable.vue')).default;

const riga = {
    id: 31, subject: 'Il cancello non chiude', description: '', priority: 'media', stato: 'aperta', is_private: false,
    is_published: true, is_approved: true, is_featured: false, is_locked: false, can_comment: true, created_at: '3 giorni fa',
    creata_il: '08/11/2026 10:00', condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
    anagrafiche: [], assegnata_a: { id: 9, name: 'Elena Studio', attivo: true },
};

/** La barra dei filtri vera chiede alla tabella le sue colonne: qui ne teniamo solo la tabella ricevuta. */
const tabelle: { getColumn: (id: string) => unknown; getAllColumns: () => { id: string; getIsVisible: () => boolean }[] }[] = [];
const Barra = { props: ['table'], setup(props: { table: never }) { tabelle.push(props.table); return () => null; } };

function monta() {
    tabelle.length = 0;

    return mount(DataTable as never, {
        props: { columns, data: [riga], meta: { current_page: 1, per_page: 15, last_page: 1, total: 1 } } as never,
        global: {
            stubs: {
                DataTableToolbar: Barra,
                DataTablePagination: true,
                DataTableColumnHeader: { props: ['title'], template: '<span data-intestazione>{{ title }}</span>' },
                DataTableRowActions: true,
            },
        },
    });
}

describe('la tabella dell\'elenco delle segnalazioni', () => {
    test('non disegna la colonna «Assegnata a»: né l\'intestazione né la cella', () => {
        const w = monta();

        expect(w.findAll('thead th')).toHaveLength(columns.length - 1);
        expect(w.findAll('tbody tr').at(0)!.findAll('td')).toHaveLength(columns.length - 1);
        expect(w.find('thead').text()).not.toContain('segnalazioni.assignment.label');
        expect(w.find('thead').text()).not.toContain('segnalazioni.table.sent');
    });

    test('il nome di chi la segue si legge una volta sola, sotto il titolo', () => {
        const w = monta();

        expect(w.findAll('[data-assegnata-a]')).toHaveLength(1);
        expect(w.find('tbody td [data-sotto-titolo] [data-assegnata-a]').text()).toContain('Elena Studio');
        expect(w.find('tbody td [data-sotto-titolo] [data-inviata]').text()).toBe('3 giorni fa');
    });

    test('la barra dei filtri trova lo stesso la colonna, nascosta', () => {
        monta();
        const tabella = tabelle.at(-1)!;

        expect(tabella.getColumn('assegnata_a')).toBeDefined();
        expect(tabella.getAllColumns().find((c) => c.id === 'assegnata_a')!.getIsVisible()).toBe(false);
        expect(tabella.getAllColumns().filter((c) => !c.getIsVisible()).map((c) => c.id)).toEqual(['assegnata_a']);
    });
});

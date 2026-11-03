// @vitest-environment jsdom

/**
 * La finestra dei permessi dell'elenco dei ruoli segue la riga (decisione 135.1).
 *
 * Il pulsante con il numero e la finestra che apre mostrano il ruolo della riga **adesso**: quando l'elenco
 * cambia sotto la tabella (un ruolo eliminato, uno creato) la stessa cella può ricevere un altro ruolo, e una
 * copia fatta una volta sola mostrerebbe quello di prima, con i suoi permessi e il suo numero di utenti.
 */

import { expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');
const PermissionsDialog = (await import('@/components/PermissionsDialog.vue')).default;

const permessi = (n: number) => Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `Permesso ${i + 1}`, description: null }));
const amministratore = { id: 1, name: 'amministratore', description: 'Il ruolo di chi governa', permissions: permessi(65), users_count: 2 };
const demo = { id: 7, name: 'Amministratore Demo', description: 'Un ruolo su misura', permissions: permessi(12), users_count: 40 };

test('il pulsante e la finestra mostrano il ruolo della riga, anche quando la riga cambia ruolo', async () => {
    const colonna = columns.find((c) => (c as { accessorKey?: string }).accessorKey === 'permissions')!;
    const cella = colonna.cell as (contesto: unknown) => ReturnType<typeof h>;
    const riga = ref<typeof amministratore>(demo);

    const w = mount(defineComponent({
        render: () => h('div', [cella({ getValue: () => riga.value.permissions, row: { original: riga.value, index: 0 } })]),
    }));
    expect(w.find('button').text()).toBe('12');

    riga.value = amministratore;
    await nextTick();

    expect(w.find('button').text()).toBe('65');
    const finestra = w.findComponent(PermissionsDialog);
    expect(finestra.props('entityName')).toBe('amministratore');
    expect(finestra.props('entityDescription')).toBe('Il ruolo di chi governa');
    expect((finestra.props('badges') as { label: string }[]).map((b) => b.label)).toEqual(['2 utenti']);
});

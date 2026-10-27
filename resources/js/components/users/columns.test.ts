// @vitest-environment jsdom

/**
 * Le celle dell'elenco utenti.
 *
 * Lo scudo della verifica dell'email, nella colonna del nome (Coda 258, decisioni 134.1 e 136.3): togliere o
 * rimettere la verifica vale solo per un account nella portata di chi guarda l'elenco, e chiede «Modifica
 * utenti», come la sua rotta. Altrimenti lo scudo resta un'indicazione e il clic non manda la richiesta che il
 * server rifiuterebbe.
 *
 * La finestra dei permessi, nella colonna dei permessi (decisione 135.1): il pulsante con il numero e la
 * finestra che apre mostrano i permessi e il ruolo dell'utente della riga **adesso**. Ordinare, filtrare o
 * cambiare pagina ricaricano l'elenco tenendo la tabella, e la stessa cella riceve l'utente nuovo: una copia
 * fatta una volta sola mostrerebbe quello di prima.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, ref } from 'vue';

const inertia = vi.hoisted(() => ({ put: vi.fn() }));
const chiGuarda = vi.hoisted(() => ({ permessi: [] as string[] }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { put: inertia.put, get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: { roles: ['collaboratore'], get permissions() { return chiGuarda.permessi; } } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');
const PermissionsDialog = (await import('@/components/PermissionsDialog.vue')).default;

/** La cella del nome, montata come la monta la tabella. */
function cellaDelNome(gestibile: boolean) {
    const utente = {
        id: '5', name: 'Anna', email: 'anna@example.com', suspended_at: null, email_verified_at: '2026-10-01',
        roles: [], permissions: [], anagrafica: null, gestibile,
    };
    const cella = columns[0].cell as (contesto: unknown) => ReturnType<typeof h>;

    return mount(defineComponent({
        render: () => cella({ row: { original: utente, index: 0 }, table: { options: { meta: { updateData: vi.fn() } } } }),
    }));
}

beforeEach(() => {
    inertia.put.mockClear();
    chiGuarda.permessi = ['Modifica utenti'];
});

describe('columns degli utenti — lo scudo della verifica segue la portata e il permesso (Coda 258, decisioni 134.1 e 136.3)', () => {
    test('nella portata, con «Modifica utenti», il clic toglie la verifica', async () => {
        const cella = cellaDelNome(true);
        const scudo = cella.find('[title]');

        expect(scudo.classes()).toContain('cursor-pointer');
        await scudo.trigger('click');
        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/utenti.toggle-verification');
    });

    test('fuori portata lo scudo non manda niente', async () => {
        const cella = cellaDelNome(false);
        const scudo = cella.find('[title]');

        expect(scudo.classes()).not.toContain('cursor-pointer');
        await scudo.trigger('click');
        expect(inertia.put).not.toHaveBeenCalled();
    });

    test('senza «Modifica utenti» lo scudo non manda niente, anche nella portata', async () => {
        chiGuarda.permessi = ['Visualizza utenti', 'Sospendi utenti'];
        const cella = cellaDelNome(true);
        const scudo = cella.find('[title]');

        expect(scudo.classes()).not.toContain('cursor-pointer');
        await scudo.trigger('click');
        expect(inertia.put).not.toHaveBeenCalled();
    });
});

describe('columns degli utenti — la finestra dei permessi segue la riga (decisione 135.1)', () => {
    const permessi = (n: number) => Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `Permesso ${i + 1}`, description: null }));
    const amministratore = { id: '1', name: 'Vincenzo Rossi', roles: ['amministratore'], permissions: permessi(65) };
    const demo = { id: '3', name: 'Caporalini', roles: ['Amministratore Demo'], permissions: permessi(12) };

    /** La cella dei permessi di una riga che cambia utente, come dopo un ordinamento, un filtro o una pagina nuova. */
    function cellaDeiPermessi() {
        const colonna = columns.find((c) => (c as { accessorKey?: string }).accessorKey === 'permissions')!;
        const cella = colonna.cell as (contesto: unknown) => ReturnType<typeof h>;
        const riga = ref<typeof amministratore>(demo);

        const w = mount(defineComponent({
            render: () => h('div', [cella({ getValue: () => riga.value.permissions, row: { original: riga.value, index: 0 } })]),
        }));

        return { w, riga };
    }

    test('il pulsante dice i permessi dell\'utente della riga, anche quando la riga cambia utente', async () => {
        const { w, riga } = cellaDeiPermessi();
        expect(w.find('button').text()).toBe('12');

        riga.value = amministratore;
        await nextTick();
        expect(w.find('button').text()).toBe('65');
    });

    test('la finestra riceve il nome, i permessi e il ruolo dell\'utente della riga adesso', async () => {
        const { w, riga } = cellaDeiPermessi();

        riga.value = amministratore;
        await nextTick();

        const finestra = w.findComponent(PermissionsDialog);
        expect(finestra.props('user')).toBe('Vincenzo Rossi');
        expect(finestra.props('permissions')).toHaveLength(65);
        expect((finestra.props('badges') as { label: string }[]).map((b) => b.label)).toEqual(['amministratore']);
    });
});

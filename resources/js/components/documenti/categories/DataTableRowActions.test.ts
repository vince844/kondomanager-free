// @vitest-environment jsdom

/**
 * Il modulo «Modifica» di una categoria dei documenti prende i dati della categoria della riga quando si apre.
 *
 * L'elenco delle categorie ricarica tenendo la tabella (`preserveState`): dopo un ordinamento, un filtro o una
 * pagina nuova una riga può ricevere un'altra categoria. Un modulo riempito una volta sola, quando la riga nasce,
 * si apriva con il nome e la descrizione della categoria di prima e «Salva» li scriveva sull'altra. Lo stesso
 * dopo un salvataggio: il modulo tornava ai dati di quando la riga era nata.
 */

import { beforeEach, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave, transChoice: (chiave: string) => chiave }));
(globalThis as Record<string, unknown>).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}/${parametri?.id ?? ''}`;

const DataTableRowActions = (await import('./DataTableRowActions.vue')).default;

type Stato = Record<string, any>;

const verbali = { id: 1, name: 'Verbali', description: 'Verbali di assemblea', documenti_count: 0, documenti: [] };
const contratti = { id: 2, name: 'Contratti', description: 'Contratti dei fornitori', documenti_count: 0, documenti: [] };

let mandate: Array<{ url: unknown; dati: unknown }> = [];

beforeEach(() => {
    vi.useFakeTimers();
    mandate = [];
    vi.spyOn(router, 'visit').mockImplementation(((url: unknown, opzioni: { data?: unknown; onSuccess?: () => void }) => {
        mandate.push({ url, dati: JSON.parse(JSON.stringify(opzioni?.data ?? null)) });
        opzioni?.onSuccess?.();
    }) as never);
});

function monta(categoria: object) {
    return shallowMount(DataTableRowActions, {
        props: { categoria: categoria as never },
        global: { renderStubDefaultSlot: true },
    });
}

function stato(riga: ReturnType<typeof monta>): Stato {
    return (riga.vm as unknown as { $: { setupState: Stato } }).$.setupState;
}

/** «Modifica» dal menù della riga, come lo chiama il modello: con la categoria della riga adesso. */
function apriModifica(riga: ReturnType<typeof monta>) {
    const s = stato(riga);
    s.handleEdit((riga.props() as { categoria: object }).categoria);
    vi.advanceTimersByTime(250);
    return s;
}

test('la riga che riceve un\'altra categoria apre «Modifica» con i dati di quella, e «Salva» li scrive su di lei', async () => {
    const riga = monta(verbali);

    // L'elenco ricaricato dopo un ordinamento: la stessa riga ora mostra «Contratti».
    await riga.setProps({ categoria: contratti as never });

    const s = apriModifica(riga);
    expect({ nome: s.form.name, descrizione: s.form.description }).toEqual({ nome: 'Contratti', descrizione: 'Contratti dei fornitori' });

    s.editCategory();
    expect(mandate.at(-1)).toEqual({ url: '/admin.categorie.update/2', dati: { name: 'Contratti', description: 'Contratti dei fornitori' } });
});

test('dopo un salvataggio, riaperto, il modulo mostra la categoria com\'è adesso', async () => {
    const riga = monta(contratti);

    let s = apriModifica(riga);
    s.form.name = 'Contratti di manutenzione';
    s.editCategory();

    // Il server ricarica l'elenco con la categoria salvata.
    await riga.setProps({ categoria: { ...contratti, name: 'Contratti di manutenzione' } as never });

    s = apriModifica(riga);
    expect(s.form.name).toBe('Contratti di manutenzione');
    expect(s.form.description).toBe('Contratti dei fornitori');
});

test('un errore sotto un campo non resta al modulo della categoria aperta dopo', async () => {
    const riga = monta(verbali);

    let s = apriModifica(riga);
    s.form.setError('name', 'Il nome è già usato.');

    await riga.setProps({ categoria: contratti as never });
    s = apriModifica(riga);

    expect(s.form.errors.name).toBeUndefined();
});

// @vitest-environment jsdom

/**
 * La pagina di modifica di una segnalazione dal portale: che cosa manda.
 *
 * - Aperta e mandata senza toccare niente, il modulo manda i dati ricevuti, privata o no.
 * - Togliendo «privata», il corpo porta `is_private` falso: il server stacca allora il collegamento
 *   dell'autore, perché la segnalazione sia del condominio come la guida «Chi la vede» dice (Coda 304,
 *   `tests/Feature/Portale/AutoreTogliePrivataTest.php`).
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 2, roles: ['utente'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/user/${p}`, generateRoute: (n: string) => `user.${n}`, hasRole: () => false, hasPermission: () => false,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniEdit = (await import('./SegnalazioniEdit.vue')).default;

function segnalazione(campi: Record<string, unknown> = {}) {
    return {
        id: 9, subject: "Muffa nella camera «grande» dell'ingresso", description: "Prima riga\nseconda riga — con l'apostrofo",
        priority: 'alta', stato: 'in lavorazione', is_private: true, is_published: true, is_approved: false,
        condominio: { option: { value: 3, label: "Condominio L'Aurora" }, full: { nome: "Condominio L'Aurora" } },
        created_by: { user: { name: "Mario D'Angelo" } },
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniEdit, {
        props: {
            segnalazione: segnalazione(campi) as never,
            condomini: [{ value: 3, label: "Condominio L'Aurora" }, { value: 4, label: 'Condominio Borea' }] as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

type Vm = { $: { setupState: { form: Record<string, unknown>; submit: () => void } } };
const stato = (pagina: ReturnType<typeof monta>) => (pagina.vm as unknown as Vm).$.setupState;

function corpoInviato(): { url: string; dati: Record<string, unknown> } {
    const chiamata = vi.mocked(router.put).mock.calls.at(-1);
    expect(chiamata, 'il modulo non ha chiamato router.put').toBeDefined();

    return { url: String(chiamata![0]), dati: chiamata![1] as Record<string, unknown> };
}

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('SegnalazioniEdit del portale — che cosa manda', () => {
    test.each([
        ['privata', true],
        ['non privata', false],
    ])('aperta e mandata senza toccare niente (%s), il modulo manda i dati ricevuti', (_caso, privata) => {
        stato(monta({ is_private: privata })).submit();

        const { url, dati } = corpoInviato();
        expect(url).toBe('/user.segnalazioni.update');
        expect(dati).toEqual({
            subject: "Muffa nella camera «grande» dell'ingresso",
            description: "Prima riga\nseconda riga — con l'apostrofo",
            priority: 'alta',
            stato: 'in lavorazione',
            condominio_id: 3,
            is_private: privata,
        });
    });

    test('togliendo «privata», il corpo porta `is_private` falso e il resto com\'era', () => {
        const pagina = monta({ is_private: 1 });
        stato(pagina).form.is_private = false;

        stato(pagina).submit();

        expect(corpoInviato().dati).toMatchObject({ is_private: false, subject: "Muffa nella camera «grande» dell'ingresso", condominio_id: 3 });
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Avvisa i condòmini» dal seguito di una delibera: la pagina della nuova comunicazione si apre già scritta con la frase pubblica del punto, come bozza da
 * rileggere, lo dice in testa, e **rimanda la cosa da fare** nel salvataggio (`cosa_da_fare_id`), come fa con l'assemblea dell'avviso: pubblicata, la cosa è
 * fatta.
 *
 * Che cosa non copre: il testo proposto e la cosa fatta alla pubblicazione (li provano `AvvisoPubblicatoTest` e le catene delle pagine del seguito).
 */
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const forma = vi.hoisted(() => ({ dati: null as Record<string, unknown> | null }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
    useForm: (dati: Record<string, unknown>) => { forma.dati = dati; return { ...dati, errors: {}, clearErrors: () => {}, post: vi.fn(), processing: false }; },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;

const monta = () => shallowMount(ComunicazioniNew, {
    props: {
        condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true,
        precompilata: { subject: 'Assemblea del 15/12/2026: Orari del riscaldamento 2026/2027', description: 'L\'assemblea ordinaria del 15/12/2026 ha approvato…',
            condomini_ids: [3], cosa_da_fare_id: 31, priority: 'bassa' },
    } as never,
    global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
});

describe('ComunicazioniNew — «Avvisa i condòmini» dal seguito di una delibera', () => {
    test('il modulo porta la cosa da fare, e nasce come bozza da rileggere', () => {
        monta();
        expect(forma.dati).toMatchObject({ cosa_da_fare_id: 31, is_published: false, subject: 'Assemblea del 15/12/2026: Orari del riscaldamento 2026/2027' });
    });

    test('la priorità è già proposta: il salvataggio non si ferma su «Il campo priorità è richiesto»', () => {
        monta();
        expect(forma.dati).toMatchObject({ priority: 'bassa' });
    });

    test('in testa dice da dove viene, e che pubblicata la cosa da fare è fatta', () => {
        const w = monta();
        expect(w.find('[data-testid="dalla-delibera"]').text()).toContain('Preparata dal seguito della delibera: la rileggi e la pubblichi tu. Pubblicata, la cosa da fare è fatta.');
    });
});

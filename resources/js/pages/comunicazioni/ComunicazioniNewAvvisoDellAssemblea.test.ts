// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Avviso anche in bacheca» dalla convocazione di un'assemblea (Coda 679): la pagina della nuova comunicazione parte dal testo già scritto e, con un punto
 * senza la frase per la bacheca, lo dice in testa: in bacheca va solo la materia del punto, mai la frase dell'avviso, che può avere i nomi delle persone.
 *
 * Che cosa non copre: il testo proposto (lo scrive il server, `BachecaSenzaDatiPersonaliTest`). Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniNew = (await import('./ComunicazioniNew.vue')).default;

const monta = (mancaLaFrase: string | null) => shallowMount(ComunicazioniNew, {
    props: {
        condomini: [], anagrafiche: [], allegati_massimo: 6, commenti_moderati_di_serie: true,
        precompilata: { subject: 'Assemblea ordinaria del 15/12/2026', description: 'È convocata…', condomini_ids: [3], assemblea_id: 9, manca_la_frase: mancaLaFrase },
    } as never,
    global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
});

describe('ComunicazioniNew — l\'avviso dell\'assemblea in bacheca', () => {
    test('con un punto senza la frase per la bacheca lo dice in testa, e dove si scrive', () => {
        const nota = monta('Al punto 6 manca la frase per la bacheca: il programma scrive solo «Una questione legale del condominio».').find('[data-testid="manca-la-frase"]');
        expect(nota.exists()).toBe(true);
        expect(nota.text()).toContain('Al punto 6 manca la frase per la bacheca');
        expect(nota.text()).toContain('La frase si scrive nel punto, nella scheda dell\'assemblea.');
    });

    test('con tutte le frasi non dice niente', () => {
        expect(monta(null).find('[data-testid="manca-la-frase"]').exists()).toBe(false);
    });
});

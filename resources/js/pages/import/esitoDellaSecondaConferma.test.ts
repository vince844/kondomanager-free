// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'esito dell'importazione legge i messaggi del server (1.12.0-beta.14, tappa 5).
 *
 * La seconda conferma di un'importazione già entrata (il doppio clic, «Indietro» del browser e di nuovo «Importa») riporta all'esito con
 * «Questa importazione è già entrata: la trovi qui.» (`ImportController::confermaUnaVolta()`). La pagina non leggeva i messaggi: si vedeva
 * l'esito completato, senza la frase che dice perché si è lì. Le props sono come le manda `ImportController::esito()`; i nomi sono inventati.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: { message: { type: 'info', message: 'Questa importazione è già entrata: la trovi qui.' } } } }),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Esito = (await import('./ImportEsito.vue')).default;

const STUBS = { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div />' }, ImportGuide: { template: '<div />' } };
const MOCKS = { route: (nome: string) => `/${nome}` };

let pagina: VueWrapper | null = null;
afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('l\'esito dopo la seconda conferma', () => {
    test('dice «Questa importazione è già entrata: la trovi qui.»', async () => {
        const livello = (chiave: string, etichetta: string) => ({
            livello: chiave, etichetta, creati: 2, uniti: 0, saltati: 0, riuscito: true, gia_a_posto: false, saltato: false, prerequisiti_mancanti: [], rilievi: [], avvisi: [],
        });
        pagina = mount(Esito, {
            props: {
                lotto: { uuid: 'u-1', stato: 'completato', condominio: 'Condominio Esempio', condominio_id: 1, url_condominio: '/c/1', url_controlli: null, url_rapporto: null, url_piano_conti: null, riferimento: 'X', completato_at: '2026-11-23' },
                rapporto: { livelli: [livello('condominio', 'Condominio'), livello('unita', 'Unità')], saldi: null },
                creati: 4,
                annullamento: { possibile: false, motivo: null, aiuto: null, impedimenti: {}, conteggi: {}, condominio: null },
            } as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });
        // L'avviso compare al montaggio (`Alert.vue`, `onMounted`): un giro di rendering.
        await pagina.vm.$nextTick();

        expect(pagina.text()).toContain('Questa importazione è già entrata: la trovi qui.');
    });
});

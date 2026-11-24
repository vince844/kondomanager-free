// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 707 (1): nelle rate coperte del dettaglio dell'incasso la colonna «Unità» scrive l'etichetta dell'unità come la manda il server
 * (`IncassoRateService`, `Immobile::getEtichettaAttribute`), che con l'interno dice già «Int. …». La pagina ci metteva davanti un altro «Int.»: «Int. Int. 1B»
 * per un'unità con l'interno, «Int. Immobile 1A» per una senza.
 *
 * Che cosa NON copre: l'etichetta, che è del server; il resto della tabella (`IncassoRateShow.test.ts`).
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateShow from './IncassoRateShow.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    RicevutaDialog: true,
    ConfirmDialog: true,
};

function apri(unita: Array<string | null>) {
    return mount(IncassoRateShow, {
        props: {
            condominio: { data: { id: 16, nome: 'Via roma' } },
            esercizio: { id: 1 },
            condomini: [],
            incasso: { id: 7, numero_protocollo: 'INC-2026-00031', stato: 'registrata', causale: null, data_competenza: '2026-11-18T00:00:00.000000Z', updated_at: '2026-11-18T22:00:00Z' },
            incassoFormatted: {
                data_competenza: '2026-11-18',
                dettagli_rate: unita.map((immobile, i) => ({ numero: i + 1, scadenza: '31/01/2026', immobile, importo_formatted: '€ 10,00', tipo: 'contanti', credito_di: null })),
                pagante: { principale: 'Cecilia Angeli', altri_count: 0, lista_completa: 'Cecilia Angeli', ruolo: 'Proprietario', versato_da: null },
                cassa_nome: 'Banca Intesa', cassa_tipo_label: 'Conto corrente', importo_totale_formatted: '€ 10,00', importo_totale_cents: 1000, gestione_nome: 'Ordinaria',
            },
            utenteCreatore: 'Vincenzo',
            utenteStornatore: null,
            imputazione: { codice: 'ordine_scadenze', testo: 'Secondo l\'ordine delle scadenze', nota: null },
            storno: null,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

const testo = (el: { text: () => string }) => el.text().replace(/[\s ]+/g, ' ').trim();
/** La colonna «Unità» di ogni rata coperta, nell'ordine della tabella. */
const colonnaUnita = (w: ReturnType<typeof apri>) => w.findAll('tbody tr').map((r) => testo(r.findAll('td')[1]));

describe('la colonna «Unità» delle rate coperte', () => {
    test('un\'unità con l\'interno: «Int. 1B», una volta sola', () => {
        expect(colonnaUnita(apri(['Int. 1B']))).toEqual(['Int. 1B']);
    });

    test('un\'unità senza interno: il suo nome, senza «Int.» davanti', () => {
        expect(colonnaUnita(apri(['Immobile 1A']))).toEqual(['Immobile 1A']);
    });

    test('senza l\'unità: «N/D»', () => {
        expect(colonnaUnita(apri([null]))).toEqual(['N/D']);
    });
});

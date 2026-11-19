// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 539 (4): il dettaglio dell'incasso dice a chi è intestata la ricevuta del versamento di un erede quando la scelta non è quella di serie
 * (l'erede che ha pagato): «Ricevuta intestata a — Eredi di Massimo Bagnara». Di serie niente: «Ha pagato» dice già chi è.
 *
 * Che cosa NON copre: la scelta, che compone il server (`RicevutaDellEredeTest.php`); il disegno a 390 px e il tema scuro (a video).
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

function apri(props: Record<string, unknown> = {}) {
    return mount(IncassoRateShow, {
        props: {
            condominio: { data: { id: 16, nome: 'Via roma' } },
            esercizio: { id: 1 },
            condomini: [],
            incasso: { id: 7, numero_protocollo: 'INC-2026-00031', stato: 'registrata', causale: 'Bonifico di Cecilia', data_competenza: '2026-11-18T00:00:00.000000Z', updated_at: '2026-11-18T22:00:00Z' },
            incassoFormatted: {
                data_competenza: '2026-11-18',
                dettagli_rate: [{ numero: 1, scadenza: '31/01/2026', immobile: '1B', importo_formatted: '€ 32,79', tipo: 'contanti', credito_di: null }],
                pagante: { principale: 'Massimo Bagnara', altri_count: 0, lista_completa: 'Massimo Bagnara', ruolo: 'Proprietario', versato_da: 'Cecilia Angeli' },
                cassa_nome: 'Banca Intesa', cassa_tipo_label: 'Conto corrente', importo_totale_formatted: '€ 32,79', importo_totale_cents: 3279, gestione_nome: 'Ordinaria',
            },
            utenteCreatore: 'Vincenzo',
            utenteStornatore: null,
            imputazione: { codice: 'ordine_scadenze', testo: 'Secondo l\'ordine delle scadenze', nota: null },
            storno: null,
            ha_pagato: { nome: 'Cecilia Angeli', sotto: 'erede, sul debito di Massimo Bagnara', versato_da: null },
            spaccato: { sul_debito_ereditato: 3279, sulle_sue_rate: 0 },
            ...props,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

const testo = (el: { text: () => string }) => el.text().replace(/[\s ]+/g, ' ').trim();

describe('«Ricevuta intestata a» nel dettaglio dell\'incasso', () => {
    test('con «Eredi di Massimo Bagnara» la riga c\'è, con le altre voci del versamento', () => {
        const w = apri({ ricevuta_intestata_a: 'Eredi di Massimo Bagnara' });
        const riga = w.find('[data-testid="ricevuta-intestata-a"]');

        expect(riga.exists()).toBe(true);
        expect(testo(riga.find('dt'))).toBe('Ricevuta intestata a');
        expect(testo(riga.find('dd'))).toBe('Eredi di Massimo Bagnara');
    });

    test('di serie (all\'erede che ha pagato) la riga non c\'è', () => {
        expect(apri({ ricevuta_intestata_a: null }).find('[data-testid="ricevuta-intestata-a"]').exists()).toBe(false);
        expect(apri().find('[data-testid="ricevuta-intestata-a"]').exists()).toBe(false);
    });
});

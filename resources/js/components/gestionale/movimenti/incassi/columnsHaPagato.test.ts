// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 539 (5), come il dettaglio della decisione 538: nell'elenco degli incassi la riga di un versamento dell'erede sul debito ereditato dice chi ha
 * pagato davvero, «Cecilia Angeli» con sotto «erede, sul debito di Massimo Bagnara», e il nome porta all'estratto conto di Cecilia. Prima la riga diceva la
 * posizione del giornale, «Massimo Bagnara — Versato da Cecilia Angeli».
 *
 * Si monta la cella «Soggetto» di `createColumns`, come la monta la tabella.
 *
 * Che cosa NON copre: la lettura del server (`RicevutaDellEredeTest.php`, anche dopo lo storno e il filtro per nome); la tabella intera; il tema scuro.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const { createColumns } = await import('./columns');

const base = {
    id: 90, numero_protocollo: 'INC-2026-0090', data_competenza: '2026-11-25', data_registrazione: '2026-11-25', causale: 'Bonifico', gestione_nome: 'Ordinaria 2026',
    cassa_nome: 'Banca', stato: 'registrata', importo_totale_raw: 32.79, importo_totale_cents: 3279, importo_totale_formatted: '€ 32,79', cassa_tipo_label: 'Conto Corrente',
    dettagli_rate: [], anagrafica_id_principale: 7,
    pagante: { principale: 'Massimo Bagnara', altri_count: 0, lista_completa: 'Massimo Bagnara', ruolo: 'Proprietario', versato_da: 'Cecilia Angeli' },
};

function cella(riga: Record<string, unknown>) {
    const colonna = createColumns(16).find((c) => (c as { accessorKey?: string }).accessorKey === 'pagante')!;
    const render = colonna.cell as (contesto: unknown) => ReturnType<typeof h>;

    return mount(defineComponent({ render: () => render({ row: { original: riga, getValue: (k: string) => riga[k] } }) }));
}

/** Il testo della cella pezzo per pezzo (gli elementi senza figli), separato da spazi: fra due elementi la cella non ne mette. */
const testo = (w: ReturnType<typeof cella>) => Array.from(w.element.querySelectorAll('*')).filter((n) => n.children.length === 0)
    .map((n) => (n.textContent ?? '').replace(/\s+/g, ' ').trim()).filter(Boolean).join(' ');

describe('la colonna «Soggetto» dell\'elenco degli incassi', () => {
    test('il versamento dell\'erede: chi ha pagato, con sotto di chi era il debito, e il nome porta al suo estratto conto', () => {
        const w = cella({ ...base, ha_pagato: { nome: 'Cecilia Angeli', sotto: 'erede, sul debito di Massimo Bagnara', versato_da: null, anagrafica_id: 21 } });

        expect(testo(w)).toBe('Cecilia Angeli erede, sul debito di Massimo Bagnara');
        expect(w.find('a').attributes('href')).toBe('/admin/gestionale/16/anagrafiche/21/estratto-conto');
        expect(w.text()).not.toContain('Versato da');
    });

    test('controllo: senza `ha_pagato` la posizione come prima, con il ruolo e «Versato da»', () => {
        const w = cella({ ...base, ha_pagato: null });

        expect(testo(w)).toBe('Massimo Bagnara Proprietario Versato da Cecilia Angeli');
        expect(w.find('a').attributes('href')).toBe('/admin/gestionale/16/anagrafiche/7/estratto-conto');
    });
});

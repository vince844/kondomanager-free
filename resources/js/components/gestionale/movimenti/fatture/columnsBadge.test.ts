// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I badge dell'elenco delle fatture. Nella colonna del fornitore «Nota credito» (e «Pregresso») stanno accanto al numero, come «Ritenuta», non su una riga
 * loro; nella colonna dell'importo «Accredito» è un badge come «Bonifico: …», e una nota di credito si scrive «−€ 183,00», come nel resto del programma.
 * I badge toccati: almeno 12 pixel e gli angoli `rounded-md`.
 *
 * Che cosa non copre: il riepilogo che si apre passando sull'importo, e le altre colonne.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()), router: { visit: vi.fn(), get: vi.fn() } }));
vi.mock('./DataTableRowActions.vue', () => ({ default: { template: '<div />' } }));

const { createColumns } = await import('./columns');
const colonne = createColumns(7);
const cella = (chiave: string, fattura: Record<string, unknown>) => {
    const c = colonne.find((x) => (x as { accessorKey?: string }).accessorKey === chiave)! as { cell: (a: unknown) => unknown };
    return mount({ render: () => h('div', [c.cell({ row: { original: fattura } }) as never]) });
};
const nota = { id: 1, tipo_documento: 'nota_credito', is_pregresso: false, numero_documento: 'NC-2026-1', fornitore: { ragione_sociale: 'Impresa Manutenzioni Demo s.r.l.' },
    importo_ritenuta: 0, documenti: [], importo_imponibile: -15000, importo_iva: -3300, totale_documento: -18300, netto_a_pagare: -18300, stato_pagamento: 'pagata' };

describe('i badge dell\'elenco delle fatture', () => {
    test('«Nota credito» sta accanto al numero, come «Ritenuta»', () => {
        const w = cella('fornitore', nota);
        const riga = w.findAll('div').find((d) => d.text().startsWith('n. NC-2026-1'))!;
        const badge = riga.findAll('span').find((s) => s.text().trim() === 'Nota credito')!;
        expect(badge).toBeTruthy();
        expect(badge.classes()).toEqual(expect.arrayContaining(['rounded-md', 'text-xs', 'px-1', 'py-px', 'font-medium']));
        // Piccoli e senza icona, come «Ritenuta» (prova a video, 19/11).
        expect(badge.find('svg').exists()).toBe(false);
    });

    test('«Accredito» è un badge come «Bonifico», e l\'importo si scrive «−€ 183,00»', () => {
        const w = cella('totale_documento', nota);
        expect(w.text()).toContain('−€ 183,00');
        const accredito = w.findAll('span').find((s) => s.text().trim() === 'Accredito')!;
        expect(accredito.classes()).toEqual(expect.arrayContaining(['rounded-md', 'text-xs', 'bg-emerald-50', 'border']));
    });
});

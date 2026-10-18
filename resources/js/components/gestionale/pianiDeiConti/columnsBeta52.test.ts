// @vitest-environment jsdom

/**
 * Cosa resta scoperto: l'ordinamento e i filtri della tabella (qui si legge la cella, non la tabella montata); il colore e la larghezza a 375 px (si
 * guardano a video); il menu delle azioni di riga, che ha i suoi test.
 *
 * La colonna «Budget & Composizione» nell'elenco dei piani dei conti, con le voci di entrata.
 *
 * Nel piano dei conti «Preventivo» resta sulle sole spese (decisione 87.3): la cifra grande della colonna è la somma delle voci di spesa
 * (`importo_totale`), e con almeno un'entrata la colonna aggiunge una riga con il totale delle entrate (`importo_entrate`) e dice nella didascalia
 * quante voci sono di entrata (`capitoli_entrate_count`). Senza entrate la didascalia è «N voci di spesa», com'era. Il netto «da ripartire» si legge nel
 * piano rate, non qui.
 *
 * La cella è quella vera, `createColumns()`, disegnata con Vue in un contenitore finto.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const { createColumns } = await import('@/components/gestionale/pianiDeiConti/columns');

const colonne = createColumns({ id: 1, nome: 'Condominio Demo' } as never, { id: 1, nome: '2026' } as never);

function testoDi(nodo: unknown): string {
    const w = mount({ render: () => nodo as VNode });

    return w.text().replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
}

const cella = (piano: Record<string, unknown>) => {
    const colonna = colonne.find((c) => (c as { id?: string }).id === 'totale');
    expect(colonna, 'l\'elenco non ha la colonna del totale').toBeDefined();

    return testoDi((colonna!.cell as (ctx: unknown) => unknown)({ row: { original: { id: 1, nome: 'Piano dei conti', ...piano } } }));
};

describe('la colonna «Budget & Composizione»', () => {
    test('con un\'entrata: € 1.200,00 di spese, «2 voci (1 di entrata)» e una riga con le entrate € 240,00', () => {
        const t = cella({ importo_totale: 120000, importo_entrate: 24000, capitoli_count: 2, capitoli_entrate_count: 1 });

        expect(t).toContain('€ 1.200,00');
        expect(t).toContain('2 voci (1 di entrata)');
        expect(t).toContain('Entrate € 240,00');
    });

    test('controprova: senza entrate resta com\'è, «2 voci di spesa», senza la riga delle entrate', () => {
        const t = cella({ importo_totale: 120000, importo_entrate: 0, capitoli_count: 2, capitoli_entrate_count: 0 });

        expect(t).toContain('€ 1.200,00');
        expect(t).toContain('2 voci di spesa');
        expect(t).not.toContain('Entrate');
    });

    test('con la sola entrata le spese sono zero e le entrate € 240,00', () => {
        const t = cella({ importo_totale: 0, importo_entrate: 24000, capitoli_count: 1, capitoli_entrate_count: 1 });

        expect(t).toContain('€ 0,00');
        expect(t).toContain('(1 di entrata)');
        expect(t).toContain('Entrate € 240,00');
    });
});

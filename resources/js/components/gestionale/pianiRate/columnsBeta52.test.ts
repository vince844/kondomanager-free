// @vitest-environment jsdom

/**
 * Cosa resta scoperto: l'ordinamento e i filtri della tabella (qui si leggono l'intestazione e la cella, non la tabella montata); il colore e la
 * larghezza a 375 px (si guardano a video); il menu delle azioni di riga, che ha i suoi test.
 *
 * La colonna del totale nell'elenco dei piani rate, con le voci di entrata.
 *
 * L'elenco leggeva `totale_capitoli`, la somma delle voci senza il segno, e la chiamava «Preventivo». Con un'entrata il numero era più alto di
 * ciò che il piano chiede: la colonna ora dice «Da ripartire» (le spese meno le entrate), con una seconda riga che mostra da dove viene la
 * cifra quando ci sono entrate. L'intestazione di una colonna non cambia riga per riga: per un piano di sole spese «Da ripartire» è lo stesso
 * numero di prima. La cella legge `totale_da_ripartire`, `totale_spese` e `totale_entrate` dal server: un punto che resta sulla chiave
 * vecchia mostrerebbe «€ 0,00» senza che nessun altro test se ne accorga, per questo si legge la cella vera.
 *
 * Le frasi che dicono come si ottiene il netto («da ripartire … (spese …, entrate …)») si firmano con la clausola del motore, letta alla lettera:
 * `in_array($conto->tipo, ['spesa', 'uscita']) ? abs($importo) : -abs($importo)` (`CalcoloQuoteService::processaConti`, il ramo con l'importo del piano e quello con l'importo della voce): le voci di
 * un tipo diverso da spesa e uscita si sottraggono.
 *
 * Il componente è il file vero, `createColumns()`: le celle si disegnano con Vue in un contenitore finto.
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

const { createColumns } = await import('@/components/gestionale/pianiRate/columns');

const colonne = createColumns({ id: 1, nome: 'Condominio Demo' } as never, { id: 1, nome: '2026' } as never);
const colonnaFinta = { getCanSort: () => false, getIsSorted: () => false, toggleSorting: () => undefined };

/** Il testo di un nodo di Vue, con gli spazi normalizzati (lo spazio dopo «€» è non divisibile). */
function testoDi(nodo: unknown): string {
    const w = mount({ render: () => nodo as VNode });
    return w.text().replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
}

const intestazioni = () => colonne.map((c) => (typeof c.header === 'function' ? testoDi((c.header as (ctx: unknown) => unknown)({ column: colonnaFinta })) : ''));
const cellaDelTotale = (piano: Record<string, unknown>) => {
    // la colonna del totale, che si chiami con il nome nuovo o con quello vecchio: le sue cifre si leggono in ogni caso
    const indice = intestazioni().findIndex((t) => t === 'Da ripartire' || t === 'Preventivo');
    expect(indice, 'l\'elenco non ha la colonna del totale').toBeGreaterThanOrEqual(0);

    return testoDi((colonne[indice].cell as (ctx: unknown) => unknown)({ row: { original: piano } }));
};

const piano = (totali: Record<string, number>) => ({ id: 1, nome: 'Preventivo 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, data_inizio: '2026-01-05', ...totali });

describe('la colonna del totale dell\'elenco dei piani rate', () => {
    test('si chiama «Da ripartire», e «Preventivo» non c\'è più', () => {
        expect(intestazioni()).toContain('Da ripartire');
        expect(intestazioni()).not.toContain('Preventivo');
    });

    test('con spese € 1.200,00 ed entrate € 240,00 la cella dice € 960,00 e, sotto, da dove viene', () => {
        const t = cellaDelTotale(piano({ totale_spese: 120000, totale_entrate: 24000, totale_da_ripartire: 96000 }));

        expect(t).toContain('€ 960,00');
        expect(t).toContain('spese € 1.200,00, entrate € 240,00');
        expect(t).not.toContain('€ 0,00');
    });

    test('un piano di sole spese mostra la sola cifra, la stessa di prima, senza la seconda riga', () => {
        const t = cellaDelTotale(piano({ totale_spese: 120000, totale_entrate: 0, totale_da_ripartire: 120000 }));

        expect(t).toContain('€ 1.200,00');
        expect(t).not.toContain('entrate');
        expect(t).not.toContain('spese €');
    });

    test('con la sola entrata il netto è negativo e si legge col meno: − € 240,00', () => {
        const t = cellaDelTotale(piano({ totale_spese: 0, totale_entrate: 24000, totale_da_ripartire: -24000 }));

        expect(t).toContain('€ -240,00');
        expect(t).toContain('spese € 0,00, entrate € 240,00');
    });

    test('un piano straordinario da fatture (nessuna entrata) dice la somma collegata', () => {
        const t = cellaDelTotale({ ...piano({ totale_spese: 100000, totale_entrate: 0, totale_da_ripartire: 100000 }), tipo: 'straordinario' });

        expect(t).toContain('€ 1.000,00');
    });
});

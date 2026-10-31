// @vitest-environment jsdom

/**
 * Lo scadenzario F24 con lo storico dei vecchi esercizi (Coda 314, decisione 154.3).
 *
 * L'elenco mostra anche le deleghe versate, stornate e annullate degli esercizi passati, ognuna con il nome del suo esercizio;
 * il totale «Versato» è solo quello delle deleghe versate dell'esercizio corrente. La carta lo dice, così non lo si confronta
 * con la somma delle righe che si vedono.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    Link: { render: () => null },
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        url: '/admin/gestionale/1/f24',
        props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const F24List = (await import('./F24List.vue')).default;

function montaElenco(esercizio: { id: number; nome: string } | null) {
    return shallowMount(F24List, {
        props: {
            condominio: { id: 1, nome: 'Condominio Test' } as any,
            condomini: [],
            esercizio,
            deleghe: { data: [], meta: { current_page: 1, per_page: 15, last_page: 1, total: 0 } },
            riepilogo: { da_versare_cents: 0, versato_cents: 20000, scadute: 0, prossima_scadenza: null },
            soglie: { appalti_cents: 50000, professionisti_cents: 10000 },
            da_calcolare: { numero: 0, totale_cents: 0 },
            fornitori_da_classificare: [],
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (nome: string) => `/${nome}` } },
    });
}

describe('F24List — il «Versato»', () => {
    test('Coda 314 — la carta del «Versato» dice di quale esercizio è il totale', () => {
        const paragrafi = montaElenco({ id: 9, nome: 'Esercizio 2027' }).findAll('p').map((p) => p.text().replace(/\s+/g, ' '));

        // Il totale, € 200,00, e subito sotto di che esercizio è.
        const posizione = paragrafi.indexOf('€ 200,00');
        expect(posizione, 'il totale del «Versato» non è stato trovato').toBeGreaterThan(-1);
        expect(paragrafi[posizione + 1]).toBe('dell\'esercizio «Esercizio 2027»');
    });

    test('Coda 314 — senza un esercizio la carta non dice di che esercizio è', () => {
        const paragrafi = montaElenco(null).findAll('p').map((p) => p.text().replace(/\s+/g, ' '));

        expect(paragrafi).toContain('€ 200,00');
        expect(paragrafi.some((t) => t.startsWith('dell\'esercizio «'))).toBe(false);
    });
});

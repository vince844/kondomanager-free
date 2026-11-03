// @vitest-environment jsdom

/**
 * La scheda di una fattura con ritenuta mostra il codice tributo congelato alla registrazione (`dati_extra.fiscal.ritenuta_details`).
 *
 * Il codice dell'F24 non viene da lì: lo decide il regime della ritenuta (`TipoRitenuta::codiceTributo`) e, solo negli appalti, la natura del
 * percipiente (`GeneraDelegheF24Action`); dalla 1.11.0-beta.59 un ente non commerciale è 1020 (Coda 316) anche per le fatture registrate con
 * 1019. La scheda dice che quel codice è quello della registrazione e da che cosa dipende l'F24, per ogni regime: negli altri regimi il codice
 * è sempre lo stesso, quindi una frase che nomina la sola natura sarebbe falsa sotto la ritenuta di un professionista.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    router: { visit: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        url: '/admin/gestionale/1/fatture/5',
        props: { flash: {}, errors: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const FatturaShow = (await import('./FatturaShow.vue')).default;

function montaFattura(datiExtra: Record<string, unknown>) {
    return shallowMount(FatturaShow, {
        props: {
            condominio: { id: 1, nome: 'Condominio Test' },
            condomini: [],
            esercizio: { id: 1, nome: 'Esercizio 2026' },
            fattura: {
                id: 5,
                numero_documento: 'F-1',
                data_documento: '2026-03-01',
                data_scadenza: '2026-03-31',
                stato_pagamento: 'aperta',
                stato_approvazione: 'approvata',
                fornitore: { ragione_sociale: 'Parrocchia Test' },
                righe: [],
                note_collegate: [],
                coperture: [],
                documenti: [],
                dati_extra: datiExtra,
            },
            utenteRatifica: null,
            motivoBloccoModifica: null,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (nome: string) => `/${nome}` } },
    });
}

const testo = (w: ReturnType<typeof montaFattura>) => w.text().replace(/\s+/g, ' ');

describe('FatturaShow — il codice tributo della ritenuta', () => {
    test('Coda 316 — dice che il codice tributo mostrato è quello della registrazione e che nell\'F24 lo decide il regime della ritenuta e, negli appalti, la natura del percipiente', () => {
        const pagina = testo(montaFattura({ fiscal: { ritenuta_details: { aliquota: 4, codice_tributo: '1019' } } }));

        expect(pagina).toContain('Soggetto a ritenuta del 4%');
        expect(pagina).toContain('(Tributo alla registrazione: 1019)');
        expect(pagina).toContain('Nell\'F24 il codice lo decide il regime della ritenuta e, negli appalti, la natura del percipiente: non questo valore.');
        expect(pagina).not.toContain('(Tributo: 1019)');
    });

    test('Coda 316 — sotto la ritenuta di un professionista la frase non attribuisce il codice alla sola natura del percipiente', () => {
        // Il lavoro autonomo dà 1040 qualunque sia la natura (`TipoRitenuta::codiceTributo`): «lo decide la natura» sarebbe falso.
        const pagina = testo(montaFattura({ fiscal: { ritenuta_details: { aliquota: 20, codice_tributo: '1040' } } }));

        expect(pagina).toContain('(Tributo alla registrazione: 1040)');
        expect(pagina).not.toContain('il codice lo decide la natura del percipiente, non questo valore');
        expect(pagina).toContain('il regime della ritenuta');
    });

    test('Coda 316 — una fattura senza ritenuta non parla di codici tributo', () => {
        const pagina = testo(montaFattura({}));

        expect(pagina).not.toContain('Tributo');
        expect(pagina).not.toContain('Nell\'F24 il codice');
    });
});

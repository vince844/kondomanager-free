// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 2 — due scritte dell'estratto conto della persona.
 *
 * - Coda 227 (solo la frase): il credito dal passaggio ancora nei saldi, accanto a rate non pagate della stessa persona, dice che quel credito può andare
 *   prima su quelle, con «Compensa», e che si rimborsa quello che avanza; senza rate da pagare la frase è quella di prima. La frase è a 12 px, non 10.
 *   «Può», non «va»: le rate da pagare sono anche quelle emesse e non ancora scadute, e la compensazione della legge vale fra debiti già esigibili
 *   (art. 1243 c.c.); per quelle se il credito va prima sulla rata o si rimborsa lo decide l'amministratore.
 * - Coda 707, la scritta vicina: «(Int. …)» nel dettaglio di un addebito non compare per un'unità senza interno (prima «(Int. )»).
 *
 * Si monta la pagina con `shallowMount`, come in `EstrattoContoAnagrafica.test.ts`; la prop `rate_da_pagare` la manda il server
 * (`Beta14RateDaPagareNelRimborsoTest.php`).
 *
 * Cosa NON copre: la compensazione da sola (Coda 230, 1.13); il tooltip del dettaglio fuori dal DOM (si legge dal segnaposto che rende il contenuto).
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ url: '/admin/gestionale/28/anagrafiche/7/estratto-conto', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

const FRASE_DI_PRIMA = '€ 50,00 di credito dal passaggio di titolarità sono ancora nei saldi della gestione: diventano una quota rimborsabile quando il prossimo piano rate li assorbe.';
const FRASE_CON_RATE = '€ 50,00 di credito dal passaggio di titolarità sono ancora nei saldi della gestione: quando il prossimo piano rate li assorbe diventano una quota a credito. '
    + 'Ugo ha ancora € 200,00 di rate da pagare: quel credito può andare prima su quelle, con «Compensa»; si rimborsa quello che avanza.';

function monta(rimborso: Record<string, unknown>, timeline: unknown[] = []) {
    return shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Ugo', codice_fiscale: null, immobili: [] } as never, timeline,
            stats: { totale_addebiti: '€ 200,00', totale_versamenti: '€ 0,00', saldo_finale: '€ 200,00', saldo_raw: 20000, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null },
            rimborso: { quote: [], casse: [], credito_in_saldi: 5000, ...rimborso }, addebiti: [] as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}
const testo = (w: ReturnType<typeof monta>) => w.text().replace(/\s+/g, ' ');

describe('227 — il credito nei saldi accanto alle rate da pagare', () => {
    test('con rate da pagare la frase dice che il credito può andare prima su quelle, con «Compensa», e che si rimborsa quello che avanza', () => {
        expect(testo(monta({ rate_da_pagare: 20000 }))).toContain(FRASE_CON_RATE);
    });

    test('decide l\'amministratore: la frase non dice che il credito «va prima» né che si rimborsa «solo» quello che avanza', () => {
        expect(testo(monta({ rate_da_pagare: 20000 }))).not.toContain('va prima su quelle');
        expect(testo(monta({ rate_da_pagare: 20000 }))).not.toContain('si rimborsa solo');
    });

    test('senza rate da pagare resta la frase di prima', () => {
        expect(testo(monta({ rate_da_pagare: 0 }))).toContain(FRASE_DI_PRIMA);
        expect(testo(monta({ rate_da_pagare: 0 }))).not.toContain('prima su quelle');
    });

    test('la frase è a 12 pixel, non sotto', () => {
        const w = monta({ rate_da_pagare: 20000 });
        const p = w.findAll('p').find((x) => x.text().replace(/\s+/g, ' ').includes('di credito dal passaggio di titolarità'))!;

        expect(p.classes().some((c) => /^text-\[(?:[0-9]|10|11)px\]$/.test(c))).toBe(false);
        expect(p.classes()).toContain('text-xs');
    });
});

describe('707, la scritta vicina — «(Int. …)» nel dettaglio di un addebito', () => {
    const riga = (immobile: string | null) => ({
        id: 1, data: '2026-03-01', data_registrazione: null, protocollo: null, descrizione: 'Rata 1', gestione: null, dettagli: [], note: null, tipo_icona: 'invoice',
        dare: 10000, avere: 0, saldo: 0, breakdown: { type: 'addebito', start: 100, cost: 100, end: 0, immobile }, scrittura_id: 1, tipo_movimento: 'emissione_rate',
        stato_scrittura: 'registrata', stornabile: false, storna_annullata: false,
    });

    test('con un\'unità con l\'interno: «(Int. 1B)»; senza interno o generico: niente «(Int. )»', () => {
        expect(testo(monta({}, [riga('1B')]))).toContain('(Int. 1B)');
        expect(testo(monta({}, [riga(null)]))).not.toContain('(Int. )');
        expect(testo(monta({}, [riga('')]))).not.toContain('(Int. )');
        expect(testo(monta({}, [riga('Generico')]))).not.toContain('(Int.');
    });
});

describe('la parte della frase nel sorgente', () => {
    test('nessuna scritta della frase del credito sotto i 12 pixel', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue'), 'utf8').split('\n');
        const righe = sorgente.map((r, i) => ({ r, n: i + 1 })).filter(({ r }) => r.includes('rimborso.credito_in_saldi > 0'));

        expect(righe.length).toBeGreaterThan(0);
        expect(righe.filter(({ r }) => /text-\[(?:[0-9]|10|11)px\]/.test(r)).map(({ n }) => n)).toEqual([]);
    });
});

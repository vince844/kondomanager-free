// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 538 (1): il riquadro «Crediti da riallineare» dell'elenco degli incassi come nella bozza approvata. A video: un'icona in una scatola, tre righe
 * di testo lungo sotto il titolo, «Mostra il dettaglio» sotto il testo e «Scrivi le correzioni» lontano, a destra.
 *
 * - l'icona piccola, senza la scatola; sotto il titolo due righe che dicono che cosa c'è da fare;
 * - «Mostra il dettaglio» e «Scrivi le correzioni» insieme, nello stesso gruppo, fuori dal dettaglio;
 * - il testo lungo di prima dentro il dettaglio, non sotto il titolo.
 *
 * Si monta come `IncassoRateList.test.ts` (`shallowMount`, i segnaposto rendono il contenuto: il dettaglio è nel DOM anche chiuso).
 *
 * Che cosa NON copre: l'apertura vera del dettaglio con il clic (il componente `Collapsible` di reka-ui, sostituito); il disegno a 390 px e il tema scuro (a
 * video); le frasi della conferma, in `IncassoRateList.test.ts`.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const IncassoRateList = (await import('./IncassoRateList.vue')).default;

function monta(riallineamento: Record<string, unknown>) {
    return shallowMount(IncassoRateList, {
        props: {
            condominio: { id: 16, nome: 'Via roma' } as never,
            condomini: [] as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            esercizi: [] as never,
            movimenti: { data: [], meta: {} },
            stats: { totale_incassi: 0, incassi_mese: 0, stornati: 0 },
            filters: {},
            riallineamento_crediti: { compensazioni: [], rate: [], da_emettere: [], restano: [], ad_personam: [], ad_personam_non_calcolabili: [], ...riallineamento } as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

const COMPENSAZIONE = { scrittura_id: 100, protocollo: '2026/100', data: '15/03/2026', quota_id: 10, numero_rata: 0, persona: 'Ugo Uno', importo: 5000 };
const RATA = { rata_id: 200, numero_rata: 1, piano: 'Preventivo 2026', azione: 'scrivi', persone: [{ nome: 'Ugo Uno', spesa: 5000, credito: 20000 }] };
const testo = (el: { text: () => string }) => el.text().replace(/[\s ]+/g, ' ').trim();

describe('il riquadro «Crediti da riallineare»', () => {
    test('il titolo e due righe che dicono che cosa c\'è da fare; il testo lungo sta nel dettaglio', () => {
        const w = monta({ compensazioni: [COMPENSAZIONE], rate: [RATA] });
        const riquadro = w.find('[data-testid="riquadro-riallineamento"]');
        const sintesi = w.find('[data-testid="riallineamento-sintesi"]');
        const dettaglio = riquadro.findComponent({ name: 'CollapsibleContent' });

        expect(testo(sintesi)).toBe('2 scritture da aggiungere nel libro giornale: crediti riportati dagli esercizi precedenti presi dal conto sbagliato o rimasti fuori '
            + 'dall\'emissione della loro rata. Le scritture già registrate non si toccano.');
        expect(testo(dettaglio)).toContain('Alcuni crediti riportati da esercizi precedenti sono stati prelevati da «Crediti verso condomini»');
        expect(testo(sintesi)).not.toContain('Passate gestioni');
    });

    test('«Mostra il dettaglio» e «Scrivi le correzioni» insieme, nello stesso gruppo e fuori dal dettaglio', () => {
        const w = monta({ compensazioni: [COMPENSAZIONE] });
        const pulsanti = w.findAllComponents({ name: 'Button' }).filter((b) => ['Mostra il dettaglio', 'Scrivi le correzioni'].includes(testo(b)));

        expect(pulsanti.map((b) => testo(b))).toEqual(['Mostra il dettaglio', 'Scrivi le correzioni']);
        const gruppo = (b: (typeof pulsanti)[number]) => b.element.parentElement?.closest('div');
        expect(gruppo(pulsanti[0])).toBe(gruppo(pulsanti[1]));
        const dettaglio = w.findComponent({ name: 'CollapsibleContent' });
        expect(pulsanti.some((b) => dettaglio.element.contains(b.element))).toBe(false);
    });

    test('l\'icona è piccola e senza la scatola intorno', () => {
        const w = monta({ compensazioni: [COMPENSAZIONE] });
        // L'icona del titolo è il primo elemento color ambra del riquadro (nel segnaposto di `shallowMount` resta la classe).
        const icona = w.find('[data-testid="riquadro-riallineamento"]').element.querySelector('[class*="text-amber-600"]')!;

        expect(icona.getAttribute('class')).toMatch(/\bh-4\b/);
        expect(icona.getAttribute('class')).toMatch(/\bw-4\b/);
        expect(icona.parentElement!.className).not.toMatch(/\bp-2\b|bg-amber-100/);
    });

    test('in sola lettura le due righe lo dicono, e «Scrivi le correzioni» non c\'è', () => {
        const w = monta({ restano: [{ motivo: 'l\'esercizio è chiuso' }] });

        expect(testo(w.find('[data-testid="riallineamento-sintesi"]'))).toBe('Niente da scrivere: nel dettaglio, per ogni voce, il perché e, dove c\'è, la strada.');
        expect(testo(w)).not.toContain('Scrivi le correzioni');
        expect(testo(w.findComponent({ name: 'CollapsibleContent' }))).toContain('Resta com\'è: l\'esercizio è chiuso.');
    });
});

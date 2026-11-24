// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle comunicazioni nel pannello: commenti, allegati e letture in una riga sola sotto il titolo (Coda 594, nota E5 della decisione 313;
 * decisione 441; la scena 1 della bozza approvata `beta14_letture_bozza.html`).
 *
 * - I numeri delle letture (`[data-letture]`) stanno nella **stessa riga** di commenti e allegati (`[data-contatori-elenco]`), non in una seconda riga.
 * - La riga parte **dove parte il titolo**: chi vede lo scudo dell'approvazione ha la riga rientrata della larghezza dello scudo e del suo spazio; chi non
 *   lo vede no.
 * - «Aperture» e «Prese visione» hanno la loro icona, come commenti e allegati.
 * - Senza contatori e senza letture la riga non c'è.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

let puoApprovare = true;

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a data-titolo><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`,
        generateRoute: (n: string) => n,
        hasRole: () => true,
        hasPermission: () => puoApprovare,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');

type Colonna = { accessorKey?: string; cell: (contesto: unknown) => unknown };
const colonna = (columns as unknown as Colonna[]).find((c) => c.accessorKey === 'subject')!;

function cella(campi: Record<string, unknown> = {}) {
    const originale: Record<string, unknown> = {
        id: 7, subject: 'Lavori al tetto: chiusura del cortile', priority: 'media',
        is_published: true, is_approved: true, is_private: false, data_programmata: null,
        chiedi_presa_visione: true,
        contatori: { commenti: 3, allegati: 2, in_attesa: 0 },
        letture: { aperte: 12, prese_visione: 8 },
        ...campi,
    };
    const row = { index: 0, original: originale, getValue: (chiave: string) => originale[chiave] };

    return mount({ render: () => colonna.cell({ row, table: { options: { meta: {} } } }) });
}

beforeEach(() => {
    puoApprovare = true;
});

describe('la riga sotto il titolo — una sola', () => {
    test('commenti, allegati e letture stanno nella stessa riga, che non contiene il titolo', () => {
        const c = cella();
        const contatori = c.find('[data-contatori-elenco]').element as HTMLElement;
        const letture = c.find('[data-letture]').element as HTMLElement;
        const titolo = c.find('[data-titolo]').element as HTMLElement;
        const riga = contatori.parentElement!;

        expect(riga.contains(letture)).toBe(true);
        expect(riga.contains(titolo)).toBe(false);
        expect(riga.textContent).toContain('commenti.conta_commenti#3');
        expect(riga.textContent).toContain('comunicazioni.readings.list_opened');
        expect(riga.textContent).toContain('comunicazioni.readings.list_acknowledged');
    });

    test('con la sola presa visione chiesta e nessuna data: «Prese visione: 0» nella stessa riga, «Aperture» no', () => {
        const c = cella({ contatori: { commenti: 0, allegati: 1, in_attesa: 0 }, letture: { aperte: 0, prese_visione: 0 } });
        const riga = (c.find('[data-contatori-elenco]').element as HTMLElement).parentElement!;

        expect(riga.contains(c.find('[data-letture-prese-visione]').element)).toBe(true);
        expect(c.find('[data-letture-prese-visione]').text()).toContain('"count":0');
        expect(c.find('[data-letture-aperte]').exists()).toBe(false);
    });

    test('senza contatori e senza letture la riga non c\'è', () => {
        const c = cella({ contatori: undefined, chiedi_presa_visione: false, letture: { aperte: 0, prese_visione: 0 } });

        expect(c.find('[data-contatori-elenco]').exists()).toBe(false);
        expect(c.find('[data-letture]').exists()).toBe(false);
        // sotto la cella c'è solo la riga del titolo, senza una riga vuota che aggiunga spazio
        expect((c.element as HTMLElement).children.length).toBe(1);
    });
});

describe('la riga sotto il titolo — allineata al titolo, non allo scudo', () => {
    test('chi vede lo scudo ha la riga rientrata della larghezza dello scudo e del suo spazio', () => {
        const c = cella();
        const riga = (c.find('[data-contatori-elenco]').element as HTMLElement).parentElement!;

        expect(c.find('[data-scudo-approvazione]').exists()).toBe(true);
        expect(riga.className).toContain('pl-6');
    });

    test('chi non vede lo scudo ha la riga sotto il titolo senza rientro', () => {
        puoApprovare = false;
        const c = cella();
        const riga = (c.find('[data-contatori-elenco]').element as HTMLElement).parentElement!;

        expect(c.find('[data-scudo-approvazione]').exists()).toBe(false);
        expect(riga.className).not.toContain('pl-6');
        expect(riga.contains(c.find('[data-letture]').element)).toBe(true);
    });
});

describe('le letture con la loro icona', () => {
    test('«Aperture» e «Prese visione» hanno un\'icona, come commenti e allegati', () => {
        const c = cella();

        expect(c.find('[data-letture-aperte] svg').exists()).toBe(true);
        expect(c.find('[data-letture-prese-visione] svg').exists()).toBe(true);
        expect(c.find('[data-letture-aperte] svg').attributes('aria-hidden')).toBe('true');
    });
});

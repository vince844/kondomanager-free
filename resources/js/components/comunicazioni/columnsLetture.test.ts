// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle comunicazioni nel pannello: le letture sotto il titolo (Coda 594, decisione 311).
 *
 * Nessuna colonna nuova: i due numeri stanno **sotto il titolo**, in piccolo, in un elemento con l'attributo
 * `data-letture`. Dentro:
 * - `[data-letture-aperte]`, «Aperture: 12», se ce n'è almeno una;
 * - `[data-letture-prese-visione]`, «Prese visione: 8», se la comunicazione la chiede o se ce n'è almeno una.
 * Se non c'è niente da dire, l'elemento non c'è e la cella resta com'era.
 *
 * `trans()` restituisce la chiave seguita dai valori passati.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');

type Colonna = { id?: string; accessorKey?: string; cell: (contesto: unknown) => unknown };
const colonna = (columns as unknown as Colonna[]).find((c) => c.accessorKey === 'subject')!;

function cella(campi: Record<string, unknown> = {}) {
    const originale: Record<string, unknown> = {
        id: 7, subject: 'Chiusura dell\'acqua', priority: 'media',
        is_published: true, is_approved: true, is_private: false, data_programmata: null,
        chiedi_presa_visione: false,
        ...campi,
    };
    const row = { index: 0, original: originale, getValue: (chiave: string) => originale[chiave] };

    return mount({ render: () => colonna.cell({ row, table: { options: { meta: {} } } }) });
}

describe('l\'elenco delle comunicazioni — nessuna colonna in più', () => {
    test('le colonne sono quelle di prima', () => {
        const chiavi = (columns as unknown as Colonna[]).map((c) => c.accessorKey).filter(Boolean);

        expect(chiavi).toEqual(['subject', 'condomini', 'anagrafiche', 'priority', 'is_published']);
        expect(columns).toHaveLength(6);
    });
});

describe('la colonna del titolo — le letture sotto il titolo', () => {
    test('senza letture e senza presa visione chiesta la cella resta com\'era', () => {
        for (const campi of [{}, { letture: { aperte: 0, prese_visione: 0 } }]) {
            const c = cella(campi);

            expect(c.text()).toContain('Chiusura dell\'acqua');
            expect(c.find('[data-letture]').exists()).toBe(false);
            expect(c.text()).not.toContain('comunicazioni.readings');
        }
    });

    test('con delle aperture le dice sotto il titolo', () => {
        const c = cella({ letture: { aperte: 12, prese_visione: 0 } });

        expect(c.text()).toContain('Chiusura dell\'acqua');
        expect(c.find('[data-letture] [data-letture-aperte]').text()).toContain('comunicazioni.readings.list_opened');
        expect(c.find('[data-letture-aperte]').text()).toContain('"count":12');
        expect(c.find('[data-letture-prese-visione]').exists()).toBe(false);
    });

    test('dove la presa visione è chiesta dice quante sono, anche zero', () => {
        const c = cella({ chiedi_presa_visione: true, letture: { aperte: 0, prese_visione: 0 } });

        expect(c.find('[data-letture] [data-letture-prese-visione]').text()).toContain('comunicazioni.readings.list_acknowledged');
        expect(c.find('[data-letture-prese-visione]').text()).toContain('"count":0');
        expect(c.find('[data-letture-aperte]').exists()).toBe(false);
    });

    test('con tutte e due dice i due numeri, prima le aperture', () => {
        const c = cella({ chiedi_presa_visione: true, letture: { aperte: 12, prese_visione: 8 } });
        const testo = c.find('[data-letture]').text();

        expect(c.find('[data-letture-aperte]').text()).toContain('"count":12');
        expect(c.find('[data-letture-prese-visione]').text()).toContain('"count":8');
        expect(testo.indexOf('list_opened')).toBeLessThan(testo.indexOf('list_acknowledged'));
    });

    test('le prese visione date prima restano scritte anche se la spunta è stata tolta', () => {
        const c = cella({ chiedi_presa_visione: false, letture: { aperte: 0, prese_visione: 3 } });

        expect(c.find('[data-letture-prese-visione]').text()).toContain('"count":3');
    });

    test('i due numeri non stanno dentro il collegamento del titolo', () => {
        const c = cella({ chiedi_presa_visione: true, letture: { aperte: 12, prese_visione: 8 } });

        expect(c.find('a').text()).toBe('Chiusura dell\'acqua');
        expect(c.find('a [data-letture]').exists()).toBe(false);
    });
});

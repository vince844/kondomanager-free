// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle comunicazioni nel pannello: la colonna dello stato di una programmata (Coda 589, decisioni 290 e 291).
 *
 * Una comunicazione programmata dice «Programmata», con il pallino azzurro, e accanto il giorno e l'ora in cui esce
 * (`15/01/2027 09:00`), in un elemento con l'attributo `data-programmata-per`: con il filtro «Programmata»
 * l'amministratore vede in una schermata che cosa deve uscire e quando.
 *
 * Il filtro della colonna lavora anche nel browser: se non riconosce le programmate, le righe che il server manda
 * spariscono dalla tabella.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { columns } = await import('./columns');

type Colonna = { accessorKey?: string; cell: (contesto: unknown) => unknown; filterFn: (riga: unknown, id: string, valore: unknown) => boolean };
const colonna = (columns as unknown as Colonna[]).find((c) => c.accessorKey === 'is_published')!;

function riga(campi: Record<string, unknown> = {}) {
    const originale: Record<string, unknown> = {
        id: 7, subject: 'Chiusura dell\'acqua', priority: 'media',
        is_published: false, is_approved: true, is_private: false, data_programmata: null,
        ...campi,
    };

    return { original: originale, getValue: (chiave: string) => originale[chiave] };
}

function cella(campi: Record<string, unknown> = {}) {
    return mount({ render: () => colonna.cell({ row: riga(campi) }) });
}

describe('la colonna dello stato — una comunicazione programmata', () => {
    test('dice «Programmata», con il pallino azzurro e il giorno e l\'ora accanto', () => {
        const c = cella({ data_programmata: '2027-01-15T09:00' });

        expect(c.text()).toContain('comunicazioni.publication.scheduled');
        expect(c.text()).not.toContain('comunicazioni.publication.draft');
        expect(c.find('.bg-sky-500').exists()).toBe(true);
        expect(c.find('[data-programmata-per]').exists()).toBe(true);
        expect(c.find('[data-programmata-per]').text()).toContain('15/01/2027');
        expect(c.find('[data-programmata-per]').text()).toContain('09:00');
    });

    test('una bozza e una pubblicata restano com\'erano, senza giorno e ora', () => {
        const bozza = cella();
        expect(bozza.text()).toContain('comunicazioni.publication.draft');
        expect(bozza.find('[data-programmata-per]').exists()).toBe(false);

        const pubblicata = cella({ is_published: true });
        expect(pubblicata.text()).toContain('comunicazioni.publication.published');
        expect(pubblicata.find('[data-programmata-per]').exists()).toBe(false);
    });
});

describe('la colonna dello stato — il filtro nel browser', () => {
    test('una programmata passa con «Programmata» e non con «Bozza»', () => {
        const programmata = riga({ data_programmata: '2027-01-15T09:00' });

        expect(colonna.filterFn(programmata, 'is_published', ['scheduled'])).toBe(true);
        expect(colonna.filterFn(programmata, 'is_published', ['draft'])).toBe(false);
        expect(colonna.filterFn(programmata, 'is_published', ['draft', 'scheduled'])).toBe(true);
    });

    test('una bozza passa con «Bozza» e non con «Programmata»', () => {
        expect(colonna.filterFn(riga(), 'is_published', ['draft'])).toBe(true);
        expect(colonna.filterFn(riga(), 'is_published', ['scheduled'])).toBe(false);
    });

    test('senza filtro passano tutte', () => {
        expect(colonna.filterFn(riga({ data_programmata: '2027-01-15T09:00' }), 'is_published', [])).toBe(true);
    });
});

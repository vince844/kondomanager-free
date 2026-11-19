// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle segnalazioni del pannello: chi segue la segnalazione si legge sotto il titolo (decisione 294; giro 3).
 *
 * Una colonna sua toglieva spazio al titolo. Il nome sta nella cella del titolo, nella riga piccola con
 * `data-sotto-titolo`, dentro un elemento con `data-assegnata-a`; se quella persona non ha più il pannello, accanto c'è
 * un segno con `data-non-attivo`. Una segnalazione di nessuno non ha quell'elemento.
 *
 * La colonna con `accessorKey: 'assegnata_a'` resta, perché la barra dei filtri la usa per il filtro «Assegnata a»:
 * la tabella non la disegna (`colonneNascoste`), non si ordina, e il filtro lo applica il server.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
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

const modulo = await import('@/components/segnalazioni/columns');
const { columns } = modulo;

type Colonna = { accessorKey?: string; id?: string; cell?: unknown; header?: unknown; filterFn?: unknown; enableSorting?: boolean };
const colonne = columns as unknown as Colonna[];
const colonna = (chiave: string) => colonne.find((c) => c.accessorKey === chiave);

function cellaDelTitolo(assegnataA: unknown) {
    const oggetto: Record<string, unknown> = {
        id: 1, subject: 'Il cancello non chiude', is_approved: true, is_published: true, created_at: '3 giorni fa',
        creata_il: '08/11/2026 10:00', assegnata_a: assegnataA,
    };
    const riga = { original: oggetto, getValue: (k: string) => oggetto[k] };
    const nodo = (colonna('subject')!.cell as (ctx: unknown) => unknown)({ row: riga });

    return mount({ render: () => nodo as VNode });
}

describe('chi segue la segnalazione, sotto il titolo', () => {
    test('il nome sta nella riga sotto il titolo', () => {
        const w = cellaDelTitolo({ id: 9, name: 'Elena Studio', attivo: true });

        expect(w.find('[data-sotto-titolo] [data-assegnata-a]').text()).toContain('Elena Studio');
        expect(w.find('[data-non-attivo]').exists()).toBe(false);
    });

    test('se chi la segue non ha più il pannello, lo segna', () => {
        const w = cellaDelTitolo({ id: 12, name: 'Ida Sospesa', attivo: false });

        expect(w.find('[data-sotto-titolo] [data-assegnata-a]').text()).toContain('Ida Sospesa');
        expect(w.find('[data-assegnata-a] [data-non-attivo]').exists()).toBe(true);
        expect(w.find('[data-non-attivo]').attributes('title')).toBe('segnalazioni.assignment.inactive_short');
    });

    test('una segnalazione di nessuno non ha il nome, e il titolo resta', () => {
        for (const vuoto of [null, undefined]) {
            const w = cellaDelTitolo(vuoto);

            expect(w.find('[data-assegnata-a]').exists()).toBe(false);
            expect(w.text()).toContain('Il cancello non chiude');
        }
    });
});

describe('la colonna «Assegnata a» resta solo per il filtro', () => {
    test('c\'è, ma la tabella non la disegna e non si ordina', () => {
        expect(colonna('assegnata_a'), 'manca la colonna con accessorKey «assegnata_a»: serve al filtro della barra').toBeDefined();
        expect(colonna('assegnata_a')!.enableSorting).toBe(false);
        expect((modulo as unknown as { colonneNascoste?: Record<string, boolean> }).colonneNascoste).toEqual({ assegnata_a: false });
    });

    test('il filtro lo applica il server: la colonna non nasconde righe', () => {
        const filtro = colonna('assegnata_a')!.filterFn as (riga: unknown, id: string, valore: unknown) => boolean;
        const riga = { original: { assegnata_a: null }, getValue: () => null };

        expect(typeof filtro).toBe('function');
        expect(filtro(riga, 'assegnata_a', ['mie'])).toBe(true);
        expect(filtro(riga, 'assegnata_a', ['9'])).toBe(true);
        expect(filtro(riga, 'assegnata_a', [])).toBe(true);
    });
});

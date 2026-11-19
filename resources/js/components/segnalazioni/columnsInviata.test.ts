// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco delle segnalazioni del pannello: quando è stata inviata si legge sotto il titolo (giro 2; giro 3).
 *
 * Una colonna sua toglieva spazio al titolo: non c'è più. La cella del titolo (`data-cella-titolo`) ha il titolo, con
 * il testo intero nel `title` perché su due righe può essere troncato, e sotto una riga piccola (`data-sotto-titolo`)
 * con la data: le parole che manda il server («3 giorni fa») dentro un elemento con `data-inviata`, che ha per `title`
 * la data per esteso (`creata_il`). L'elenco parte comunque dalle più recenti: lo decide il server.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const { columns } = await import('@/components/segnalazioni/columns');

type Colonna = { accessorKey?: string; id?: string; cell?: unknown; header?: unknown; enableSorting?: boolean };
const colonne = columns as unknown as Colonna[];

function cellaDelTitolo(campi: Record<string, unknown>) {
    const oggetto: Record<string, unknown> = { id: 1, subject: 'Il cancello non chiude', is_approved: true, is_published: true, ...campi };
    const riga = { original: oggetto, getValue: (k: string) => oggetto[k] };
    const nodo = (colonne.find((c) => c.accessorKey === 'subject')!.cell as (ctx: unknown) => unknown)({ row: riga });

    return mount({ render: () => nodo as VNode });
}

describe('quando è stata inviata, sotto il titolo', () => {
    test('la colonna «Inviata» non c\'è più, e il titolo resta la prima colonna', () => {
        expect(colonne.find((c) => c.accessorKey === 'created_at')).toBeUndefined();
        expect(colonne[0].accessorKey).toBe('subject');
    });

    test('la data sta nella riga sotto il titolo, con la data per esteso al passaggio del puntatore', () => {
        const w = cellaDelTitolo({ created_at: '3 giorni fa', creata_il: '08/11/2026 10:00' });

        expect(w.find('[data-cella-titolo] [data-sotto-titolo] [data-inviata]').text()).toBe('3 giorni fa');
        expect(w.find('[data-inviata]').attributes('title')).toBe('08/11/2026 10:00');
    });

    test('il titolo porta alla scheda e ha il testo intero nel `title`', () => {
        const lungo = 'Infiltrazione dal tetto sopra il vano scale della scala B, dopo il temporale di ieri notte';
        const w = cellaDelTitolo({ subject: lungo, created_at: 'ieri', creata_il: '10/11/2026 22:15' });

        const titolo = w.find('[data-cella-titolo] a');
        expect(titolo.text()).toBe(lungo);
        expect(titolo.attributes('title')).toBe(lungo);
        expect(titolo.attributes('href')).toContain('segnalazioni.show');
    });

    test('la data sta prima di chi la segue', () => {
        const w = cellaDelTitolo({ created_at: '3 giorni fa', creata_il: '08/11/2026 10:00', assegnata_a: { id: 9, name: 'Elena Studio', attivo: true } });

        const testo = w.find('[data-sotto-titolo]').text();
        expect(testo.indexOf('3 giorni fa')).toBeGreaterThanOrEqual(0);
        expect(testo.indexOf('3 giorni fa')).toBeLessThan(testo.indexOf('Elena Studio'));
    });
});

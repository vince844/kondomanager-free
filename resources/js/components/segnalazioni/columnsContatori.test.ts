// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 453: nell'elenco delle segnalazioni del pannello, sotto il titolo, quanti commenti e allegati ha ogni riga.
 *
 * - Una riga piccola sotto il titolo, nessuna colonna nuova.
 * - I numeri sono quelli del server (`contatori`); «N in attesa» compare per chi modera, quando c'è qualcosa da approvare.
 * - Una riga senza contatori non mostra niente in più.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const { columns } = await import('@/components/segnalazioni/columns');

function cellaTitolo(riga: Record<string, unknown>) {
    const colonna = (columns as Array<{ accessorKey?: string; cell?: unknown }>).find((c) => c.accessorKey === 'subject');
    expect(colonna, 'la tabella non ha più la colonna del titolo').toBeDefined();
    const nodo = (colonna!.cell as (ctx: unknown) => unknown)({
        row: { original: riga, index: 0 },
        table: { options: { meta: { updateData: () => undefined } } },
    });

    return mount({ render: () => nodo as VNode });
}

describe('elenco delle segnalazioni del pannello: i contatori sotto il titolo', () => {
    // Decisione 463: ciò che aspetta dice che cosa è, «N commenti da approvare» e «N allegati da approvare», al posto di «N in attesa».
    test('sotto il titolo ci sono i commenti e gli allegati, con ciò che c\'è da approvare', () => {
        const w = cellaTitolo({ id: 7, subject: 'Il cancello non chiude', is_approved: true, contatori: { commenti: 3, allegati: 1, in_attesa: 2, commenti_in_attesa: 1, allegati_in_attesa: 1 } });

        expect(w.text()).toContain('Il cancello non chiude');
        expect(w.find('[data-contatore="commenti"]').text()).toContain('commenti.conta_commenti#3');
        expect(w.find('[data-contatore="allegati"]').text()).toContain('commenti.conta_allegati#1');
        expect(w.find('[data-contatore="commenti_da_approvare"]').text()).toContain('commenti.commenti_da_approvare#1');
        expect(w.find('[data-contatore="allegati_da_approvare"]').text()).toContain('commenti.allegati_da_approvare#1');
    });

    test('i contatori stanno nella cella del titolo, in una riga sotto di esso', () => {
        const w = cellaTitolo({ id: 7, subject: 'Il cancello non chiude', is_approved: true, contatori: { commenti: 0, allegati: 0, in_attesa: 0 } });
        const figli = Array.from(w.element.children);

        expect(figli).toHaveLength(2);
        expect(figli[0].textContent).toContain('Il cancello non chiude');
        expect(figli[1].hasAttribute('data-contatori-elenco')).toBe(true);
    });

    test('una riga senza contatori non mostra niente in più', () => {
        const w = cellaTitolo({ id: 7, subject: 'Il cancello non chiude', is_approved: true });

        expect(w.find('[data-contatori-elenco]').exists()).toBe(false);
        expect(w.text()).toContain('Il cancello non chiude');
    });
});

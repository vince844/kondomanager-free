// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco dei documenti dell'archivio quando l'utente che ha caricato un documento è stato eliminato: il documento resta, e la riga dice «utente
 * eliminato» al posto del nome. Il pannello lo riceve già dalla risorsa; il portale riceve il nome vuoto, e la riga lo dice lo stesso.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string, p: Record<string, string> = {}) => (chiave === 'documenti.visibility.sent_on_by_category' ? `Inviato ${p.date} da ${p.name} in ${p.category}` : chiave) }));
const route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.id}`;

const DocumentiList = (await import('./DocumentiList.vue')).default;

const documento = (createdBy: unknown) => ({ id: 7, name: 'Rendiconto 2026', description: '', created_at: 'tre mesi fa', created_at_data: '01/03/2027', mime_type: 'PDF',
    condomini: { options: [], full: [] }, anagrafiche: [], categorie: [{ id: 1, name: 'Verbali' }], created_by: createdBy });
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

describe('l\'autore eliminato nell\'elenco dei documenti', () => {
    test('dal pannello: il nome che manda la risorsa', () => {
        w = mount(DocumentiList, { props: { documenti: [documento({ user: { id: null, name: 'utente eliminato' }, anagrafica: null })] } as any, global: { mocks: { route } } });
        expect(w.text()).toContain('Inviato tre mesi fa da utente eliminato in verbali');
    });

    test('dal portale: il nome vuoto si legge «utente eliminato»', () => {
        w = mount(DocumentiList, { props: { documenti: [documento({ user: { id: null, name: null } })] } as any, global: { mocks: { route } } });
        expect(w.text()).toContain('da utente eliminato');
    });
});

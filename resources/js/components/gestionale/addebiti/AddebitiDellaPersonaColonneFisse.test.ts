// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Addebiti personali» nell'estratto conto della persona è una tabella con le colonne fisse: ogni addebito sta su una griglia dichiarata (non un `flex` che
 * segue il testo), così l'importo, la scadenza e «Annulla» stanno in colonna da una riga all'altra; l'importo è a destra, con le cifre della stessa larghezza.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, generatePath: (p: string) => `/${p}`, hasPermission: () => true }),
}));
(globalThis as any).route = (nome: string) => `/${nome}`;

const Addebiti = (await import('./AddebitiDellaPersona.vue')).default;
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const a = (id: number, causale: string, importo: number, annullabile: boolean) => ({ rata_id: id, causale, unita: 'Int. 3', importo, versato: 0, scadenza: '2026-12-20',
    natura: 'contributo', spesa_fatturata: false, piano: 'Addebiti individuali', annullabile, perche_resta: annullabile ? null : 'C\'è un incasso: si storna prima.' });

describe('«Addebiti personali»: le colonne fisse', () => {
    test('ogni addebito è una riga della stessa griglia dichiarata, con l\'importo a destra in cifre della stessa larghezza', () => {
        w = mount(Addebiti, { attachTo: document.body, props: { condominioId: 7, persona: { id: 1, nome: 'Rossi' },
            addebiti: [a(1, 'Spese di sollecito', 2500, true), a(2, 'Quota non assegnata: pulizie straordinarie della scala B', 125000, false)] } as any });
        const righe = [...document.body.querySelectorAll('[data-testid="addebito"]')] as HTMLElement[];
        const griglie = righe.map((r) => r.className.split(/\s+/).find((c) => /grid-cols-\[/.test(c)) ?? null);
        expect(griglie.every((g) => g !== null)).toBe(true);
        expect(new Set(griglie).size).toBe(1);
        const importo = righe[0].querySelector('[data-testid="importo-addebito"]') as HTMLElement;
        expect(importo.textContent).toContain('€');
        expect(importo.className).toMatch(/\btext-right\b/);
        expect(importo.className).toMatch(/\btabular-nums\b/);
    });
});

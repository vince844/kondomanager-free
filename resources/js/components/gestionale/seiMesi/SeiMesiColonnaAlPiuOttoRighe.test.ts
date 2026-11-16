// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Sei mesi per agire» nella colonna dell'Action inbox: la regola delle 8 vale per la card intera, non per ognuna delle due liste. Le righe da seguire e i
 * rendiconti insieme: fino a 8 tutti; oltre, 5 in tutto (prima le righe da seguire, almeno 2 rendiconti se ci sono) e «e altre N», che apre il cassetto.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: vi.fn(), post: vi.fn(), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, generatePath: (p: string) => `/${p}`, hasPermission: () => true }),
}));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Colonna = (await import('./SeiMesiColonna.vue')).default;
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const riga = (i: number) => ({ chiave: `sei-mesi-${i}`, esercizio: { id: i, nome: `Esercizio ${2000 + i}` }, data: '2026-12-31', giorni: 45, colore: 'vicina', quante: 2,
    cifra: 10000, persone: [], chiuse: { agito: 0, dispensa: 0 }, compito: true, compito_tornato: null, risposta: null });
const rendiconto = (i: number) => ({ chiave: `rendiconto-${i}`, esercizio: { id: 100 + i, nome: `Esercizio ${1900 + i}` }, data: '2026-12-20', giorni: 34, colore: 'vicina',
    stato: 'bozza', breve: 'Bozza', frase: 'Bozza.', passo: 'apri', url: `/r/${i}`, compito: true, risposta: null });

/** Un condominio per ogni riga e ogni rendiconto. */
function monta(righe: number, rendiconti: number) {
    const esiti = Array.from({ length: Math.max(righe, rendiconti) }, (_, i) => ({ condominio: { id: i + 1, nome: `Condominio ${i + 1}` }, oggi: '2026-11-16', troppe: null,
        righe: i < righe ? [riga(i + 1)] : [], rendiconti: i < rendiconti ? [rendiconto(i + 1)] : [] }));
    w = mount(Colonna, { attachTo: document.body, props: { condominio: null, stato: { esiti, totale: esiti.length, fatti: esiti.length, inCorso: false, daRiprovare: [] } } as any });
    const conta = (id: string) => document.body.querySelectorAll(`[data-testid="${id}"]`).length;

    return { righe: conta('riga-colonna'), rendiconti: conta('rendiconto-colonna'), testo: (document.body.textContent ?? '').replace(/\s+/g, ' ') };
}

describe('«Sei mesi per agire» nella colonna: al più 8 righe nella card', () => {
    test('8 righe da seguire e 8 rendiconti: non 16 righe, ma 5, e quante mancano', () => {
        const m = monta(8, 8);
        expect(m.righe + m.rendiconti).toBeLessThanOrEqual(8);
        expect(m).toMatchObject({ righe: 3, rendiconti: 2 });
        expect(m.testo).toContain('e altre 5 righe da seguire');
        expect(m.testo).toContain('e altri 6 rendiconti');
    });

    test('6 righe e 3 rendiconti (9 in tutto): oltre le 8, 5 in tutto', () => {
        expect(monta(6, 3)).toMatchObject({ righe: 3, rendiconti: 2 });
    });

    test('il controllo: fino a 8 in tutto si vedono tutte; una lista sola oltre le 8 mostra le prime 5, come prima', () => {
        expect(monta(5, 3)).toMatchObject({ righe: 5, rendiconti: 3 });
        w!.unmount();
        document.body.innerHTML = '';
        expect(monta(9, 0)).toMatchObject({ righe: 5, rendiconti: 0 });
        w!.unmount();
        document.body.innerHTML = '';
        expect(monta(0, 9)).toMatchObject({ righe: 0, rendiconti: 5 });
    });
});

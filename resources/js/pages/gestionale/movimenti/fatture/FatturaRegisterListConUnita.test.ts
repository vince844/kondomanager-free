// @vitest-environment jsdom

/**
 * L'elenco delle fatture filtrato sulle fatture con una riga su un'unità (le spese per singole unità), dove porta la riga delle spese personali del
 * riepilogo: l'elenco lo dice, e il filtro si toglie dalla stessa riga. Senza il filtro, niente.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';

enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: ['Accesso pannello amministratore'] } } },
    }),
}));
vi.mock('@/components/gestionale/movimenti/fatture/columns', () => ({ createColumns: () => [] }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.condominio ?? ''}`;

const FatturaRegisterList = (await import('./FatturaRegisterList.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = { GestionaleLayout: passa, MovimentiLayout: passa, PageHeaderGuide: true, FattureGuide: true, DataTable: true, Alert: true,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } };

const pagina = (filters: Record<string, string>) => mount(FatturaRegisterList, {
    props: { condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], fatture: { data: [], meta: {} },
        stats: { totale_aperte: 0, totale_sfori: 0, importo_da_pagare: 0 }, filters, regolazioniImmediate: 0, regolazioniSuVoceDiEntrata: [], esercizio: { id: 41 } } as never,
    global: { stubs },
});

describe('le fatture con una riga su un\'unità', () => {
    test('con il filtro l\'elenco lo dice, e porta a tutte le fatture; senza, niente', () => {
        const w = pagina({ con_unita: '1' });
        const riga = w.find('[data-test="filtro-con-unita"]');
        expect(riga.text().replace(/\s+/g, ' ')).toContain('Solo le fatture con una riga su un\'unità, cioè le spese per singole unità, di tutti gli esercizi.');
        expect(riga.find('a').attributes('href')).toBe('/admin.gestionale.fatture.index/28');
        expect(pagina({}).find('[data-test="filtro-con-unita"]').exists()).toBe(false);
    });
});

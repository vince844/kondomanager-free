// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom
// Il cassetto con le otto azioni insieme, l'ordine e i pulsanti verso le pagine giuste con i parametri; «Scelgo io» le toglie tutte.
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

vi.mock('vue-chartjs', () => ({ Line: defineComponent({ name: 'LineFinto', props: ['data', 'options', 'plugins'], setup: () => () => h('canvas') }) }));
vi.mock('chart.js', () => ({ Chart: { register: vi.fn() }, LineController: {}, LineElement: {}, PointElement: {}, LinearScale: {}, CategoryScale: {}, Filler: {}, Tooltip: {} }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const AzioniConsigliate = (await import('./AzioniConsigliate.vue')).default;

const giornoN = (i: number) => new Date(Date.UTC(2026, 10, 16 + i)).toISOString().slice(0, 10);
// Lo stesso caso delle otto azioni della matrice: saldo € 1.000,00, fattura di € 1.200,00 il 19/11, € 500,00 di rate il 22/11, € 100,00 di rate scadute.
function sp() {
    let s = 100000;
    const giorni = [];
    for (let i = 0; i <= 30; i++) {
        const u = i === 3 ? 120000 : 0;
        s -= u;
        giorni.push({ giorno: giornoN(i), saldo: s, con_entrate: 0, uscite: u, quante_uscite: u ? 1 : 0, voci_uscite: [], fatture: u ? [{ id: 103, fornitore: 'Edil', numero: '3', importo: u }] : [],
            entrate: 0, quante_entrate: 0, rate_in_scadenza: i === 6 ? 50000 : 0, quante_rate_in_scadenza: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [] });
    }

    return { giorni, primoSottoZero: null, minimoCents: 0, finaleCents: s, finaleConEntrateCents: 0, rateScaduteCents: 10000 };
}
const recupero = { persone: [{ anagraficaId: 1, nome: 'Rossi', interno: 'Int. 1', cifra: 10000, lettera: null }], servono: 1, servonoCents: 10000, quante: 1, cifra: 10000 };
const dati = {
    ritenute: { scadenze: [{ giorno: '2026-12-16', cifra: 1780 }] }, bonifici: { quanti: 1, cifra: 5000 }, deficit: { quanti: 0, cifra: 0 }, esercizioId: 9,
    rateDaEmettere: [{ pianoId: 5, piano: 'Preventivo 2026', esercizioId: 9, rate: [{ rataId: 50, numero: 7, scadenza: '2026-12-05', cifra: 40000 }], quante: 1, cifra: 40000 }],
    conti: [{ cassaId: 1, nome: 'Banca', tipo: 'banca', saldo: -5000, attiva: true }, { cassaId: 2, nome: 'Contanti', tipo: 'contanti', saldo: 8000, attiva: true }],
    fondi: [{ cassaId: 11, nome: 'Fondo facciata', saldo: 20000, sottotipo: 'vincolato_lavori', vincolo: null }],
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
async function apri(suggerisce: boolean) {
    w = mount(AzioniConsigliate, {
        attachTo: document.body,
        props: { open: true, saldoPrevisto: sp(), recupero, suggerisce, condominioId: 7, oggi: '2026-11-16', datiDelleAzioni: dati } as any,
        global: { stubs: { LettereDialog: { template: '<div />' } } },
    });
    await vi.dynamicImportSettled();
    await flushPromises();
}
const carte = () => [...document.body.querySelectorAll('[data-testid="azione-consigliata"]')] as HTMLElement[];
const href = (chiave: string) => (document.body.querySelector(`[data-chiave="${chiave}"] [data-testid="pulsante-azione"]`) as HTMLAnchorElement | null)?.getAttribute('href');

describe('il cassetto con le otto azioni', () => {
    test('(d) l\'ordine H, D, C, A, B, G, F, E', async () => {
        await apri(true);
        expect(carte().map((c) => c.dataset.chiave)).toEqual(['ritenute', 'bonifici', 'emissione', 'recupero', 'fatture', 'giroconto', 'fondo', 'straordinario']);
    });
    test('(f) i pulsanti: la pagina giusta con i parametri', async () => {
        await apri(true);
        expect(href('ritenute')).toBe('/admin.gestionale.f24.index?condominio=7');
        expect(href('bonifici')).toBe('/admin.inbox?condominio_id=7&tipo=verifica_pagamento');
        // Le rate da emettere non portano a una pagina: il pulsante apre la finestra «Emetti le rate».
        expect(document.body.querySelector('[data-chiave="emissione"] [data-testid="pulsante-azione"]')?.tagName).toBe('BUTTON');
        expect(href('fatture')).toBe('/admin.gestionale.fatture.show?condominio=7&fattura=103');
        expect(href('giroconto')).toBe('/admin.gestionale.giroconti.create?condominio=7&origine=2&destinazione=1&importo=5000');
        expect(href('fondo')).toBe('/admin.gestionale.casse.edit?condominio=7&cassa=11');
        expect(href('straordinario')).toBe('/admin.gestionale.esercizi.piani-rate.create?condominio=7&esercizio=9&tipo=straordinario');
        expect((document.body.querySelector('[data-chiave="recupero"] [data-testid="pulsante-incassi"]') as HTMLAnchorElement).getAttribute('href'))
            .toBe('/admin.gestionale.movimenti-rate.create?condominio=7');
    });
    test('(e) con «Scelgo io» nessuna azione, né la sezione', async () => {
        await apri(false);
        expect(carte()).toHaveLength(0);
        expect(document.body.querySelector('[data-testid="sezione-azioni"]')).toBeNull();
    });
});

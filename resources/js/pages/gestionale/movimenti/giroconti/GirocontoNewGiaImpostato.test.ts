// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il giroconto già impostato dall'indirizzo, come lo apre l'azione consigliata della tesoreria «Sposta € … da … a …»: la cassa di partenza, quella di arrivo e la
 * cifra arrivano dal server già controllate (`preselezione`) e compilano i campi, senza registrare niente; la causale resta da scrivere. Senza, i campi sono
 * vuoti come prima; una copertura in attesa scelta dall'indirizzo vince. La pagina non ha scritte sotto i 12 pixel, pillole né bordi sinistri colorati.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { visit: inertia.visit, post: inertia.post, on: () => () => undefined },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const GirocontoNew = (await import('./GirocontoNew.vue')).default;

const passa = { template: '<div><slot /></div>' };

const casse = [
    { id: 1, nome: 'Banca Intesa', tipo: 'banca', conto_contabile_id: 11, saldo_cents: -30000, sottotipo_fondo: null, is_override_assemblea: false, is_utilizzabile_per_imprevisti: false },
    { id: 2, nome: 'Cassa contanti', tipo: 'contanti', conto_contabile_id: 12, saldo_cents: 50000, sottotipo_fondo: null, is_override_assemblea: false, is_utilizzabile_per_imprevisti: false },
];
const copertura = { id: 5, importo: 12000, fondo_id: 99, fattura_id: 3, fattura_numero: 'F-3', fornitore: 'Edil Rossi srl', label: 'Ft. F-3' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(altro: Record<string, unknown> = {}) {
    w = mount(GirocontoNew, {
        attachTo: document.body,
        props: { condominio: { id: 7, nome: 'Via Verdi 12' }, condomini: [], esercizio: { id: 25 }, esercizi: [{ id: 25, nome: 'Esercizio 2026' }],
            gestioni: [{ id: 3, nome: 'Ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [25] }], casse, coperture_in_attesa: [], copertura_preselezionata_id: null, ...altro } as never,
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
            stubs: { GestionaleLayout: passa, PageHeaderGuide: passa, PulsanteGuida: true, GirocontiGuide: true, Alert: true,
                MoneyInput: { props: ['modelValue', 'id'], template: '<input :id="id" data-testid="importo" :value="modelValue" />' } },
        },
    });
    await flushPromises();
}
/** Il testo della pagina senza spazi: le righe dell'anteprima («Dare», la cifra, la cassa) sono elementi affiancati. */
const anteprima = () => (document.body.textContent ?? '').replace(/\s+/g, '');
const valoreImporto = () => (document.body.querySelector('[data-testid="importo"]') as HTMLInputElement).value;

describe('il giroconto già impostato dall\'indirizzo', () => {
    test('le due casse e la cifra compilano i campi: nell\'anteprima «Dare» Banca Intesa, «Avere» Cassa contanti, € 300,00; niente si registra', async () => {
        await monta({ preselezione: { origine: 2, destinazione: 1, importo_cents: 30000 } });
        expect(valoreImporto()).toBe('300');
        expect(anteprima()).toContain('Dare€300,00BancaIntesa');
        expect(anteprima()).toContain('Avere€300,00Cassacontanti');
        expect(inertia.post).not.toHaveBeenCalled();
        // La causale è da scrivere: il fuoco è lì.
        expect(document.activeElement?.id).toBe('causale');
    });

    test('quello che il server lascia vuoto resta vuoto; senza preselezione i campi sono vuoti come prima', async () => {
        await monta({ preselezione: { origine: 2, destinazione: null, importo_cents: null } });
        expect(anteprima()).toContain('Dare—Cassadidestinazionedaselezionare');
        expect(anteprima()).toContain('Avere—Cassacontanti');
        expect(valoreImporto()).toBe('0');
        w?.unmount();
        await monta();
        expect(anteprima()).toContain('Cassadioriginedaselezionare');
        expect(anteprima()).toContain('Cassadidestinazionedaselezionare');
    });

    test('una copertura in attesa scelta dall\'indirizzo vince sulla preselezione', async () => {
        await monta({ coperture_in_attesa: [copertura], copertura_preselezionata_id: 5, preselezione: { origine: 2, destinazione: 1, importo_cents: 30000 } });
        expect(anteprima()).not.toContain('Dare€300,00');
        expect(anteprima()).toContain('€120,00');
    });

    test('il disegno della pagina: niente scritte sotto i 12 pixel, nessuna pillola, nessun bordo sinistro colorato', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/movimenti/giroconti/GirocontoNew.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(8|9|10|11)px\]/);
        expect(sorgente).not.toMatch(/border-l-\d/);
        // Tondo resta solo il pallino del segno di spunta.
        expect(sorgente.match(/rounded-full/g) ?? []).toHaveLength(1);
    });
});

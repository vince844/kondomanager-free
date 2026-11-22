// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il diario dell'assemblea nel cassetto di lato (la scena 3 della bozza approvata dei documenti dei punti e del diario): «Diario» nel menu «Azioni» lo apre
 * a destra, come la guida.
 *
 * - Aperto chiede il diario al server (solo il diario, con il filtro «Tutto»): non parte con la pagina.
 * - In testa «Il diario dell'assemblea» e «Ordinaria del 15/12/2026 · le modifiche e gli invii, dal più recente», con «Chiudi».
 * - I filtri «Tutto | Modifiche | Invii» in una barra larga quanto il cassetto, e «Mostra altre»: visite parziali del solo diario, come oggi.
 * - Le righe per giorno («Oggi, 20/11/2026»): l'ora, la frase, chi sotto in grigio. Gli invii senza badge: la frase dice già che cosa è.
 *
 * Che cosa non copre: le righe del server (`DiarioDellAssembleaTest`, `DocumentiDeiPuntiTest`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Cassetto = (await import('./CassettoDelDiario.vue')).default;

const riga = (giorno: string, ora: string, frase: string, chi = 'Vincenzo Vecchio', fonte: 'modifica' | 'invio' = 'modifica') => ({
    fonte, istante: `${giorno}T${ora}`, giorno, ora, chi, frase, registro: null,
});
const DIARIO = {
    righe: [
        riga('20/11/2026', '23:14', 'Documento aggiunto al punto 1: «Preventivo quadro di manovra.pdf»'),
        riga('20/11/2026', '22:58', 'PEC a Rossi Mario: partita il 20/11', 'Il programma', 'invio'),
        riga('19/11/2026', '18:02', 'Preparata: 2 punti'),
    ],
    dopo: '2026-11-19T17:02:00.000000Z',
    altre: true,
};

let w: VueWrapper | null = null;
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    // Le 23:30 di Roma del 20/11, scritte con il fuso: «Oggi» è il giorno di Roma, su qualunque computer (rilievo C54).
    vi.setSystemTime(new Date('2026-11-20T23:30:00+01:00'));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.reload.mockReset(); vi.useRealTimers(); });
const qs = (sel: string) => document.body.querySelector(sel) as HTMLElement | null;
const testo = (el: Element | null) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Cassetto, { attachTo: document.body, props: { open: true, diario: DIARIO, sottotitolo: 'Ordinaria del 15/12/2026', ...piu } });
    await flushPromises();
}

describe('il diario nel cassetto', () => {
    test('aperto chiede il diario al server, solo il diario e con il filtro «Tutto»; chiuso non c\'è e non chiede niente', async () => {
        await monta({ open: false, diario: undefined });
        expect(qs('[data-testid="cassetto-diario"]')).toBeNull();
        expect(inertia.reload).not.toHaveBeenCalled();
        await w!.setProps({ open: true });
        await flushPromises();
        expect(inertia.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['diario'], data: { diario_filtro: 'tutto', diario_dopo: undefined } }));
        expect(qs('[data-testid="cassetto-diario"]')).not.toBeNull();
        expect(testo(qs('[data-testid="cassetto-diario"]'))).toContain('Un momento…');
    });

    test('in testa il titolo e il sottotitolo, con «Chiudi»; i filtri in una barra larga quanto il cassetto', async () => {
        await monta();
        expect(testo(qs('[data-testid="titolo-diario"]'))).toBe('Il diario dell\'assemblea');
        expect(testo(qs('[data-testid="sottotitolo-diario"]'))).toBe('Ordinaria del 15/12/2026 · le modifiche e gli invii, dal più recente');
        expect(testo(qs('[data-testid="chiudi-diario"]'))).toBe('Chiudi');
        const filtri = qs('[data-testid="filtri-diario"]')!;
        expect(filtri.className).toContain('grid-cols-3');
        expect([...filtri.querySelectorAll('button')].map((b) => testo(b))).toEqual(['Tutto', 'Modifiche', 'Invii']);
        (filtri.querySelectorAll('button')[2] as HTMLButtonElement).click();
        await flushPromises();
        expect(inertia.reload).toHaveBeenLastCalledWith(expect.objectContaining({ only: ['diario'], data: { diario_filtro: 'invii', diario_dopo: undefined } }));
    });

    test('le righe per giorno, con l\'ora, la frase e chi sotto; gli invii senza badge; «Mostra altre»', async () => {
        await monta();
        const giorni = [...document.body.querySelectorAll('[data-testid="giorno-diario"]')].map((g) => testo(g));
        expect(giorni).toEqual(['Oggi, 20/11/2026', '19/11/2026']);
        const prima = document.body.querySelector('[data-testid="riga-diario"]')!;
        expect(testo(prima.querySelector('[data-testid="ora"]'))).toBe('23:14');
        expect(testo(prima.querySelector('[data-testid="frase"]'))).toBe('Documento aggiunto al punto 1: «Preventivo quadro di manovra.pdf»');
        expect(testo(prima.querySelector('[data-testid="chi"]'))).toBe('Vincenzo Vecchio');
        const invio = document.body.querySelectorAll('[data-testid="riga-diario"]')[1];
        expect(testo(invio.querySelector('[data-testid="frase"]'))).toBe('PEC a Rossi Mario: partita il 20/11');
        expect(testo(invio)).not.toContain('Invio');
        expect(invio.querySelector('.rounded-md')).toBeNull();
        // Il sorgente non ha più il badge delle righe: né nel cassetto né nella pagina della riunione registrata.
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        expect(readFileSync(resolve(process.cwd(), 'resources/js/components/assemblee/DiarioDellAssemblea.vue'), 'utf8')).not.toContain('r.badge');
        (qs('[data-testid="mostra-altre-diario"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(inertia.reload).toHaveBeenLastCalledWith(expect.objectContaining({ only: ['diario'], data: { diario_filtro: 'tutto', diario_dopo: '2026-11-19T17:02:00.000000Z' } }));
    });
});

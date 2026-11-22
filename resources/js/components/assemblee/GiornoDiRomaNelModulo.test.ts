// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il giorno di Roma in tutto il modulo delle assemblee (rilievo C54 della prova a video del 22/11/2026): con il computer su un altro fuso, alle 00:30 di
 * Roma del 22/11 (le 23:30 del 21/11 a Londra) il giorno del computer è ancora il 21/11, mentre il programma conta i giorni a Roma. Le finestre che
 * propongono «oggi», e i loro `:max`/`:min`, usano `giornoDiRoma()` (`components/assemblee/sala/sala.ts`), come la sala e «Registra la spedizione».
 *
 * - Tre finestre di tre parti del modulo (la convocazione, il verbale, il seguito), montate alle 23:30 UTC del 21/11 con il computer sull'ora di Londra:
 *   propongono e mandano il 22/11.
 * - Una guardia: nessun file del modulo (`components/assemblee`, `pages/gestionale/assemblee`) usa più `oggiLocale`.
 *
 * Che cosa non copre: il conto dei giorni del server (le prove PHP della convocazione e del verbale).
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { oggiLocale } from '@/lib/giorno';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n }) }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}?${new URLSearchParams(p as Record<string, string>)}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { righeDellaBozza } = await import('./convocazione/viaRoma12');
const { confermato } = await import('./seguito/seguitoDiProva');
const RegistraLaConsegna = (await import('./convocazione/RegistraLaConsegna.vue')).default;
const SegnaImpugnata = (await import('./verbale/SegnaImpugnata.vue')).default;
const FinestraDellaCosa = (await import('./seguito/FinestraDellaCosa.vue')).default;

// Il computer sull'ora di Londra, come quello di Vincenzo la notte del 22/11: lo stesso su ogni macchina che lancia le prove.
let fusoDelComputer: string | undefined;
beforeAll(() => { fusoDelComputer = process.env.TZ; process.env.TZ = 'Europe/London'; });
afterAll(() => { if (fusoDelComputer === undefined) delete process.env.TZ; else process.env.TZ = fusoDelComputer; });

let w: VueWrapper | null = null;
beforeEach(() => {
    inertia.post.mockReset();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-21T23:30:00Z'));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const corpo = () => inertia.post.mock.calls.at(-1)?.[1] as Record<string, unknown>;

describe('alle 23:30 UTC del 21/11, a Roma è il 22/11', () => {
    test('il computer dice ancora 21/11: è il caso del rilievo', () => {
        expect(oggiLocale()).toBe('2026-11-21');
    });

    test('«Registra la consegna» (la convocazione) propone e manda il 22/11', async () => {
        w = mount(RegistraLaConsegna, { attachTo: document.body, props: { open: true, riga: righeDellaBozza()[3], rotta: '/a/1/invii/__INVIO__/consegna' } });
        await flushPromises();
        el('[data-testid="registra-consegna"]').click();
        expect(corpo()).toMatchObject({ giorno: '2026-11-22' });
    });

    test('«Segna impugnata» (il verbale) propone e manda il 22/11', async () => {
        w = mount(SegnaImpugnata, { attachTo: document.body, props: { open: true, punto: 4, rotta: '/a/1/delibere/77/impugnata' } });
        await flushPromises();
        const nota = el('#nota-impugnata') as HTMLTextAreaElement;
        nota.value = 'Citazione di Rossi Mario.';
        nota.dispatchEvent(new Event('input'));
        await flushPromises();
        el('[data-testid="conferma-impugnata"]').click();
        expect(corpo()).toMatchObject({ giorno: '2026-11-22' });
    });

    test('«Segna eseguita» (il seguito) propone e manda il 22/11', async () => {
        w = mount(FinestraDellaCosa, { attachTo: document.body, props: { open: true, modo: 'eseguita', condominioId: 3, assembleaId: 9, delibera: confermato().tabella[2] } });
        await flushPromises();
        el('[data-testid="conferma-della-cosa"]').click();
        expect(corpo()).toMatchObject({ giorno: '2026-11-22' });
    });
});

describe('la guardia', () => {
    test('nessun file del modulo propone «oggi» con il giorno del computer (`oggiLocale`)', () => {
        const cartelle = ['resources/js/components/assemblee', 'resources/js/pages/gestionale/assemblee'];
        const file = cartelle.flatMap((c) => (readdirSync(resolve(process.cwd(), c), { recursive: true }) as string[])
            .filter((f) => /\.(vue|ts)$/.test(f) && !f.endsWith('.test.ts')).map((f) => join(c, f)));
        expect(file.length).toBeGreaterThan(100);
        expect(file.filter((f) => readFileSync(resolve(process.cwd(), f), 'utf8').includes('oggiLocale'))).toEqual([]);
    });
});

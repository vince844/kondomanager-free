// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Registra la spedizione» e il foglio delle consegne a mano con molti condòmini (la prova a video del 22/11/2026, notte, assemblea n. 14):
 *
 * - da otto persone in su, sopra l'elenco, «Cerca per nome o per unità», come nelle presenze della sala: filtra sul posto, la × lo svuota, una ricerca
 *   senza risultati lo dice; sotto le otto persone il campo non c'è;
 * - l'elenco scorre dentro la finestra, che non si allunga oltre lo schermo;
 * - «Tutte» e «Nessuna» valgono su ciò che si vede: con la ricerca spuntano o tolgono solo le righe trovate, le altre restano come sono;
 * - il segnaposto del numero è «N. della raccomandata», che sta nel campo (prima «Numero della raccomar», tagliato).
 *
 * Che cosa non copre: la regola delle parole della ricerca (`lib/assemblee/sala/cerca.test.ts`); la richiesta al server (`FinestreDellaConvocazione.test.ts`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { riga } = await import('./viaRoma12');
const Spedizione = (await import('./RegistraLaSpedizione.vue')).default;
const Foglio = (await import('./FoglioDelleConsegne.vue')).default;

let w: VueWrapper | null = null;
beforeEach(() => {
    inertia.post.mockClear();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-12-10T10:00:00Z'));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

/** Dodici condòmini di Via Roma 12, ognuno con la sua unità. */
const PERSONE: [string, string][] = [
    ['Rossi Mario', 'Int. 3'], ['Bianchi Giorgio', 'Int. 2'], ['Costa Teresa', 'Int. 7'], ['Fontana Laura', 'Int. 8'], ['De Luca Gianni', 'Int. 13'],
    ['Russo Anna', 'Int. 5'], ['Neri Carla', 'Int. 9'], ['Colombo Rita', 'Int. 12'], ['Marino Luigi', 'Int. 10'], ['Romano Ida', 'Int. 11'],
    ['Esposito Luca', 'Int. 16'], ['Greco Silvio', 'Int. 112'],
];
const daSpedire = (quante: number) => PERSONE.slice(0, quante).map(([nome, unita], i) => riga(nome, { chiave: `p${i}`, anagrafica_id: i + 1, unita, invio_id: 200 + i,
    azioni: { spedizione: true, consegna: false, correggi: false, prova: false, ritiro: false, ricevuta: false } }));
const aMano = (quante: number) => daSpedire(quante).map((r) => ({ ...r, mezzo: 'a_mano' as const, mezzo_parola: 'A mano', stato: 'da_consegnare' as const }));

const nelDialogo = <T extends Element = HTMLElement>(selettore: string) => document.body.querySelector<T>(selettore);
const tutti = (selettore: string) => [...document.body.querySelectorAll<HTMLElement>(selettore)];
async function scrivi(el: HTMLInputElement, valore: string) {
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
}
async function clic(testid: string) {
    nelDialogo<HTMLElement>(`[data-testid="${testid}"]`)!.click();
    await flushPromises();
}
const conteggio = () => nelDialogo('[data-testid="registra-spedizione"]')!.textContent?.trim();

describe('«Registra la spedizione» con molti condòmini', () => {
    test('da otto persone «Cerca per nome o per unità» filtra l\'elenco, sul posto; la × lo svuota; senza risultati lo dice', async () => {
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe: daSpedire(12), scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        const cerca = nelDialogo<HTMLInputElement>('[data-testid="cerca-spedizione"]')!;
        expect(cerca.getAttribute('placeholder')).toBe('Cerca per nome o per unità');
        expect(tutti('[data-testid^="spedizione-2"]')).toHaveLength(12);
        await scrivi(cerca, 'neri');
        expect(tutti('[data-testid^="spedizione-2"]').map((r) => r.getAttribute('data-testid'))).toEqual(['spedizione-206']);
        // «12» trova l'Int. 12, non l'Int. 112.
        await scrivi(cerca, '12');
        expect(tutti('[data-testid^="spedizione-2"]').map((r) => r.getAttribute('data-testid'))).toEqual(['spedizione-207']);
        await scrivi(cerca, 'zzz');
        expect(nelDialogo('[data-testid="ricerca-vuota-spedizione"]')!.textContent).toContain('Nessuno per «zzz»');
        await clic('svuota-ricerca-spedizione');
        expect(cerca.value).toBe('');
        expect(tutti('[data-testid^="spedizione-2"]')).toHaveLength(12);
    });

    test('«Tutte» e «Nessuna» valgono su ciò che si vede; le righe nascoste restano come sono', async () => {
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe: daSpedire(12), scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        expect(conteggio()).toBe('Registra la spedizione (12)');
        await scrivi(nelDialogo<HTMLInputElement>('[data-testid="cerca-spedizione"]')!, 'russo');
        await clic('spedizione-nessuna');
        expect(conteggio()).toBe('Registra la spedizione (11)');
        await clic('spedizione-tutte');
        expect(conteggio()).toBe('Registra la spedizione (12)');
        await clic('svuota-ricerca-spedizione');
        await clic('spedizione-nessuna');
        expect(conteggio()).toBe('Registra la spedizione (0)');
        await scrivi(nelDialogo<HTMLInputElement>('[data-testid="cerca-spedizione"]')!, 'rossi');
        await clic('spedizione-tutte');
        expect(conteggio()).toBe('Registra la spedizione (1)');
        nelDialogo<HTMLElement>('[data-testid="registra-spedizione"]')!.click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/convocazione/spedizione', expect.objectContaining({ invii: [200] }), expect.anything());
    });

    test('l\'elenco scorre dentro la finestra; il numero dice «N. della raccomandata»', async () => {
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe: daSpedire(12), scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        const elenco = nelDialogo('[data-testid="elenco-spedizione"]')!;
        expect(elenco.className.split(' ')).toEqual(expect.arrayContaining(['overflow-y-auto']));
        expect(elenco.className).toMatch(/max-h-\[/);
        const numero = nelDialogo<HTMLInputElement>('[aria-label="Numero della raccomandata di Rossi Mario"]')!;
        expect(numero.getAttribute('placeholder')).toBe('N. della raccomandata');
    });

    test('sotto le otto persone la ricerca non c\'è', async () => {
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe: daSpedire(7), scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        expect(nelDialogo('[data-testid="cerca-spedizione"]')).toBeNull();
        expect(tutti('[data-testid^="spedizione-2"]')).toHaveLength(7);
    });
});

describe('il foglio delle consegne a mano con molti condòmini', () => {
    test('da otto persone la ricerca; «Tutte» e «Nessuna» valgono su ciò che si vede; l\'elenco scorre', async () => {
        w = mount(Foglio, { attachTo: document.body, props: { open: true, righe: aMano(9), rotte: { foglio: '/f', foglio_firmato: '/f/firmato' } } });
        await flushPromises();
        const carica = () => nelDialogo('[data-testid="carica-foglio"]')!.textContent?.trim();
        expect(carica()).toBe('Registra le consegne (9)');
        const cerca = nelDialogo<HTMLInputElement>('[data-testid="cerca-foglio"]')!;
        expect(cerca.getAttribute('placeholder')).toBe('Cerca per nome o per unità');
        await scrivi(cerca, 'colombo');
        expect(tutti('[data-foglio]').map((r) => r.getAttribute('data-foglio'))).toEqual(['207']);
        await clic('foglio-nessuna');
        expect(carica()).toBe('Registra le consegne (8)');
        await clic('foglio-tutte');
        expect(carica()).toBe('Registra le consegne (9)');
        expect(nelDialogo('[data-testid="elenco-foglio"]')!.className).toMatch(/max-h-\[[^\]]+\][\s\S]*overflow-y-auto|overflow-y-auto[\s\S]*max-h-\[/);
    });

    test('sotto le otto persone la ricerca non c\'è', async () => {
        w = mount(Foglio, { attachTo: document.body, props: { open: true, righe: aMano(5), rotte: { foglio: '/f', foglio_firmato: '/f/firmato' } } });
        await flushPromises();
        expect(nelDialogo('[data-testid="cerca-foglio"]')).toBeNull();
    });
});

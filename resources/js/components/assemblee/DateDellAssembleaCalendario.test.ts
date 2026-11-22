// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le date dell'assemblea si scelgono nel calendario del programma (`CampoGiorno`), non nel campo del browser: la prima convocazione di lunedì 14/12/2026
 * spostata al 21/12 arriva nel modulo con la sua ora, la prosecuzione prende il suo giorno, il giorno del consenso alla videoconferenza si toglie con la
 * «×». Con la pagina in sola lettura i giorni non si aprono.
 *
 * Che cosa non copre: il controllo delle date (`DateDellAssembleaMancanti.test.ts` e `lib/assemblee/date.ts`); il salvataggio, che fa la pagina.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { calendarioAperto, giornoMostrato, scegliIlGiorno } from '@/components/campoGiornoDiProva';
import type { ModuloDelleDate } from '@/lib/assemblee/pagine';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const DateDellAssemblea = (await import('./DateDellAssemblea.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 7, palazzine: [], scale: [], tabelle: [],
    maggioranze_proposte: {} };
const modulo = (): ModuloDelleDate => ({ tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'videoconferenza', piattaforma: 'Meet',
    consenso_videoconferenza_il: '2026-05-14', consenso_videoconferenza_modo: 'Delibera del 14/05/2026', sedute: [
        { convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' },
        { convocazione: 'seconda', numero: 2, giorno: '', ora: '18:30' }] });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const monta = (disabilitato = false) => {
    w = mount(DateDellAssemblea as never, { attachTo: document.body, props: { modelValue: modulo(), scelte, disabilitato } as never });

    return w;
};
const ultimo = () => (w!.emitted('update:modelValue') ?? []).at(-1)?.[0] as ModuloDelleDate;

describe('i giorni dell\'assemblea nel calendario', () => {
    test('la prima convocazione scelta nel calendario arriva nel modulo con la sua ora', async () => {
        monta();
        expect(giornoMostrato(w!.get('[data-testid="prima-giorno"]'))).toBe('14/12/2026');
        expect(giornoMostrato(w!.get('[data-testid="seconda-giorno"]'))).toBe('15/12/2026');
        await scegliIlGiorno(w!.get('[data-testid="prima-giorno"]'), '2026-12-21');
        expect(ultimo().sedute.find((s) => s.convocazione === 'prima' && s.numero === 1)).toEqual({ convocazione: 'prima', numero: 1, giorno: '2026-12-21', ora: '08:00' });
        expect(ultimo().sedute.find((s) => s.convocazione === 'seconda' && s.numero === 1)?.giorno).toBe('2026-12-15');
    });

    test('la prosecuzione prende il suo giorno', async () => {
        monta();
        const prosecuzione = w!.get('[aria-label="Il giorno della prosecuzione 1"]');
        expect(giornoMostrato(prosecuzione)).toBe('gg/mm/aaaa');
        await scegliIlGiorno(prosecuzione, '2026-12-16');
        expect(ultimo().sedute.find((s) => s.numero === 2)?.giorno).toBe('2026-12-16');
    });

    test('il giorno del consenso alla videoconferenza si toglie con la «×»', async () => {
        monta();
        const togli = document.body.querySelectorAll<HTMLButtonElement>('[aria-label="Togli il giorno"]');
        expect(togli).toHaveLength(1);
        togli[0].click();
        expect(ultimo().consenso_videoconferenza_il).toBe('');
    });

    test('in sola lettura i giorni non si aprono e la «×» non c\'è', async () => {
        monta(true);
        const prima = w!.get('[data-testid="prima-giorno"]');
        expect(giornoMostrato(prima)).toBe('14/12/2026');
        expect((prima.element as HTMLButtonElement).disabled).toBe(true);
        await prima.trigger('click');
        expect(calendarioAperto()).toBeNull();
        expect(document.body.querySelector('[aria-label="Togli il giorno"]')).toBeNull();
    });
});

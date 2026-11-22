// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * `CampoGiorno`, il campo del giorno del programma al posto di quello del browser: un pulsante con il giorno e l'icona del calendario, che apre il
 * calendario in italiano (lo stesso dei filtri degli allegati).
 *
 * - Il giorno si mostra 14/12/2026, senza giorno «gg/mm/aaaa»; `id`, `aria-label` e `data-testid` vanno al pulsante, `class` al contenitore.
 * - Un giorno scelto nel calendario arriva al `v-model` come `2026-12-14`, e il calendario si chiude; Esc lo chiude senza cambiare niente.
 * - `min` e `max` spengono i giorni fuori: un clic su un giorno spento non cambia niente.
 * - La «×» c'è solo con `svuotabile` e un giorno, e lo toglie (`''`).
 * - Spento, il pulsante non apre il calendario e la «×» non c'è.
 * - Nessuno spostamento per il fuso: con l'ora di Los Angeles e di Kiritimati il giorno mostrato e quello scelto restano gli stessi.
 *
 * Che cosa non copre: Invio sul pulsante (in jsdom un tasto non diventa un clic; il pulsante è un `<button type="button">`, che il browser apre con Invio
 * e con lo spazio); la misura a 375 pixel e il tema scuro a video.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { apriAlGiorno, calendarioAperto, giornoMostrato, scegliIlGiorno } from './campoGiornoDiProva';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const CampoGiorno = (await import('./CampoGiorno.vue')).default;

let w: VueWrapper | null = null;
const fusoDiPrima = process.env.TZ;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; process.env.TZ = fusoDiPrima; });

/** Il campo dentro un contenitore che tiene il `v-model`, come lo usa una finestra. */
async function monta(iniziale: string, piu: Record<string, unknown> = {}) {
    const valore = ref(iniziale);
    const Contenitore = defineComponent({
        setup: () => () => h(CampoGiorno, {
            modelValue: valore.value, 'onUpdate:modelValue': (v: string) => { valore.value = v; },
            id: 'giorno-di-prova', 'aria-label': 'Il giorno della prova', 'data-testid': 'giorno-di-prova', ...piu,
        }),
    });
    w = mount(Contenitore, { attachTo: document.body });
    await flushPromises();

    return { valore, pulsante: () => document.body.querySelector<HTMLButtonElement>('[data-testid="giorno-di-prova"]')! };
}

describe('il giorno mostrato', () => {
    test('con il giorno «14/12/2026», senza «gg/mm/aaaa»; il pulsante porta id, aria-label e data-testid', async () => {
        const { pulsante, valore } = await monta('2026-12-14');
        expect(giornoMostrato(pulsante())).toBe('14/12/2026');
        expect(pulsante().tagName).toBe('BUTTON');
        expect(pulsante().getAttribute('type')).toBe('button');
        expect(pulsante().id).toBe('giorno-di-prova');
        expect(pulsante().getAttribute('aria-label')).toBe('Il giorno della prova');
        valore.value = '';
        await flushPromises();
        expect(giornoMostrato(pulsante())).toBe('gg/mm/aaaa');
    });

    test('largo quanto il contenitore, alto 32 pixel di serie; `class` cambia il contenitore', async () => {
        const { pulsante } = await monta('2026-12-14', { class: 'h-9 text-sm sm:w-[190px]' });
        const contenitore = pulsante().parentElement!;
        expect(contenitore.tagName).toBe('SPAN');
        expect(contenitore.className).toContain('w-full');
        expect(contenitore.className).toContain('h-9');
        expect(contenitore.className).not.toContain('h-8');
        expect(contenitore.className).toContain('sm:w-[190px]');
        expect(pulsante().className).toContain('w-full');
        w!.unmount();
        const altro = await monta('');
        expect(altro.pulsante().parentElement!.className).toContain('h-8');
    });
});

describe('la scelta nel calendario', () => {
    test('il giorno scelto arriva al v-model in ISO, il pulsante lo mostra e il calendario si chiude', async () => {
        const { valore, pulsante } = await monta('2026-12-14');
        pulsante().click();
        await flushPromises();
        expect(calendarioAperto()).not.toBeNull();
        expect(calendarioAperto()!.textContent).toContain('dicembre 2026');
        await scegliIlGiorno(pulsante(), '2027-01-11');
        expect(valore.value).toBe('2027-01-11');
        expect(giornoMostrato(pulsante())).toBe('11/01/2027');
        expect(calendarioAperto()).toBeNull();
    });

    test('un clic sul giorno già scelto non lo toglie', async () => {
        const { valore, pulsante } = await monta('2026-12-14');
        await scegliIlGiorno(pulsante(), '2026-12-14');
        expect(valore.value).toBe('2026-12-14');
    });

    test('Esc chiude il calendario senza cambiare il giorno', async () => {
        const { valore, pulsante } = await monta('2026-12-14');
        pulsante().click();
        await flushPromises();
        expect(calendarioAperto()).not.toBeNull();
        document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(calendarioAperto()).toBeNull();
        expect(valore.value).toBe('2026-12-14');
    });
});

describe('min e max', () => {
    test('i giorni fuori sono spenti, e un clic su un giorno spento non cambia niente', async () => {
        const { valore, pulsante } = await monta('2026-12-10', { min: '2026-12-05', max: '2026-12-20' });
        const prima = await apriAlGiorno(pulsante(), '2026-12-04');
        expect(prima.hasAttribute('data-disabled')).toBe(true);
        expect(document.body.querySelector('[data-value="2026-12-05"]')!.hasAttribute('data-disabled')).toBe(false);
        expect(document.body.querySelector('[data-value="2026-12-20"]')!.hasAttribute('data-disabled')).toBe(false);
        expect(document.body.querySelector('[data-value="2026-12-21"]')!.hasAttribute('data-disabled')).toBe(true);
        prima.click();
        await flushPromises();
        expect(valore.value).toBe('2026-12-10');
        await expect(scegliIlGiorno(pulsante(), '2026-12-21')).rejects.toThrow('spento');
        await scegliIlGiorno(pulsante(), '2026-12-20');
        expect(valore.value).toBe('2026-12-20');
    });

    test('il mese dopo il massimo non si raggiunge con la freccia', async () => {
        const { pulsante } = await monta('2026-11-10', { max: '2026-11-20' });
        await expect(apriAlGiorno(pulsante(), '2026-12-01')).rejects.toThrow('non si raggiunge');
    });

    test('senza un giorno il calendario apre sul mese dei limiti', async () => {
        const { pulsante } = await monta('', { min: '2099-03-10' });
        pulsante().click();
        await flushPromises();
        expect(calendarioAperto()!.textContent).toContain('marzo 2099');
    });
});

describe('la «×»', () => {
    test('c\'è solo con `svuotabile` e un giorno, e lo toglie', async () => {
        const senza = await monta('2026-12-14');
        expect(document.body.querySelector('[aria-label="Togli il giorno"]')).toBeNull();
        expect(senza.valore.value).toBe('2026-12-14');
        w!.unmount();

        const { valore, pulsante } = await monta('2026-12-14', { svuotabile: true });
        const x = document.body.querySelector<HTMLButtonElement>('[aria-label="Togli il giorno"]')!;
        expect(x).not.toBeNull();
        expect(x.getAttribute('type')).toBe('button');
        x.click();
        await flushPromises();
        expect(valore.value).toBe('');
        expect(giornoMostrato(pulsante())).toBe('gg/mm/aaaa');
        expect(document.body.querySelector('[aria-label="Togli il giorno"]')).toBeNull();
    });
});

describe('spento', () => {
    test('il pulsante non apre il calendario, e la «×» non c\'è', async () => {
        const { pulsante } = await monta('2026-12-14', { disabled: true, svuotabile: true });
        expect(pulsante().disabled).toBe(true);
        pulsante().click();
        await flushPromises();
        expect(calendarioAperto()).toBeNull();
        expect(document.body.querySelector('[aria-label="Togli il giorno"]')).toBeNull();
        expect(giornoMostrato(pulsante())).toBe('14/12/2026');
    });
});

describe('nessuno spostamento per il fuso', () => {
    // Per ogni fuso la trappola che sposterebbe il giorno se passasse da un'ora: a Los Angeles la mezzanotte di Greenwich del 14/12 è ancora il 13; a
    // Kiritimati la mezzanotte locale del 14/12 per Greenwich è il 13.
    const trappole: Record<string, () => number | string> = {
        'America/Los_Angeles': () => new Date('2026-12-14T00:00:00Z').getDate(),
        'Pacific/Kiritimati': () => new Date(2026, 11, 14).toISOString().slice(0, 10),
    };
    const spostati: Record<string, number | string> = { 'America/Los_Angeles': 13, 'Pacific/Kiritimati': '2026-12-13' };
    for (const fuso of Object.keys(trappole)) {
        test(`con l'ora di ${fuso} il giorno mostrato e quello scelto sono gli stessi`, async () => {
            process.env.TZ = fuso;
            // Il fuso è cambiato davvero: la trappola qui sposta il giorno.
            expect(trappole[fuso]()).toBe(spostati[fuso]);
            const { valore, pulsante } = await monta('2026-12-14');
            expect(giornoMostrato(pulsante())).toBe('14/12/2026');
            await scegliIlGiorno(pulsante(), '2026-03-29');
            expect(valore.value).toBe('2026-03-29');
            expect(giornoMostrato(pulsante())).toBe('29/03/2026');
        });
    }
});

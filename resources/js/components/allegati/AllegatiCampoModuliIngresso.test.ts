// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// @vitest-environment jsdom

/**
 * La foto dal telefono all'ingresso nel core (Coda 590): le quattro correzioni della decisione 296 e il tasto «Foto» del riquadro
 * della scheda (decisione 484).
 *
 * - F1: più file scelti insieme si preparano uno alla volta, in fila: la seconda preparazione parte solo quando la prima è finita
 *   (una foto da 12 megapixel decodificata occupa circa 48 MB, e un telefono con poca memoria chiude la scheda del browser).
 * - F2: dove il puntatore è un dito i pulsanti sono alti almeno 40 px (`pointer-coarse:h-10` / `pointer-coarse:min-h-10`).
 * - F3: «Preparo le foto…» sta in una regione `role="status"`, che i lettori di schermo annunciano.
 * - Decisione 484: nella forma «zona» (il riquadro «Allegati» della scheda) accanto alla barra «Aggiungi allegati» c'è un tasto
 *   «Foto», solo dove lo schermo si tocca, con il nome «Scatta una foto» per chi non vede, che apre il campo della fotocamera e
 *   passa dalla stessa preparazione. La barra resta un pulsante a sé: niente pulsanti dentro pulsanti. Al computer non c'è.
 *
 * Che cosa non copre: l'altezza vera in pixel (jsdom non disegna: si guardano le classi), la fotocamera vera e la ricodifica
 * (`preparaImmagine()` qui è finta; la prova `resources/js/lib/allegati/preparaImmagine.test.ts`), l'annuncio vero di un lettore
 * di schermo.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

const preparazione = vi.hoisted(() => ({ fn: vi.fn() }));
vi.mock('@/lib/allegati/preparaImmagine', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    preparaImmagine: preparazione.fn,
}));

const AllegatiCampoModuli = (await import('./AllegatiCampoModuli.vue')).default;

/** Il telefono (puntatore grossolano) o il computer. */
function dispositivo(conIlDito: boolean) {
    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        writable: true,
        value: (domanda: string) => ({
            matches: conIlDito && domanda.includes('pointer: coarse'),
            media: domanda,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
        }),
    });
}

const file = (nome: string, tipo = 'image/jpeg') => new File(['x'], nome, { type: tipo });

function monta(props: Record<string, unknown> = {}) {
    return mount(AllegatiCampoModuli, {
        props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', ...props },
        attachTo: document.body,
    });
}

const campoFile = (w: VueWrapper) => w.findAll('input[type="file"]').find((i) => i.attributes('data-campo') !== 'fotocamera')!;
const campoFoto = (w: VueWrapper) => w.find('input[data-campo="fotocamera"]');
const tastoFotoZona = (w: VueWrapper) => w.find('button[data-azione="foto-zona"]');
const emessi = (w: VueWrapper) => (w.emitted('update:modelValue') ?? []).map((e) => e[0] as File[]);

async function scegli(campo: ReturnType<VueWrapper['find']>, files: File[]) {
    Object.defineProperty(campo.element, 'files', { value: files, configurable: true });
    await campo.trigger('change');
    await flushPromises();
}

beforeEach(() => {
    document.body.innerHTML = '';
    dispositivo(true);
    preparazione.fn.mockReset();
    preparazione.fn.mockImplementation(async (f: File) => new File(['pronto'], `pronto-${f.name}`, { type: f.type }));
});

describe('F1 — le foto si preparano una alla volta, in fila', () => {
    test('con tre file la seconda preparazione parte solo quando la prima è finita, e la terza dopo la seconda', async () => {
        const finisci: Array<(f: File) => void> = [];
        preparazione.fn.mockImplementation((f: File) => new Promise<File>((risolvi) => { finisci.push(() => risolvi(new File(['p'], `pronto-${f.name}`))); }));
        const w = monta();

        Object.defineProperty(campoFile(w).element, 'files', { value: [file('a.jpg'), file('b.jpg'), file('c.jpg')], configurable: true });
        await campoFile(w).trigger('change');
        await flushPromises();

        // Con `Promise.all` qui sarebbero già partite tutte e tre.
        expect(preparazione.fn).toHaveBeenCalledTimes(1);
        expect((preparazione.fn.mock.calls[0][0] as File).name).toBe('a.jpg');

        finisci[0](file('x'));
        await flushPromises();
        expect(preparazione.fn).toHaveBeenCalledTimes(2);
        expect((preparazione.fn.mock.calls[1][0] as File).name).toBe('b.jpg');

        finisci[1](file('x'));
        await flushPromises();
        expect(preparazione.fn).toHaveBeenCalledTimes(3);
        expect(emessi(w)).toHaveLength(0);

        finisci[2](file('x'));
        await flushPromises();
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronto-a.jpg', 'pronto-b.jpg', 'pronto-c.jpg']);
    });
});

describe('F2 — dove il puntatore è un dito i pulsanti sono alti almeno 40 px', () => {
    test('«Scatta una foto» e «Aggiungi allegati» dei moduli hanno l\'altezza da dito', () => {
        const w = monta();
        const foto = w.get('button[data-azione="scatta-foto"]');
        const aggiungi = w.findAll('button').find((b) => b.text().includes('allegati.upload.label'))!;

        expect(foto.classes()).toContain('pointer-coarse:h-10');
        expect(aggiungi.classes()).toContain('pointer-coarse:h-10');
    });
});

describe('F3 — «Preparo le foto…» si annuncia', () => {
    test('il testo sta in una regione role="status", che c\'è anche prima, vuota, nei moduli e nella zona', async () => {
        for (const aspetto of ['campo', 'zona']) {
            document.body.innerHTML = '';
            let finisci: (f: File) => void = () => undefined;
            preparazione.fn.mockImplementation(() => new Promise<File>((risolvi) => { finisci = risolvi; }));
            const w = monta({ aspetto });

            const regione = w.find('[role="status"]');
            expect(regione.exists(), `manca la regione in «${aspetto}»`).toBe(true);
            expect(regione.text()).toBe('');

            Object.defineProperty(campoFile(w).element, 'files', { value: [file('a.jpg')], configurable: true });
            await campoFile(w).trigger('change');
            await flushPromises();

            expect(w.get('[role="status"]').text()).toContain('allegati.upload.preparing');
            finisci(file('pronta.jpg'));
            await flushPromises();
            expect(w.get('[role="status"]').text()).toBe('');
        }
    });
});

describe('Decisione 484 — il tasto «Foto» accanto alla barra del riquadro della scheda', () => {
    test('con lo schermo touch c\'è, accanto alla barra e fuori da lei, con il nome «Scatta una foto», e apre la fotocamera', async () => {
        const w = monta({ aspetto: 'zona' });
        const barra = w.get('[data-zona-allegati]');
        const tasto = tastoFotoZona(w);

        expect(tasto.exists()).toBe(true);
        expect(tasto.attributes('type')).toBe('button');
        expect(tasto.attributes('aria-label')).toBe('allegati.upload.take_photo');
        expect(tasto.text()).toContain('allegati.upload.photo_short');
        // Niente pulsanti dentro pulsanti: la barra e il tasto sono fratelli nella stessa riga.
        expect(barra.find('button').exists()).toBe(false);
        expect(tasto.element.parentElement).toBe(barra.element.parentElement);
        // Alto quanto la barra (la riga li stira insieme), almeno 40 px, con lo stesso tratteggio.
        expect(tasto.classes()).toEqual(expect.arrayContaining(['border-dashed', 'min-h-10']));
        expect(barra.classes()).toContain('min-h-10');
        expect(barra.element.parentElement!.className).toContain('items-stretch');

        expect(campoFoto(w).exists()).toBe(true);
        expect(campoFoto(w).attributes('accept')).toBe('image/*');
        expect(campoFoto(w).attributes('capture')).toBe('environment');
        const clic = vi.spyOn(campoFoto(w).element as HTMLInputElement, 'click').mockImplementation(() => undefined);
        await tasto.trigger('click');
        expect(clic).toHaveBeenCalledTimes(1);
    });

    test('la foto scattata dal tasto passa dalla stessa preparazione, come scattata', async () => {
        const w = monta({ aspetto: 'zona' });

        await scegli(campoFoto(w), [file('image.jpg')]);

        expect(preparazione.fn).toHaveBeenCalledTimes(1);
        expect(preparazione.fn.mock.calls[0][1]).toMatchObject({ scattata: true });
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronto-image.jpg']);
    });

    test('anche i file scelti dalla barra passano dalla preparazione, e mentre si preparano barra e tasto aspettano', async () => {
        let finisci: (f: File) => void = () => undefined;
        preparazione.fn.mockImplementation(() => new Promise<File>((risolvi) => { finisci = risolvi; }));
        const w = monta({ aspetto: 'zona' });

        Object.defineProperty(campoFile(w).element, 'files', { value: [file('a.jpg')], configurable: true });
        await campoFile(w).trigger('change');
        await flushPromises();

        expect(preparazione.fn).toHaveBeenCalledTimes(1);
        expect(w.get('[data-zona-allegati]').attributes('disabled')).toBeDefined();
        expect(tastoFotoZona(w).attributes('disabled')).toBeDefined();
        finisci(file('pronta.jpg'));
        await flushPromises();
        expect(w.get('[data-zona-allegati]').attributes('disabled')).toBeUndefined();
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronta.jpg']);
    });

    test('senza posti liberi il tasto è spento', () => {
        expect(tastoFotoZona(monta({ aspetto: 'zona', rimasti: 0 })).attributes('disabled')).toBeDefined();
    });

    test('al computer non c\'è: la barra resta com\'è', () => {
        dispositivo(false);
        const w = monta({ aspetto: 'zona' });

        expect(tastoFotoZona(w).exists()).toBe(false);
        expect(campoFoto(w).exists()).toBe(false);
        expect(w.get('[data-zona-allegati]').text()).toContain('allegati.upload.label');
    });

    test('senza le immagini fra i formati non c\'è', () => {
        expect(tastoFotoZona(monta({ aspetto: 'zona', formati: ['pdf'] })).exists()).toBe(false);
    });

    test('nei moduli resta «Scatta una foto», e il tasto della zona non c\'è', () => {
        const w = monta();

        expect(w.find('button[data-azione="scatta-foto"]').text()).toContain('allegati.upload.take_photo');
        expect(tastoFotoZona(w).exists()).toBe(false);
    });
});

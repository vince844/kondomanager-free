// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il campo degli allegati: la foto dal telefono (Coda 590, decisione 293).
 *
 * - Dove il puntatore è un dito (`matchMedia('(pointer: coarse)')`) e fra i formati ci sono le immagini, accanto ad
 *   «Aggiungi allegati» c'è «Scatta una foto» (`button[data-azione="scatta-foto"]`). Apre un secondo campo dei file,
 *   nascosto (`input[data-campo="fotocamera"]`): `accept="image/*"`, `capture="environment"`, un file alla volta.
 * - Ogni file, scelto o scattato, passa da `preparaImmagine()` (`@/lib/allegati/preparaImmagine`) prima di entrare
 *   nell'elenco: quello che il componente emette è il file preparato.
 * - Mentre le foto si preparano la pagina lo dice (`[data-stato="preparazione"]`) e i pulsanti aspettano.
 *
 * Qui `preparaImmagine()` è finta: che cosa fa davvero lo prova `resources/js/lib/allegati/preparaImmagine.test.ts`.
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

function file(nome: string, tipo = 'image/jpeg'): File {
    return new File(['x'], nome, { type: tipo });
}

function monta(props: Record<string, unknown> = {}) {
    return mount(AllegatiCampoModuli, {
        props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', ...props },
        attachTo: document.body,
    });
}

const pulsanteFoto = (w: VueWrapper) => w.find('button[data-azione="scatta-foto"]');
const campoFoto = (w: VueWrapper) => w.find('input[data-campo="fotocamera"]');
const campoFile = (w: VueWrapper) => w.findAll('input[type="file"]').find((i) => i.attributes('data-campo') !== 'fotocamera')!;

/** Mette dei file in un campo e lancia il `change`, come fa il browser dopo la scelta. */
async function scegli(campo: ReturnType<VueWrapper['find']>, files: File[]) {
    Object.defineProperty(campo.element, 'files', { value: files, configurable: true });
    await campo.trigger('change');
    await flushPromises();
}

const emessi = (w: VueWrapper) => (w.emitted('update:modelValue') ?? []).map((e) => e[0] as File[]);

beforeEach(() => {
    document.body.innerHTML = '';
    dispositivo(true);
    preparazione.fn.mockReset();
    // Di serie la preparazione restituisce un file nuovo, riconoscibile dal nome.
    preparazione.fn.mockImplementation(async (f: File) => new File(['pronto'], `pronto-${f.name}`, { type: f.type }));
});

describe('AllegatiCampoModuli — il pulsante «Scatta una foto»', () => {
    test('sul telefono c\'è, accanto ad «Aggiungi allegati», con il suo campo nascosto', () => {
        const w = monta();

        expect(pulsanteFoto(w).exists()).toBe(true);
        expect(pulsanteFoto(w).text()).toContain('allegati.upload.take_photo');
        expect(pulsanteFoto(w).attributes('type')).toBe('button');
        expect(campoFoto(w).exists()).toBe(true);
        expect(campoFoto(w).attributes('type')).toBe('file');
        expect(campoFoto(w).attributes('accept')).toBe('image/*');
        expect(campoFoto(w).attributes('capture')).toBe('environment');
        expect(campoFoto(w).attributes('multiple')).toBeUndefined();
        // Il campo di sempre resta com'era.
        expect(campoFile(w).attributes('accept')).toBe('.jpg,.jpeg,.png,.webp,.pdf');
        expect(campoFile(w).attributes('multiple')).toBeDefined();
    });

    test('sul computer non c\'è: lì `capture` non fa niente', () => {
        dispositivo(false);

        expect(pulsanteFoto(monta()).exists()).toBe(false);
    });

    test('dove `matchMedia` non esiste il componente si monta, senza il pulsante', () => {
        Object.defineProperty(window, 'matchMedia', { configurable: true, writable: true, value: undefined });

        const w = monta();

        expect(w.text()).toContain('allegati.upload.label');
        expect(pulsanteFoto(w).exists()).toBe(false);
    });

    test('dove le immagini non sono fra i formati ammessi non c\'è', () => {
        expect(pulsanteFoto(monta({ formati: ['pdf'] })).exists()).toBe(false);
        expect(pulsanteFoto(monta({ formati: ['pdf', 'jpg'] })).exists()).toBe(true);
    });

    test('il clic apre la fotocamera, cioè il suo campo', async () => {
        const w = monta();
        const clic = vi.spyOn(campoFoto(w).element as HTMLInputElement, 'click').mockImplementation(() => undefined);

        await pulsanteFoto(w).trigger('click');

        expect(clic).toHaveBeenCalledTimes(1);
    });

    test('senza posti liberi è spento', () => {
        const w = monta({ rimasti: 0 });

        expect(pulsanteFoto(w).attributes('disabled')).toBeDefined();
    });

    test('la pagina dice che le foto partono rimpicciolite e ripulite', () => {
        expect(monta().text()).toContain('allegati.upload.photo_hint');
        dispositivo(false);
        expect(monta().text()).toContain('allegati.upload.photo_hint');
    });
});

describe('AllegatiCampoModuli — ogni file passa dalla preparazione', () => {
    test('la foto scattata entra preparata, e alla preparazione si dice che è stata scattata', async () => {
        const w = monta();
        const scatto = file('image.jpg');

        await scegli(campoFoto(w), [scatto]);

        expect(preparazione.fn).toHaveBeenCalledTimes(1);
        expect(preparazione.fn.mock.calls[0][0]).toBe(scatto);
        expect(preparazione.fn.mock.calls[0][1]).toMatchObject({ scattata: true });
        expect(emessi(w)).toHaveLength(1);
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronto-image.jpg']);
    });

    test('i file scelti entrano preparati, nell\'ordine scelto, dopo quelli che c\'erano', async () => {
        const giaScelto = file('prima.pdf', 'application/pdf');
        const w = monta({ modelValue: [giaScelto] });

        await scegli(campoFile(w), [file('a.jpg'), file('b.png', 'image/png')]);

        expect(preparazione.fn).toHaveBeenCalledTimes(2);
        expect(preparazione.fn.mock.calls.map((c) => (c[0] as File).name)).toEqual(['a.jpg', 'b.png']);
        // I file scelti dalla galleria non sono «scattati»: tengono il loro nome.
        expect(preparazione.fn.mock.calls.every((c) => !(c[1] as { scattata?: boolean } | undefined)?.scattata)).toBe(true);
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['prima.pdf', 'pronto-a.jpg', 'pronto-b.png']);
    });

    test('oltre i posti liberi non entra niente', async () => {
        const w = monta({ rimasti: 2 });

        await scegli(campoFile(w), [file('a.jpg'), file('b.jpg'), file('c.jpg')]);

        expect(emessi(w)[0]).toHaveLength(2);
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronto-a.jpg', 'pronto-b.jpg']);
    });

    test('mentre le foto si preparano la pagina lo dice e i pulsanti aspettano', async () => {
        let finisci: (f: File) => void = () => undefined;
        preparazione.fn.mockImplementation(() => new Promise<File>((risolvi) => { finisci = risolvi; }));
        const w = monta();
        expect(w.find('[data-stato="preparazione"]').exists()).toBe(false);

        Object.defineProperty(campoFoto(w).element, 'files', { value: [file('image.jpg')], configurable: true });
        await campoFoto(w).trigger('change');
        await flushPromises();

        expect(w.find('[data-stato="preparazione"]').exists()).toBe(true);
        expect(w.find('[data-stato="preparazione"]').text()).toContain('allegati.upload.preparing');
        expect(pulsanteFoto(w).attributes('disabled')).toBeDefined();
        expect(emessi(w)).toHaveLength(0);

        finisci(file('pronta.jpg'));
        await flushPromises();

        expect(w.find('[data-stato="preparazione"]').exists()).toBe(false);
        expect(pulsanteFoto(w).attributes('disabled')).toBeUndefined();
        expect(emessi(w)[0].map((f) => f.name)).toEqual(['pronta.jpg']);
    });

    test('scegliere senza scegliere niente non emette niente', async () => {
        const w = monta();

        await scegli(campoFoto(w), []);

        expect(preparazione.fn).not.toHaveBeenCalled();
        expect(emessi(w)).toHaveLength(0);
    });
});

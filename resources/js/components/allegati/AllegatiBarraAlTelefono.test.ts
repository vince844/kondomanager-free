// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.12 (H4): nel riquadro «Allegati» della scheda, a 375 px, la barra «Aggiungi allegati» accanto al tasto «Foto» si tagliava in «Aggiungi all…».
 * Dove lo schermo si tocca (il tasto «Foto» c'è) la barra e il tasto hanno margini e spazi più stretti (`px-3`, `gap-2`): con 375 px di schermo la scritta
 * ha circa 24 px in più, e si legge intera. Al computer la barra resta com'era (`px-4`, `gap-3`).
 *
 * Dalla Fase 1-bis della beta.12 (P2): dove lo schermo si tocca, sotto la barra, una riga con i formati, i posti rimasti e la frase della foto.
 *
 * Che cosa non copre: la misura vera dei caratteri (jsdom non impagina): la larghezza l'ha stimata il costruttore con i margini della scheda, e si guarda a
 * video a 375 px.
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

describe('la barra «Aggiungi allegati» del riquadro della scheda', () => {
    test('dove lo schermo si tocca, con il tasto «Foto», la barra e il tasto sono più stretti', () => {
        dispositivo(true);
        const w = mount(AllegatiCampoModuli, { props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', aspetto: 'zona', accept: ['jpg', 'pdf'] }, attachTo: document.body });
        const barra = w.get('button[data-zona-allegati]');
        // Con il codice di prima: `px-4` e `gap-3` anche qui, e il tasto «Foto» `px-4`.
        expect(barra.classes()).toEqual(expect.arrayContaining(['px-3', 'gap-2']));
        expect(barra.classes()).not.toContain('px-4');
        expect(w.get('button[data-azione="foto-zona"]').classes()).toContain('px-3');
        w.unmount();
    });

    // P2 della lente P (Fase 1-bis della beta.12): al tocco il suggerimento della «i» non si apre (e la «i» sta dentro la barra, che apre la scelta dei
    // file), quindi dove c'è il tasto «Foto» la frase della foto, i formati e i posti rimasti si scrivono in una riga sotto la barra. Con il codice di
    // prima stavano solo nel suggerimento: al telefono non si leggevano.
    test('dove lo schermo si tocca, la frase della foto, i formati e i posti rimasti si leggono sotto la barra, senza aprire la «i»', () => {
        dispositivo(true);
        const w = mount(AllegatiCampoModuli, { props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', aspetto: 'zona' }, attachTo: document.body });
        const riga = w.find('[data-riga="suggerimento-al-tocco"]');
        expect(riga.exists()).toBe(true);
        expect(riga.text()).toContain('allegati.upload.photo_hint');
        expect(riga.text()).toContain('allegati.upload.hint_zona#6');
        // La riga è fuori dalla barra: toccarla non apre la scelta dei file.
        expect(w.get('button[data-zona-allegati]').element.contains(riga.element)).toBe(false);
        w.unmount();
    });

    test('senza immagini fra i formati la riga dice i formati e i posti, non la frase della foto (e il tasto «Foto» non c\'è)', () => {
        dispositivo(true);
        const w = mount(AllegatiCampoModuli, { props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', aspetto: 'zona', formati: ['pdf'] }, attachTo: document.body });
        expect(w.find('button[data-azione="foto-zona"]').exists()).toBe(false);
        expect(w.find('[data-riga="suggerimento-al-tocco"]').exists()).toBe(false);
        w.unmount();
    });

    test('al computer la barra resta com\'era', () => {
        dispositivo(false);
        const w = mount(AllegatiCampoModuli, { props: { modelValue: [], massimo: 6, rimasti: 6, limiteFile: '20 MB', aspetto: 'zona', accept: ['jpg', 'pdf'] }, attachTo: document.body });
        expect(w.find('button[data-azione="foto-zona"]').exists()).toBe(false);
        expect(w.get('button[data-zona-allegati]').classes()).toEqual(expect.arrayContaining(['px-4', 'gap-3']));
        // Al computer la «i» si apre al passaggio del mouse e dalla tastiera: la riga sotto la barra non c'è.
        expect(w.find('[data-riga="suggerimento-al-tocco"]').exists()).toBe(false);
        w.unmount();
    });
});

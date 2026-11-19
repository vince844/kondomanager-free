// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La preparazione delle immagini prima dell'invio (Coda 590, decisione 293).
 *
 * Ogni file scelto o scattato passa da `preparaImmagine()` prima di entrare nell'elenco degli allegati:
 * - un JPG è sempre ricodificato (qualità 0,82), che toglie i metadati (posizione GPS, modello del telefono) e lo
 *   raddrizza; se il lato lungo supera 1.600 px è anche rimpicciolito;
 * - un PNG o un WEBP è rimpicciolito solo se il lato lungo supera 1.600 px, altrimenti resta com'è;
 * - un PDF, o qualunque altro file, resta com'è;
 * - se qualcosa va storto parte il file originale: il caricamento non si ferma per una foto che non si prepara.
 *
 * Chi decodifica e ricodifica (il «codec») arriva come valore: qui è finto, perché nell'ambiente dei test il canvas
 * non esiste.
 */

import { describe, expect, test, vi } from 'vitest';
import { LATO_MASSIMO, QUALITA, misureRidotte, nomeFoto, preparaImmagine, trattamento, type Codec } from './preparaImmagine';

function file(nome: string, tipo: string, contenuto = 'originale'): File {
    return new File([contenuto], nome, { type: tipo, lastModified: 1_700_000_000_000 });
}

/** Un codec finto: dice le misure date e «disegna» un blob del tipo chiesto (o di quello forzato). */
function codecFinto(larghezza: number, altezza: number, opzioni: { tipoReso?: string | null; lancia?: boolean } = {}) {
    const disegna = vi.fn(async (_l: number, _a: number, tipo: string, _q: number) =>
        opzioni.tipoReso === null ? null : new Blob(['ricodificata'], { type: opzioni.tipoReso ?? tipo }));
    const chiudi = vi.fn();
    const leggi = vi.fn(async (_f: File) => {
        if (opzioni.lancia) {
            throw new Error('non si decodifica');
        }

        return { larghezza, altezza, disegna, chiudi };
    });

    return { codec: { leggi } as Codec, leggi, disegna, chiudi };
}

describe('le costanti', () => {
    test('il lato lungo è 1.600 px e la qualità 0,82', () => {
        expect(LATO_MASSIMO).toBe(1600);
        expect(QUALITA).toBe(0.82);
    });
});

describe('misureRidotte', () => {
    test('rimpicciolisce tenendo le proporzioni, sul lato lungo', () => {
        expect(misureRidotte(4000, 3000)).toEqual({ larghezza: 1600, altezza: 1200 });
        expect(misureRidotte(3000, 4000)).toEqual({ larghezza: 1200, altezza: 1600 });
        expect(misureRidotte(4032, 3024)).toEqual({ larghezza: 1600, altezza: 1200 });
        expect(misureRidotte(3200, 3200)).toEqual({ larghezza: 1600, altezza: 1600 });
    });

    test('non ingrandisce mai', () => {
        expect(misureRidotte(1600, 1200)).toEqual({ larghezza: 1600, altezza: 1200 });
        expect(misureRidotte(800, 600)).toEqual({ larghezza: 800, altezza: 600 });
        expect(misureRidotte(1, 1)).toEqual({ larghezza: 1, altezza: 1 });
    });

    test('dà misure intere, mai zero', () => {
        expect(misureRidotte(5000, 100)).toEqual({ larghezza: 1600, altezza: 32 });
        expect(misureRidotte(8000, 1)).toEqual({ larghezza: 1600, altezza: 1 });
        expect(misureRidotte(1, 8000)).toEqual({ larghezza: 1, altezza: 1600 });
    });

    test('segue un altro lato massimo, se lo si dà', () => {
        expect(misureRidotte(4000, 3000, 2000)).toEqual({ larghezza: 2000, altezza: 1500 });
    });
});

describe('trattamento', () => {
    test('un JPG si ricodifica sempre, dal tipo o dall\'estensione', () => {
        expect(trattamento(file('guasto.jpg', 'image/jpeg'))).toBe('ricodifica');
        expect(trattamento(file('IMG_0012.JPG', ''))).toBe('ricodifica');
        expect(trattamento(file('foto.jpeg', 'application/octet-stream'))).toBe('ricodifica');
        expect(trattamento(file('image', 'image/jpeg'))).toBe('ricodifica');
    });

    test('un PNG o un WEBP si tocca solo se è grande', () => {
        expect(trattamento(file('schermata.png', 'image/png'))).toBe('se_grande');
        expect(trattamento(file('foto.webp', 'image/webp'))).toBe('se_grande');
        expect(trattamento(file('SCHERMATA.PNG', ''))).toBe('se_grande');
    });

    test('un PDF, e ogni altro file, non si tocca', () => {
        expect(trattamento(file('verbale.pdf', 'application/pdf'))).toBe('niente');
        expect(trattamento(file('foto.heic', 'image/heic'))).toBe('niente');
        expect(trattamento(file('appunti.txt', 'text/plain'))).toBe('niente');
    });
});

describe('nomeFoto', () => {
    test('è «foto», il giorno e l\'ora del telefono, con gli zeri', () => {
        expect(nomeFoto(new Date(2026, 10, 12, 9, 30, 5))).toBe('foto-20261112-093005.jpg');
        expect(nomeFoto(new Date(2027, 0, 3, 18, 4, 59))).toBe('foto-20270103-180459.jpg');
    });
});

describe('preparaImmagine — un JPG', () => {
    test('grande: rimpicciolito a 1.600 px e ricodificato, con il suo nome', async () => {
        const originale = file('guasto.jpg', 'image/jpeg');
        const { codec, disegna, chiudi } = codecFinto(4000, 3000);

        const pronto = await preparaImmagine(originale, { codec });

        expect(disegna).toHaveBeenCalledTimes(1);
        expect(disegna).toHaveBeenCalledWith(1600, 1200, 'image/jpeg', 0.82);
        expect(pronto).not.toBe(originale);
        expect(pronto).toBeInstanceOf(File);
        expect(pronto.name).toBe('guasto.jpg');
        expect(pronto.type).toBe('image/jpeg');
        expect(await pronto.text()).toBe('ricodificata');
        expect(chiudi).toHaveBeenCalled();
    });

    test('piccolo: ricodificato lo stesso, alle sue misure, perché perda i metadati', async () => {
        const originale = file('piccola.jpg', 'image/jpeg');
        const { codec, disegna } = codecFinto(800, 600);

        const pronto = await preparaImmagine(originale, { codec });

        expect(disegna).toHaveBeenCalledWith(800, 600, 'image/jpeg', 0.82);
        expect(pronto).not.toBe(originale);
        expect(await pronto.text()).toBe('ricodificata');
    });

    test('scattato con il pulsante prende il nome della foto, con l\'ora data', async () => {
        const { codec } = codecFinto(4032, 3024);

        const pronto = await preparaImmagine(file('image.jpg', 'image/jpeg'), { codec, scattata: true, adesso: new Date(2026, 10, 12, 9, 30, 5) });

        expect(pronto.name).toBe('foto-20261112-093005.jpg');
        expect(pronto.type).toBe('image/jpeg');
    });

    test('scelto dalla galleria tiene il suo nome, anche con `scattata` falso', async () => {
        const { codec } = codecFinto(4032, 3024);

        const pronto = await preparaImmagine(file('vacanze.jpg', 'image/jpeg'), { codec, scattata: false });

        expect(pronto.name).toBe('vacanze.jpg');
    });
});

describe('preparaImmagine — un PNG o un WEBP', () => {
    test('piccolo: resta il file originale, senza disegnare niente', async () => {
        const originale = file('schermata.png', 'image/png');
        const { codec, leggi, disegna } = codecFinto(800, 600);

        const pronto = await preparaImmagine(originale, { codec });

        expect(leggi).toHaveBeenCalledTimes(1);
        expect(disegna).not.toHaveBeenCalled();
        expect(pronto).toBe(originale);
    });

    test('grande: rimpicciolito nel suo formato', async () => {
        const originale = file('schermata.png', 'image/png');
        const { codec, disegna } = codecFinto(4000, 3000);

        const pronto = await preparaImmagine(originale, { codec });

        expect(disegna).toHaveBeenCalledTimes(1);
        expect(disegna.mock.calls[0].slice(0, 3)).toEqual([1600, 1200, 'image/png']);
        expect(pronto.name).toBe('schermata.png');
        expect(pronto.type).toBe('image/png');
    });

    test('se il browser restituisce un altro formato (WEBP su Safari), resta l\'originale', async () => {
        const originale = file('foto.webp', 'image/webp');
        const { codec } = codecFinto(4000, 3000, { tipoReso: 'image/png' });

        expect(await preparaImmagine(originale, { codec })).toBe(originale);
    });
});

describe('preparaImmagine — ciò che non si tocca, e ciò che va storto', () => {
    test('un PDF resta com\'è, e il codec non viene nemmeno chiamato', async () => {
        const originale = file('verbale.pdf', 'application/pdf');
        const { codec, leggi } = codecFinto(4000, 3000);

        expect(await preparaImmagine(originale, { codec })).toBe(originale);
        expect(leggi).not.toHaveBeenCalled();
    });

    test('se la foto non si decodifica parte l\'originale', async () => {
        const originale = file('rotta.jpg', 'image/jpeg');
        const { codec } = codecFinto(0, 0, { lancia: true });

        expect(await preparaImmagine(originale, { codec })).toBe(originale);
    });

    test('se il disegno non riesce parte l\'originale', async () => {
        const originale = file('guasto.jpg', 'image/jpeg');
        const { codec, chiudi } = codecFinto(4000, 3000, { tipoReso: null });

        expect(await preparaImmagine(originale, { codec })).toBe(originale);
        expect(chiudi).toHaveBeenCalled();
    });

    test('una foto scattata che non si prepara parte com\'è, con il suo nome', async () => {
        const originale = file('image.jpg', 'image/jpeg');
        const { codec } = codecFinto(0, 0, { lancia: true });

        const pronto = await preparaImmagine(originale, { codec, scattata: true });

        expect(pronto).toBe(originale);
    });

    test('senza un codec dato usa quello del browser, e dove il canvas non c\'è non si rompe', async () => {
        // In questo ambiente il canvas non esiste: la funzione deve restituire il file, non lanciare.
        const originale = file('guasto.jpg', 'image/jpeg');

        const pronto = await preparaImmagine(originale);

        expect(pronto).toBeInstanceOf(File);
        expect(pronto.name).toBe('guasto.jpg');
    });
});

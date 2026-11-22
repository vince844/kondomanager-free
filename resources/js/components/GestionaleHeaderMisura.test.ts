// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il menu in alto del gestionale (decisione 573): le dieci voci, «Assemblee» compresa, stanno su una riga sola a 1.280 pixel e a 1.400, e nessuna va su due
 * righe; l'icona del codice non c'è più, né qui né nel menu del resto del programma (il collegamento resta nel piede della pagina).
 *
 * La misura legge le classi di `GestionaleHeader.vue` (il margine e lo spazio fra le voci, l'imbottitura di ogni voce, l'icona e il suo margine) e la
 * larghezza vera di ogni parola dal carattere della pagina (Instrument Sans 500, il file `woff` di `@fontsource`, a 14 pixel come `text-sm`), senza le
 * coppie di crenatura: la parola vera è un poco più stretta, quindi la misura sta dalla parte sicura. Il contenitore è `max-w-7xl` (1.280) con `px-4`: a
 * 1.400 pixel la barra è larga quanto a 1.280. Si toglie la barra di scorrimento (15 pixel) dove c'è.
 *
 * Che cosa non copre: il disegno del browser (la misura è il modello delle classi, non una fotografia); il cassetto del telefono, che non cambia.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { inflateSync } from 'node:zlib';
import { describe, expect, test } from 'vitest';

const radice = join(__dirname, '../../..');
const gestionale = readFileSync(join(__dirname, 'GestionaleHeader.vue'), 'utf8');
const resto = readFileSync(join(__dirname, 'AppHeader.vue'), 'utf8');

/** Le larghezze delle lettere di un file `woff` (le tabelle `head`, `hhea`, `hmtx`, `cmap` formato 4). */
function carattere(file: string): (testo: string, px: number) => number {
    const b = readFileSync(file);
    const tabelle: Record<string, Buffer> = {};
    for (let i = 0; i < b.readUInt16BE(12); i++) {
        const o = 44 + i * 20;
        const [inizio, compressa, intera] = [b.readUInt32BE(o + 4), b.readUInt32BE(o + 8), b.readUInt32BE(o + 12)];
        const dati = b.subarray(inizio, inizio + compressa);
        tabelle[b.toString('ascii', o, o + 4)] = compressa < intera ? inflateSync(dati) : dati;
    }
    const { head, hhea, hmtx, cmap } = tabelle;
    const unita = head.readUInt16BE(18);
    const metriche = hhea.readUInt16BE(34);
    let sotto = 0;
    for (let i = 0; i < cmap.readUInt16BE(2); i++) {
        const posto = cmap.readUInt32BE(8 + i * 8);
        if (cmap.readUInt16BE(4 + i * 8) === 3 && cmap.readUInt16BE(posto) === 4) sotto = posto;
    }
    const segmenti = cmap.readUInt16BE(sotto + 6) / 2;
    const glifo = (c: number): number => {
        for (let i = 0; i < segmenti; i++) {
            if (c > cmap.readUInt16BE(sotto + 14 + i * 2)) continue;
            const inizio = cmap.readUInt16BE(sotto + 16 + segmenti * 2 + i * 2);
            if (c < inizio) return 0;
            const delta = cmap.readInt16BE(sotto + 16 + segmenti * 4 + i * 2);
            const dove = sotto + 16 + segmenti * 6 + i * 2;
            const salto = cmap.readUInt16BE(dove);
            if (salto === 0) return (c + delta) & 0xffff;
            const g = cmap.readUInt16BE(dove + salto + (c - inizio) * 2);
            return g === 0 ? 0 : (g + delta) & 0xffff;
        }
        return 0;
    };
    const avanzo = (g: number) => hmtx.readUInt16BE(4 * Math.min(g, metriche - 1));

    return (testo, px) => [...testo].reduce((somma, ch) => somma + avanzo(glifo(ch.codePointAt(0) ?? 0)), 0) / unita * px;
}

const larghezza = carattere(join(radice, 'node_modules/@fontsource/instrument-sans/files/instrument-sans-latin-500-normal.woff'));
/** `px-2` → 8 pixel, `gap-0.5` → 2: la scala di Tailwind, un quarto di rem. */
const spazio = (classi: string, prefisso: string): number => {
    const m = classi.match(new RegExp(`(?:^|\\s)${prefisso.replace('-', '\\-')}-([0-9.]+)(?:\\s|$)`));
    return m ? Number(m[1]) * 4 : 0;
};
const blocco = (testo: string, da: string, a: string) => testo.slice(testo.indexOf(da), testo.indexOf(a, testo.indexOf(da)));

const voci = [...blocco(gestionale, 'const mainNavItems', '];').matchAll(/title: '([^']+)'/g)].map((m) => m[1]);
const altreVoci = (blocco(gestionale, 'const rightNavItems', '];').match(/type: 'link'/g) ?? []).length;
const classiNav = blocco(gestionale, '<!-- DESKTOP MENU -->', '<template').match(/<nav class="([^"]+)"/)?.[1] ?? '';
const voceDiretta = blocco(gestionale, '<!-- DESKTOP DIRECT LINK -->', '</Link>');
const classiVoce = voceDiretta.match(/\sclass="([^"]+)"/)?.[1] ?? '';
const classiIcona = voceDiretta.match(/<component[^>]*class="([^"]+)"/)?.[1] ?? '';

/** La riga intera: il logo, le voci con il loro spazio, e a destra le icone (36 pixel l'una, 4 fra loro), 8 di spazio e l'avatar (40). */
function rigaIntera(): number {
    const logo = 32;
    const icona = classiIcona.includes('w-4') ? 16 : 0;
    const perVoce = (testo: string) => 2 * spazio(classiVoce, 'px') + icona + spazio(classiIcona, 'mr') + larghezza(testo, 14);
    const menu = spazio(classiNav, 'ml') + voci.reduce((s, v) => s + perVoce(v), 0) + spazio(classiNav, 'gap') * (voci.length - 1);
    const destra = altreVoci * 36 + (altreVoci - 1) * 4 + 8 + 40;

    return logo + menu + destra;
}

describe('il menu in alto del gestionale (decisione 573)', () => {
    test('le dieci voci, «Assemblee» compresa, stanno su una riga a 1.280 pixel (e a 1.400, dove la barra è larga uguale)', () => {
        expect(voci).toContain('Assemblee');
        expect(voci).toHaveLength(10);
        const riga = rigaIntera();
        // La barra misura 1.280 − 32 di `px-4` = 1.248 pixel; con la barra di scorrimento 15 in meno.
        console.info(`La riga del menu del gestionale: ${riga.toFixed(1)} pixel; ci sta da una finestra di ${Math.ceil(riga + 32)} pixel, ${Math.ceil(riga + 32 + 15)} con la barra di scorrimento.`);
        expect(riga).toBeLessThanOrEqual(1280 - 32 - 15);
    });

    test('nessuna voce va su due righe', () => {
        expect(classiVoce).toContain('whitespace-nowrap');
        expect(blocco(gestionale, '<!-- DESKTOP PARENT -->', '</Button>')).toContain('whitespace-nowrap');
    });

    test('l\'icona del codice non è più nel menu in alto, né nel gestionale né nel resto del programma', () => {
        expect(blocco(gestionale, 'const rightNavItems', '];')).not.toContain('github.com');
        expect(blocco(resto, 'const rightNavItems', '].filter')).not.toContain('github.com');
        expect(gestionale).not.toContain('GitGraph');
        expect(resto).not.toContain('GitGraph');
    });
});

/** Il testo senza i blocchi `children: [ … ]` (le voci dei sottomenu), con le parentesi contate. */
function senzaSottomenu(testo: string): string {
    let fuori = '';
    let i = 0;
    while (i < testo.length) {
        const inizio = testo.indexOf('children: [', i);
        if (inizio === -1) return fuori + testo.slice(i);
        fuori += testo.slice(i, inizio);
        let livello = 0;
        let j = inizio + 'children: '.length;
        for (; j < testo.length; j++) {
            if (testo[j] === '[') livello++;
            if (testo[j] === ']' && --livello === 0) break;
        }
        i = j + 1;
    }
    return fuori;
}

/** La riga del menu del resto del programma con tutte le voci che un amministratore vede: i raggruppamenti sono pulsanti, con il loro spazio in più. */
function rigaDelResto(): { riga: number; titoli: string[] } {
    const parole = readFileSync(join(radice, 'lang/it/appHeader.php'), 'utf8');
    const voci = [...senzaSottomenu(blocco(resto, 'const mainNavItems', '\n]);')).matchAll(/type: '(link|parent)'(?: as const)?,\s*title: trans\('appHeader\.(\w+)'\)/g)]
        .map((m) => ({ gruppo: m[1] === 'parent', titolo: parole.match(new RegExp(`'${m[2]}'\\s*=>\\s*"([^"]+)"`))?.[1] ?? '' }));
    // Ogni voce: `px-3` (24), l'icona (16) con `mr-2` (8), e per un raggruppamento lo spazio del pulsante (8); fra le voci `gap-1`, prima `ml-6`.
    const menu = 24 + voci.reduce((somma, v) => somma + 24 + 16 + 8 + (v.gruppo ? 8 : 0) + larghezza(v.titolo, 14), 0) + 4 * (voci.length - 1);

    return { riga: 32 + menu + 2 * 36 + 4 + 8 + 40, titoli: voci.map((v) => v.titolo) };
}

describe('da quale larghezza compare il menu largo', () => {
    test('nel gestionale: il menu largo e i pulsanti a destra da 1.240 pixel di finestra, sotto il menu del telefono', () => {
        const testo = gestionale.replace(/\s+/g, ' ');
        expect(testo).toContain('<div class="min-[1240px]:hidden">');
        expect(testo).toContain('<AppLogo class="hidden h-6 min-[1240px]:block" />');
        expect(testo).toContain('<div class="hidden min-[1240px]:flex min-[1240px]:flex-1 h-full">');
        expect(testo).toMatch(/<div class="hidden min-\[1240px\]:flex gap-1">/);
        // Nessun interruttore rimasto a `xl` (1.280): una finestra di 1.280 con la barra di scorrimento ne ha 1.265.
        expect(testo.match(/\bxl:(hidden|flex|block)\b/g) ?? []).toEqual([]);
    });

    test('a 1.240 pixel di finestra la riga del gestionale ci sta, con 8 pixel di margine per gli arrotondamenti del browser', () => {
        console.info(`A 1.240 pixel la barra misura ${1240 - 32} pixel; la riga ${rigaIntera().toFixed(1)}.`);
        expect(rigaIntera()).toBeLessThanOrEqual(1240 - 32 - 8);
    });

    test('il resto del programma resta a `xl`: con tutte le voci di un amministratore a 1.240 pixel non ci starebbe', () => {
        const { riga, titoli } = rigaDelResto();
        console.info(`Il menu del resto del programma: ${titoli.join(', ')}; la riga ${riga.toFixed(1)} pixel.`);
        expect(titoli).toEqual(expect.arrayContaining(['Segnalazioni', 'Allegati', 'Commenti']));
        expect(riga).toBeGreaterThan(1240 - 32);
        expect(resto.replace(/\s+/g, ' ')).toContain('<div class="hidden xl:flex xl:flex-1 h-full">');
    });
});

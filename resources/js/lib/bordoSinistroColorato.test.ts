// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

/**
 * Niente bordo spesso colorato a sinistra nelle card e nelle righe (`border-l-4`, `border-l-[3px]`, `border-l-2` con un colore, e simili): il colore resta
 * dove dice qualcosa, la data e la scritta nel loro colore, lo sfondo appena tinto, il bordo sottile di tutte le card.
 *
 * La guardia legge i file sotto `resources/js` con l'intestazione della licenza (`SPDX-License-Identifier`): le pagine senza l'intestazione si sistemano
 * quando le si tocca, e allora prendono anche l'intestazione. Due regole, fuori dai commenti:
 *
 * - nessun colore del solo lato sinistro (`border-l-red-500`, `dark:border-l-amber-400`, `border-s-primary`…), nemmeno scritto in una funzione che dà le
 *   classi a una riga;
 * - un bordo sinistro spesso (`border-l-2`, `border-l-4`, `border-l-8`, `border-l-[3px]`, `border-s-…`) solo come filetto grigio: sulla stessa riga c'è
 *   il suo grigio (`border-slate-300`…), nessun colore e non il bordo intero della card (`border`), che con il lato sinistro più spesso è la striscia da
 *   togliere qualunque sia il suo colore (spesso arriva da un'altra riga, `:class`). Il filetto grigio di una citazione o di un elenco annidato resta.
 *
 * Le due regole valgono riga per riga e per elemento intero: un tag scritto su più righe si legge unito, così il colore che arriva da un `:class` sulla riga
 * dopo conta. E un bordo sinistro di 2 pixel o più scritto nello stile (`border-left: 4px …`, `borderLeft: '4px …'`, `borderLeftWidth`) è la stessa
 * striscia: il colore lo dicono le classi, mai lo stile.
 *
 * Fuori dalla regola, scritto qui sotto con il perché: il filetto accanto al titolo di ogni pagina, che non è una card né una riga.
 */

const RADICE = join(__dirname, '..');

const ECCEZIONI: Record<string, string> = {
    'components/PageHeaderGuide.vue': 'il filetto accanto al titolo della pagina, in ogni pagina: non è una card né una riga',
};

const COLORI = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|primary|destructive|secondary|accent';
const GRIGI = 'slate|gray|zinc|neutral|stone';
const LATO_COLORATO = new RegExp(`(^|[\\s"'\`\\[:])border-[ls]-(${COLORI})(\\b|-)`);
const LATO_SPESSO = /(^|[\s"'`\[:])border-[ls]-(\d+|\[[^\]]+\])(?=[\s"'`\]]|$)/;
const COLORE_DEL_BORDO = new RegExp(`(^|[\\s"'\`\\[:])border-(?:[trblxyse]-)?(${COLORI})(\\b|-)`);
const GRIGIO_DEL_BORDO = new RegExp(`(^|[\\s"'\`\\[:])border-(?:[ls]-)?(${GRIGI})-`);
const BORDO_INTERO = /(^|[\s"'`])border(?=[\s"'`]|$)/;
/** Un bordo sinistro di 2 pixel o più nello stile, scritto come CSS o come oggetto di Vue. */
const LATO_SPESSO_NELLO_STILE = /border-?left(-width)?\s*:\s*['"`]?\s*([2-9]|\d{2,})px|borderLeft(Width)?\s*:\s*['"`]?\s*([2-9]|\d{2,})px/i;

/** Il sorgente senza i commenti: quelli HTML, i blocchi `/* … *\/` e le righe `//` (non `https://`). */
function senzaCommenti(sorgente: string): string {
    return sorgente
        .replace(/<!--[\s\S]*?-->/g, (c) => c.replace(/[^\n]/g, ' '))
        .replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' '))
        .replace(/(^|\s)\/\/.*$/gm, '$1');
}

/** Un pezzo di sorgente (una riga, o un elemento intero) con un bordo sinistro colorato, spesso e non grigio, o spesso nello stile. */
function haIlBordoColorato(testo: string): boolean {
    const colorato = LATO_COLORATO.test(testo);
    const spessoNonGrigio = LATO_SPESSO.test(testo) && (COLORE_DEL_BORDO.test(testo) || BORDO_INTERO.test(testo) || !GRIGIO_DEL_BORDO.test(testo));

    return colorato || spessoNonGrigio || LATO_SPESSO_NELLO_STILE.test(testo);
}

/** Le righe (numerate da 1) con un bordo sinistro colorato o spesso e non grigio: riga per riga, e per elemento intero (la riga dove l'elemento comincia). */
export function righeConIlBordoColorato(sorgente: string): number[] {
    const testo = senzaCommenti(sorgente);
    const righe = new Set(testo.split('\n').flatMap((riga, i) => (haIlBordoColorato(riga) ? [i + 1] : [])));
    for (const m of testo.matchAll(/<[A-Za-z][^<>]*>/g)) {
        if (m[0].includes('\n') && haIlBordoColorato(m[0].replace(/\s*\n\s*/g, ' '))) righe.add(testo.slice(0, m.index).split('\n').length);
    }

    return [...righe].sort((a, b) => a - b);
}

function fileDelCodice(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return fileDelCodice(percorso);

        return (nome.endsWith('.vue') || nome.endsWith('.ts')) && !nome.endsWith('.test.ts') && !nome.endsWith('.d.ts') ? [percorso] : [];
    });
}

const conLIntestazione = () => fileDelCodice(RADICE)
    .map((f) => ({ file: relative(RADICE, f).split('\\').join('/'), sorgente: readFileSync(f, 'utf8') }))
    .filter((f) => f.sorgente.includes('SPDX-License-Identifier'));

describe('niente bordo spesso colorato a sinistra', () => {
    test('nessun file con l\'intestazione della licenza ha un bordo sinistro colorato o spesso e non grigio', () => {
        const trovati = conLIntestazione()
            .filter((f) => !(f.file in ECCEZIONI))
            .flatMap((f) => righeConIlBordoColorato(f.sorgente).map((n) => `${f.file}:${n}`));
        expect(trovati, 'Il colore lo dicono la data, la scritta e lo sfondo; il bordo è quello sottile di tutte le card.').toEqual([]);
    });

    test('ogni eccezione c\'è ancora e ha ancora il bordo: chi lo perde esce dall\'elenco; la guardia legge davvero i file', () => {
        const file = conLIntestazione();
        for (const eccezione of Object.keys(ECCEZIONI)) {
            const f = file.find((x) => x.file === eccezione);
            expect(f, eccezione).toBeDefined();
            expect(righeConIlBordoColorato(f!.sorgente).length, eccezione).toBeGreaterThan(0);
        }
        expect(file.length).toBeGreaterThan(20);
    });

    test('la guardia morde: il bordo colorato e il bordo spesso non grigio sì; il grigio, il bordo sottile e i commenti no', () => {
        expect(righeConIlBordoColorato('<li class="rounded-lg border border-l-4 border-slate-200" :class="colore">')).toEqual([1]);
        expect(righeConIlBordoColorato('<div class="border border-l-[3px] border-slate-200">\n<div :class="\'border-l-red-500\'">')).toEqual([1, 2]);
        expect(righeConIlBordoColorato('<li class="rounded-lg border border-l-4 border-slate-200">')).toEqual([1]);
        expect(righeConIlBordoColorato("return { bordo: 'border-l-amber-500' };")).toEqual([1]);
        expect(righeConIlBordoColorato('<p class="pl-2 border-l-2 border-amber-300">')).toEqual([1]);
        expect(righeConIlBordoColorato('<p class="dark:border-l-emerald-400">')).toEqual([1]);
        expect(righeConIlBordoColorato('<div class="pl-5 border-l-2 border-primary">')).toEqual([1]);
        expect(righeConIlBordoColorato('<blockquote class="border-l-4 border-slate-300 dark:border-slate-600 pl-3">')).toEqual([]);
        expect(righeConIlBordoColorato('<div class="rounded-lg border border-slate-200 bg-red-50/40">')).toEqual([]);
        expect(righeConIlBordoColorato('<button class="rounded-l-none border-l border-primary-foreground/30">')).toEqual([]);
        expect(righeConIlBordoColorato('<!-- niente border-l-4 border-red-500 -->\n// border-l-red-500\n/* border-l-4 */')).toEqual([]);
        expect(righeConIlBordoColorato('<div class="h-5 w-0.5 bg-amber-500">')).toEqual([]);
    });

    test('un elemento si legge intero: il bordo spesso grigio colorato da un :class sulla riga dopo, e un bordo sinistro nello stile, sono la striscia', () => {
        expect(righeConIlBordoColorato('<div class="pl-3 border-l-4 border-slate-200"\n        :class="\'border-red-500\'"></div>')).toEqual([1]);
        // La riga 2 da sola (spesso senza il suo grigio), e l'elemento intero dalla riga 1.
        expect(righeConIlBordoColorato('<div\n    class="pl-3 border-l-4"\n    :class="attiva ? \'border-amber-500\' : \'border-slate-200\'">')).toEqual([1, 2]);
        expect(righeConIlBordoColorato('<div class="pl-3" :style="{ borderLeft: \'4px solid #ef4444\' }"></div>')).toEqual([1]);
        expect(righeConIlBordoColorato('<div style="border-left: 3px solid red">')).toEqual([1]);
        expect(righeConIlBordoColorato('const stile = { borderLeftWidth: \'2px\' };')).toEqual([1]);
        // Il filetto grigio di una citazione su più righe resta; un bordo sinistro di un pixel nello stile non è spesso.
        expect(righeConIlBordoColorato('<blockquote\n    class="border-l-4 border-slate-300 dark:border-slate-600 pl-3">')).toEqual([]);
        expect(righeConIlBordoColorato('<div style="border-left: 1px solid #e2e8f0">')).toEqual([]);
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Nel «già versato» (l'elenco, la pagina di una voce, la finestra «Dove sono questi soldi?» e la guida) nessun testo è sotto i 12 px: né con le classi di
 * Tailwind (`text-[10px]`, `text-[0.7rem]`), né con `font-size` scritto a mano. E l'elenco e la pagina di una voce hanno il tema scuro: ogni fondo chiaro e
 * ogni testo scuro ha il suo corrispondente `dark:` nella stessa stringa di classi (la resa si guarda a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const FILE = [
    'resources/js/pages/gestionale/contributi/ContributiList.vue',
    'resources/js/pages/gestionale/contributi/ContributiEdit.vue',
    'resources/js/components/gestionale/contributi/ModalLiquiditaGiaVersato.vue',
    'resources/js/components/guides/GiaVersatoGuide.vue',
];

/** Le misure scritte nel sorgente sotto i 12 px (0,75 rem). */
function sottoDodici(sorgente: string): string[] {
    const trovate: string[] = [];
    for (const m of sorgente.matchAll(/text-\[(\d+(?:\.\d+)?)(px|rem|em)\]|font-size:\s*(\d+(?:\.\d+)?)(px|rem|em)/g)) {
        const valore = Number(m[1] ?? m[3]);
        const unita = m[2] ?? m[4];
        if ((unita === 'px' && valore < 12) || (unita !== 'px' && valore < 0.75)) trovate.push(m[0]);
    }

    return trovate;
}

describe('i testi del già versato', () => {
    test.each(FILE)('%s non ha testi sotto i 12 px', (file) => {
        expect(sottoDodici(readFileSync(resolve(process.cwd(), file), 'utf8'))).toEqual([]);
    });

    test('la guardia morde: 11 px e 0,7 rem sì; 12 px, 13 px e 0,75 rem no', () => {
        expect(sottoDodici('class="text-[11px]" style="font-size: 0.7rem"')).toEqual(['text-[11px]', 'font-size: 0.7rem']);
        expect(sottoDodici('text-[12px] text-[13px] font-size: 0.75rem; text-xs')).toEqual([]);
    });
});

/** Le stringhe di classi con un fondo chiaro o un testo scuro e senza il corrispondente `dark:`. */
function senzaTemaScuro(sorgente: string): string[] {
    // Di un `:class` con le scelte fra apici si guarda ogni scelta, non l'espressione intera.
    const stringhe = [...sorgente.matchAll(/"([^"\n]*)"|'([^'\n]*)'/g)].flatMap((m) => {
        const intera = m[1] ?? m[2];
        const dentro = [...intera.matchAll(/'([^']*)'/g)].map((x) => x[1]);

        return dentro.length ? dentro : [intera];
    });
    // `bg-white/5` è un velo sul riquadro scuro, non un fondo bianco.
    const fondo = /(?<![\w:-])(bg-white(?![\w/-])|(bg-(slate|emerald|blue|amber|rose|indigo)-(50|100)|from-indigo-50)(?![\w-]))/;
    const testo = /(?<![\w:-])text-slate-(600|700|800|900)(?![\w-])/;

    return stringhe.filter((c) => (fondo.test(c) && !/dark:(bg|from)-/.test(c)) || (testo.test(c) && !/dark:text-/.test(c)));
}

describe('il tema scuro del già versato', () => {
    test.each(FILE.slice(0, 2))('%s non lascia fondi chiari né testi scuri senza il tema scuro', (file) => {
        expect(senzaTemaScuro(readFileSync(resolve(process.cwd(), file), 'utf8'))).toEqual([]);
    });

    test('la guardia morde: un riquadro bianco e un testo scuro senza `dark:` sì, con `dark:` no, `hover:` non conta', () => {
        expect(senzaTemaScuro('class="bg-white border" :class="a ? \'text-slate-800\' : \'\'"')).toEqual(['bg-white border', 'text-slate-800']);
        expect(senzaTemaScuro('class="bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 hover:bg-slate-50"')).toEqual([]);
    });
});

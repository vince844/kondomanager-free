// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 592 (decisione 298), le due regole di `resources/css/custom.css` che valgono per tutte le tendine del programma:
 *
 * - (2) nel tema scuro il menu di `vue-select` ha lo sfondo scuro e le voci chiare, e il valore scelto è chiaro: le variabili del foglio di
 *   `vue-select` sono ridefinite su `html.dark`, che pesa più del suo `:root`; la voce sotto il dito ha il suo colore scuro;
 * - (3) dove il puntatore è un dito (`@media (pointer: coarse)`) il campo di ricerca della tendina e i campi della data sono a 16 px, perché
 *   Safari su iPhone non ingrandisca la pagina toccandoli.
 *
 * Il foglio si legge con postcss, regola per regola: l'ordine delle righe e gli spazi non contano.
 *
 * Che cosa non copre: come il browser disegna davvero le tendine (si guarda a video nei due temi), l'ingrandimento vero di Safari (lo prova
 * Vincenzo sul telefono), le tendine che hanno una regola loro più forte dentro un componente.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import postcss, { type AtRule, type Rule } from 'postcss';
import { describe, expect, test } from 'vitest';

const foglio = postcss.parse(readFileSync(join(__dirname, '../css/custom.css'), 'utf8'));

/** Le dichiarazioni (proprietà → valore) delle regole fuori da ogni `@media` che hanno quel selettore. */
function dichiarazioni(selettore: string, dentro?: (r: Rule) => boolean): Record<string, string> {
    const trovate: Record<string, string> = {};
    foglio.walkRules((regola) => {
        const selettori = regola.selectors.map((s) => s.replace(/\s+/g, ' ').trim());
        if (!selettori.includes(selettore)) return;
        if (dentro ? !dentro(regola) : regola.parent?.type === 'atrule') return;
        regola.walkDecls((d) => { trovate[d.prop] = d.important ? `${d.value} !important` : d.value; });
    });
    return trovate;
}

const nelMediaDelDito = (r: Rule) => r.parent?.type === 'atrule' && (r.parent as AtRule).name === 'media' && /pointer:\s*coarse/.test((r.parent as AtRule).params);

describe('Coda 592 (2) — le tendine nel tema scuro', () => {
    test('il menu, le voci e il valore scelto hanno i colori scuri, sulla radice del tema scuro', () => {
        const scuro = dichiarazioni('html.dark');

        for (const variabile of ['--vs-dropdown-bg', '--vs-dropdown-color', '--vs-dropdown-option-color', '--vs-selected-color', '--vs-search-input-color']) {
            expect(scuro[variabile], `manca ${variabile} su html.dark`).toBeDefined();
        }
        // Lo sfondo del menu è scuro e le voci chiare: non il bianco del foglio di `vue-select`.
        expect(scuro['--vs-dropdown-bg']).not.toMatch(/#fff|white|255 255 255/);
        expect(scuro['--vs-dropdown-option-color']).not.toBe('inherit');
    });

    test('la voce sotto il dito ha il suo colore scuro, più forte del grigio chiaro di sempre', () => {
        const voce = dichiarazioni('html.dark .vs__dropdown-option--highlight');

        expect(voce.background).toMatch(/!important$/);
        expect(voce.color).toMatch(/!important$/);
    });
});

describe('Coda 592 (3) — 16 px dove il puntatore è un dito', () => {
    test('il campo di ricerca della tendina e i campi della data sono a 16 px, solo nel `@media (pointer: coarse)`', () => {
        for (const selettore of [':root .v-select .vs__search', ':root .dp__input', ":root input[type='datetime-local']", ":root input[type='date']"]) {
            expect(dichiarazioni(selettore, nelMediaDelDito)['font-size'], `manca la misura per ${selettore}`).toBe('16px !important');
        }
        // Al computer resta la misura di prima.
        expect(dichiarazioni('.vs__search')['font-size']).toBe('0.875rem !important');
    });
});

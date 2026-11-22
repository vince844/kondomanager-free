// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Nelle assemblee nessun campo del file è quello del browser: il suo pulsante parla la lingua del browser («Choose file», «No file chosen») e ha una forma
 * sua. Si usa `CampoFile` («Scegli il file», «Nessun file scelto»), o un campo nascosto dietro un pulsante del programma. Il controllo legge i sorgenti: ogni
 * `<input type="file">` delle cartelle delle assemblee è nascosto (`hidden` o `sr-only`). Lo stesso per i giorni: il campo del browser ha la forma e la lingua
 * del browser, e nelle assemblee si usa `CampoGiorno`, il pulsante «gg/mm/aaaa» con il calendario in italiano dei filtri degli allegati.
 *
 * Che cosa non copre: le altre pagine del programma (i documenti, l'importazione), che hanno ancora campi del browser.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

const radice = join(__dirname, '..', '..', '..', '..');
const cartelle = ['resources/js/components/assemblee', 'resources/js/pages/gestionale/assemblee'];

function sorgenti(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return sorgenti(percorso);
        return nome.endsWith('.vue') ? [percorso] : [];
    });
}

describe('le caselle da spuntare delle assemblee sono quelle del programma', () => {
    test('nessuna casella del browser (`<input type="checkbox">`): si usa `Checkbox`, nera e con gli angoli arrotondati', () => {
        const scoperte: string[] = [];
        for (const file of cartelle.flatMap((c) => sorgenti(join(radice, c)))) {
            const testo = readFileSync(file, 'utf8');
            for (const m of testo.matchAll(/<input\b[^>]*?type="checkbox"[^>]*>/gs)) {
                scoperte.push(`${relative(radice, file)}:${testo.slice(0, m.index).split('\n').length}`);
            }
        }
        expect(scoperte).toEqual([]);
    });
});

describe('i giorni delle assemblee si scelgono con il calendario del programma', () => {
    test('nessun campo del giorno del browser (`<input type="date">` o `<Input type="date">`): si usa `CampoGiorno`, «gg/mm/aaaa» e il calendario in italiano', () => {
        const scoperti: string[] = [];
        for (const file of cartelle.flatMap((c) => sorgenti(join(radice, c)))) {
            const testo = readFileSync(file, 'utf8');
            // Il tag intero, anche su più righe e con «=>» dentro un attributo fra virgolette.
            for (const m of testo.matchAll(/<input\b(?:[^>"']|"[^"]*"|'[^']*')*>/gi)) {
                if (/\btype=["']date["']/i.test(m[0])) {
                    scoperti.push(`${relative(radice, file)}:${testo.slice(0, m.index).split('\n').length}`);
                }
            }
        }
        expect(scoperti).toEqual([]);
    });
});

describe('i campi del file delle assemblee parlano italiano', () => {
    test('ogni <input type="file"> è nascosto dietro un pulsante del programma', () => {
        const scoperti: string[] = [];
        for (const file of cartelle.flatMap((c) => sorgenti(join(radice, c)))) {
            const testo = readFileSync(file, 'utf8');
            for (const m of testo.matchAll(/<input\b[^>]*?type="file"[^>]*>/gs)) {
                if (!/class="[^"]*\b(hidden|sr-only)\b/.test(m[0])) {
                    scoperti.push(`${relative(radice, file)}:${testo.slice(0, m.index).split('\n').length}`);
                }
            }
        }
        expect(scoperti).toEqual([]);
    });
});

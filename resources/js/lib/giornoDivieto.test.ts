import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

/**
 * Il giorno di oggi, nel browser, non si ricava dall'ora di Greenwich (1.12.0-beta.3, decisione 217, D5). `new Date().toISOString()` tagliato
 * ai primi dieci caratteri, o diviso alla «T», dà il giorno UTC: fra le 00:00 e le 02:00 di Roma è ancora ieri, e una data proposta in un modulo
 * (l'incasso, l'emissione di una rata, una fattura) nasceva nel giorno prima, o nell'anno prima il 01/01. `toJSON()` è lo stesso giorno.
 * Le date si fanno con `resources/js/lib/giorno.ts` (`oggiLocale`, `piuGiorni`, `giornoDelCalendario`, `giornoItaliano`).
 *
 * Questo test fallisce se uno dei modi vecchi torna in un file `.vue` o `.ts` fuori dai test. Le esenzioni sono scritte qui sotto, con il perché.
 */

const RADICE = join(__dirname, '..');

/** Il file e quante volte vi è ammesso il modo vecchio, con il perché. */
const ESENZIONI: Record<string, { volte: number; perche: string }> = {
    'pages/gestionale/immobili/anagrafiche/PassaggioNew.vue': {
        volte: 1,
        perche: 'Il giorno prima di una data «AAAA-MM-GG», tutto in UTC: parte da `T00:00:00Z`, toglie un giorno con `setUTCDate` e legge il giorno UTC. Non passa dal fuso locale.',
    },
};

const MODI = [
    /toISOString\(\)\s*\.\s*(?:slice|substring)\(\s*0\s*,\s*10\s*\)/g,
    /toISOString\(\)\s*\.\s*split\(\s*['"]T['"]\s*\)/g,
    /\btoJSON\(\)/g,
];

function file(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return file(percorso);
        return /\.(vue|ts)$/.test(nome) && !/\.test\.ts$/.test(nome) && !/\.d\.ts$/.test(nome) ? [percorso] : [];
    });
}

describe('il giorno di oggi non viene dall\'ora di Greenwich', () => {
    test('nessun `toISOString()` tagliato a dieci caratteri o diviso alla «T», nessun `toJSON()`, fuori dalle esenzioni scritte', () => {
        const trovati: string[] = [];
        const conteggi: Record<string, number> = {};
        for (const percorso of file(RADICE)) {
            const nome = relative(RADICE, percorso).split('\\').join('/');
            readFileSync(percorso, 'utf8').split('\n').forEach((riga, i) => {
                // I commenti possono nominare il modo vecchio per dire perché non si usa.
                if (/^\s*(\/\/|\*|\/\*)/.test(riga)) return;
                for (const modo of MODI) {
                    for (const _ of riga.matchAll(modo)) {
                        conteggi[nome] = (conteggi[nome] ?? 0) + 1;
                        if (!ESENZIONI[nome]) trovati.push(`${nome}:${i + 1}: ${riga.trim()}`);
                    }
                }
            });
        }

        expect(trovati).toEqual([]);
        for (const [nome, { volte }] of Object.entries(ESENZIONI)) {
            expect(conteggi[nome] ?? 0, `${nome}: l'esenzione vale per ${volte} righe`).toBe(volte);
        }
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un pulsante nascosto finché il mouse non ci passa sopra (`opacity-0 group-hover:opacity-100`) deve comparire anche dove il mouse non c'è.
 * In Tailwind 4 `hover:` e `group-hover:` valgono solo con `@media (hover: hover)`: su un telefono il pulsante resta invisibile, e resta
 * toccabile, perché l'opacità non toglie il clic. Con la 1.12.0-beta.11 ci erano finiti anche «Approva» e «Mostra di nuovo» dei commenti
 * (Coda 656, trovata al telefono il 18/11/2026). Ogni classe con `opacity-0` e `group-hover:opacity-100` (anche di un gruppo con il nome,
 * `group-hover/<nome>:opacity-100`) porta anche `pointer-coarse:opacity-100`, salvo gli elementi qui sotto e quelli che il tocco non raggiunge
 * (`pointer-events-none`), che non sono pulsanti.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

const RADICE = join(__dirname);

/** Elementi che compaiono al passaggio del mouse e non sono pulsanti: si possono non vedere al telefono senza perdere niente. */
const NON_PULSANTI: Record<string, string> = {
    // la freccia che accompagna il nome di un conto, solo decorazione
    'components/gestionale/pianiDeiConti/conti/DettaglioConto.vue': 'freccia decorativa',
    // la «x» del messaggio a comparsa, che si chiude da solo e si chiude anche toccandolo
    'components/ui/toast/ToastClose.vue': 'chiusura del messaggio a comparsa',
};

function componenti(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return nome === 'node_modules' ? [] : componenti(percorso);
        return nome.endsWith('.vue') ? [percorso] : [];
    });
}

/**
 * Le righe che nascondono un elemento fino al passaggio del mouse. Si legge riga per riga, non fra virgolette: gli apostrofi del testo
 * italiano («dell'importo») confondono chi accoppia le virgolette; nei componenti la stringa delle classi sta sempre su una riga.
 */
function classiNascosteAlMouse(sorgente: string): string[] {
    return sorgente
        .split('\n')
        .filter((riga) => /(^|[\s"'`])opacity-0(?=[\s"'`]|$)/.test(riga) && /(^|[\s"'`])group-hover(\/[\w-]+)?:opacity-100(?=[\s"'`]|$)/.test(riga));
}

describe('I pulsanti che compaiono al passaggio del mouse compaiono anche sul telefono (Coda 656)', () => {
    const tutti = componenti(RADICE).map((percorso) => ({ file: relative(RADICE, percorso), sorgente: readFileSync(percorso, 'utf8') }));

    test('ci sono componenti da controllare, e i commenti fra quelli con il gruppo nascosto', () => {
        expect(tutti.length).toBeGreaterThan(100);
        const commento = tutti.find(({ file }) => file === 'components/commenti/Commento.vue');
        expect(commento && classiNascosteAlMouse(commento.sorgente)).toHaveLength(3);
    });

    test('ogni classe con opacity-0 e group-hover:opacity-100 ha anche pointer-coarse:opacity-100', () => {
        const senza = tutti
            .filter(({ file }) => !(file in NON_PULSANTI))
            .flatMap(({ file, sorgente }) =>
                classiNascosteAlMouse(sorgente)
                    .filter((riga) => !/(^|[\s"'`])pointer-coarse:opacity-100(?=[\s"'`]|$)/.test(riga))
                    // Un elemento che non riceve il tocco (`pointer-events-none`: le scritte che spiegano un pulsante spento) non è un pulsante.
                    .filter((riga) => !/(^|[\s"'`])pointer-events-none(?=[\s"'`]|$)/.test(riga))
                    .map(() => file),
            );

        expect(senza).toEqual([]);
    });

    // P5 della lente P (Fase 1-bis della beta.12): anche i gruppi con il nome (`group-hover/<nome>:opacity-100`). Con la regola di prima la guardia non
    // vedeva il pulsante «Annulla emissione» nell'intestazione della colonna di una rata (`PianiRateShow.vue`), invisibile al telefono.
    test('vede anche i gruppi con il nome: il pulsante «Annulla emissione» delle rate è fra quelli controllati', () => {
        expect(classiNascosteAlMouse('<button class="absolute opacity-0 group-hover/header:opacity-100 text-gray-400">')).toHaveLength(1);
        const piano = tutti.find(({ file }) => file === 'pages/gestionale/pianiRate/PianiRateShow.vue');
        expect(piano && classiNascosteAlMouse(piano.sorgente).length).toBeGreaterThan(0);
    });

    test('gli elementi lasciati fuori esistono ancora e hanno ancora il gruppo nascosto', () => {
        for (const file of Object.keys(NON_PULSANTI)) {
            const trovato = tutti.find((c) => c.file === file);
            expect(trovato, file).toBeDefined();
            expect(classiNascosteAlMouse(trovato!.sorgente).length, file).toBeGreaterThan(0);
        }
    });
});

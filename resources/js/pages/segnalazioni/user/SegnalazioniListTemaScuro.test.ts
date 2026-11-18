// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'elenco delle segnalazioni del portale nel tema scuro (Fase 1-bis della 1.12.0-beta.11): la card di ogni segnalazione ha le varianti scure
 * della card gemella delle comunicazioni (`comunicazioni/user/ComunicazioniList.vue`). Bianca nel tema scuro, i contatori dei commenti e degli
 * allegati (`ContatoriElenco`, `dark:text-slate-200`) vi si scrivevano chiaro su bianco.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/segnalazioni/user/SegnalazioniList.vue'), 'utf8');
const card = sorgente.match(/<article[\s\S]*?<\/article>/)![0];
const classiDi = (pezzo: string) => pezzo.match(/class="([^"]+)"/)![1].split(/\s+/);

describe('Portale, elenco delle segnalazioni: la card nel tema scuro', () => {
    test('la card ha lo sfondo e il bordo scuri, come quella delle comunicazioni', () => {
        const classi = classiDi(card.match(/<article[\s\S]*?>/)![0]);

        expect(classi).toContain('bg-white');
        expect(classi).toContain('dark:bg-slate-900');
        expect(classi).toContain('dark:border-slate-800');
    });

    test('il titolo, la descrizione, la riga «Inviata …» e le due indicazioni accanto al titolo hanno il loro colore scuro', () => {
        expect(card).toMatch(/hover:text-primary[^"]*dark:text-slate-100|dark:text-slate-100[^"]*hover:text-primary/);
        expect(classiDi(card.match(/<p class="[^"]*line-clamp-2[^"]*"/)![0])).toContain('dark:text-slate-300');
        expect(classiDi(card.match(/<div class="mt-4 flex[^"]*"/)![0])).toContain('dark:text-slate-400');
        const grigiSenzaScuro = (card.match(/class="[^"]*text-gray-500[^"]*"/g) ?? []).filter((c) => !c.includes('dark:text-'));
        expect(grigiSenzaScuro, 'Scritte grigie senza il colore scuro: ' + grigiSenzaScuro.join(', ')).toEqual([]);
    });
});

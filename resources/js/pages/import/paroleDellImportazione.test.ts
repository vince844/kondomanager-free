// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le frasi dell'importazione che non dicevano il vero (Coda 759, voci 3 e 9), lette dove vivono.
 *
 * - (3) l'esito dice dei «Uniti a esistenti» che sono stati aggiornati con i dati del file, come
 *   «Unisci» fa davvero (`LivelloSoggetti`, `fill(...)->save()`), non che nulla è stato sovrascritto;
 * - (9) l'intestazione e la guida non contano le schermate che «leggono e basta»: dicono fino a dove
 *   non entra niente in archivio, cioè fino al pulsante «Importa N record» della conferma.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';
import { guideImport } from './intestazione';

const esito = fs.readFileSync('resources/js/pages/import/ImportEsito.vue', 'utf8').replace(/\s+/g, ' ');
const guida = fs.readFileSync('resources/js/components/guides/ImportGuide.vue', 'utf8').replace(/\s+/g, ' ');
const anteprima = fs.readFileSync('resources/js/pages/import/ImportAnteprima.vue', 'utf8').replace(/\s+/g, ' ');

describe('ImportEsito — «Uniti a esistenti» (759, voce 3)', () => {
    test('non dice più «senza sovrascrivere i tuoi»: dice che li ha aggiornati con i dati del file', () => {
        expect(esito).not.toContain('senza sovrascrivere');
        expect(esito).toContain('li ho aggiornati con i dati del file, come hai scelto con «Unisci»');
    });
});

describe('Le schermate che non scrivono (759, voce 9)', () => {
    test('l\'intestazione non conta le schermate: dice fino a dove in archivio non entra niente', () => {
        const card = guideImport.find((g) => g.title === 'Niente entra finché non confermi');
        expect(card?.description).not.toMatch(/prime (tre|quattro)/);
        expect(card?.description).toContain('in archivio non entra niente');
        expect(card?.description).toContain('«Importa»');
    });

    test('la guida in pagina non conta le schermate, né nel primo punto né nelle altre strade', () => {
        expect(guida).not.toMatch(/prime (tre|quattro) schermate/);
        expect(guida).toContain('in archivio non entra niente');
    });

    test('il fatto su cui poggia: il pulsante della conferma si chiama «Importa N record»', () => {
        expect(anteprima).toContain('Importa {{ props.anteprima.totale_record }} record');
    });
});

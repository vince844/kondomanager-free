// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le tre card della guida in testa all'archivio delle assemblee, come nelle altre pagine e nel registro: che cosa c'è nell'archivio, le riunioni di carta
 * registrate a mano, la raccolta delle date scritte a mano. Tre titoli brevi, due frasi ciascuna, con le parole dell'amministratore. Lo stesso per
 * «Prepara l'assemblea»: che cosa prepari (anche i punti scritti da te, con «Aggiungi un punto»), le date e la legge, che cosa succede dopo; ogni frase ha la
 * riga di codice che la prova.
 *
 * Che cosa non copre: la pagina a video.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

describe('le card della guida dell\'archivio delle assemblee', () => {
    test('tre card, tre titoli brevi, due frasi ciascuna, nella pagina', async () => {
        const { cardDellArchivio } = await import('@/lib/assemblee/cardDellaGuida');
        const card = cardDellArchivio();
        expect(card.map((c) => c.title)).toEqual(['Che cosa c\'è', 'Le riunioni di carta', 'Le date scritte a mano']);
        expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'emerald', 'amber']);
        for (const c of card) {
            expect(c.icon).toBeTruthy();
            expect(c.title.split(' ').length).toBeLessThanOrEqual(5);
            // Due frasi: due punti fermi, l'ultimo in fondo; i puntini dentro le parole fra virgolette non contano.
            expect(c.description.replace(/«[^»]*»/g, '«»').match(/\.(\s|$)/g)?.length).toBe(2);
        }
        const pagina = readFileSync(join(__dirname, '../../pages/gestionale/assemblee/Archivio.vue'), 'utf8');
        expect(pagina).toContain(':guides="cardDellArchivio()"');
    });

    test('la sala: tre card corte, «Le presenze», «I voti», «Senza rete», due frasi ciascuna, nella pagina (decisione 660)', async () => {
        const { cardDellaSala } = await import('@/lib/assemblee/cardDellaGuida');
        const card = cardDellaSala();
        expect(card.map((c) => c.title)).toEqual(['Le presenze', 'I voti', 'Senza rete']);
        expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'emerald', 'amber']);
        for (const c of card) {
            expect(c.icon).toBeTruthy();
            expect(c.description.match(/\.(\s|$)/g)?.length).toBe(2);
        }
        const pagina = readFileSync(join(__dirname, '../../pages/gestionale/assemblee/Sala.vue'), 'utf8');
        expect(pagina).toContain(':guides="cardDellaSala()"');
    });

    test('«Prepara l\'assemblea»: tre card, tre titoli brevi, due frasi ciascuna, nella pagina; ogni frase ha la riga di codice che la prova', async () => {
        const { cardDellaPreparazione } = await import('@/lib/assemblee/cardDellaGuida');
        const card = cardDellaPreparazione();
        expect(card.map((c) => c.title)).toEqual(['Che cosa prepari qui', 'Le date e la legge', 'Dopo «Prepara l\'assemblea»']);
        expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'amber', 'emerald']);
        // I punti dell'amministratore si scrivono già qui, con «Aggiungi un punto».
        expect(card[0].description).toContain('i punti che il programma propone e quelli che scrivi tu, con «Aggiungi un punto»');
        // Dopo il salvataggio si apre «Documenti» se il primo passo è fatto, altrimenti «Date e ordine del giorno»; «Convocazione» con «Avanti» dai documenti.
        expect(card[2].description).toContain('la pagina dell\'assemblea apre «Documenti» se le date valgono e c\'è almeno un punto, altrimenti «Date e ordine del giorno»');
        expect(card[2].description).toContain('«Convocazione» con «Avanti» dai documenti');
        for (const c of card) {
            expect(c.icon).toBeTruthy();
            expect(c.title.split(' ').length).toBeLessThanOrEqual(5);
            // Due frasi; i rimandi alla legge fra parentesi («art. 1136 c. 3 c.c.») non contano.
            expect(c.description.replace(/«[^»]*»/g, '«»').replace(/\(art\.[^)]*\)/g, '()').match(/\.(\s|$)/g)?.length).toBe(2);
        }
        const pagina = readFileSync(join(__dirname, '../../pages/gestionale/assemblee/Prepara.vue'), 'utf8');
        expect(pagina).toContain(':guides="cardDellaPreparazione()"');
        // Le prove delle frasi stanno accanto, nel sorgente: «prova: file:riga «testo»».
        const sorgente = readFileSync(join(__dirname, 'cardDellaGuida.ts'), 'utf8');
        const prove = [...sorgente.matchAll(/prova: ([^\s:]+):(\d+) «([^»]+)»/g)];
        expect(prove.length).toBeGreaterThanOrEqual(6);
        const radice = join(__dirname, '../../../..');
        const mancanti = prove.filter(([, file, riga, testo]) => !(readFileSync(join(radice, file), 'utf8').split('\n')[Number(riga) - 1] ?? '')
            .replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('«Il registro dei verbali»: tre card, tre titoli brevi, due frasi ciascuna, nella pagina', async () => {
        const { cardDelRegistro } = await import('@/lib/assemblee/cardDellaGuida');
        const card = cardDelRegistro();
        expect(card.map((c) => c.title)).toEqual(['Il registro', 'La copia firmata', 'Le nomine']);
        expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'emerald', 'amber']);
        for (const c of card) {
            expect(c.icon).toBeTruthy();
            expect(c.description.replace(/«[^»]*»/g, '«»').match(/\.(\s|$)/g)?.length).toBe(2);
        }
        // Ogni nome fra virgolette basse c'è a video nella pagina.
        const pagina = readFileSync(join(__dirname, '../../pages/gestionale/assemblee/RegistroDeiVerbali.vue'), 'utf8');
        for (const nome of card.flatMap((c) => [...c.description.matchAll(/«([^»]+)»/g)].map((m) => m[1]))) expect(pagina, nome).toContain(nome);
        expect(pagina).toContain(':guides="cardDelRegistro()"');
    });

    test('«Il verbale»: tre card corte, due frasi ciascuna, nella pagina (decisione 659)', async () => {
        const { cardDelVerbale } = await import('@/lib/assemblee/cardDellaGuida');
        const card = cardDelVerbale();
        expect(card.map((c) => c.title)).toEqual(['Il testo', 'Senza rete', 'La registrazione']);
        expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'amber', 'emerald']);
        for (const c of card) {
            expect(c.icon).toBeTruthy();
            expect(c.description.length).toBeLessThanOrEqual(90);
            expect(c.description.replace(/«[^»]*»/g, '«»').match(/\.(\s|$)/g)?.length).toBe(2);
        }
        expect(card[0].description).toBe('Si scrive qui e si salva da solo. I dati della sala li mette il programma.');
        expect(card[1].description).toBe('Questo computer tiene il testo finché la rete torna. Poi si manda da solo.');
        expect(card[2].description).toBe('Il numero del registro e la copia firmata. Dopo, il testo non cambia.');
        const pagina = readFileSync(join(__dirname, '../../pages/gestionale/assemblee/Verbale.vue'), 'utf8');
        expect(pagina).toContain(':guides="cardDelVerbale()"');
    });
});

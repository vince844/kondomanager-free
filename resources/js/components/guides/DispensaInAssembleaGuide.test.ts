// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 736: le guide dicono come la dispensa dei sei mesi entra in assemblea. «Rate scadute» e la dashboard (il riquadro dei sei mesi) dicono
 * «Da portare in assemblea» e il punto che il programma propone da sé con la data entro 60 giorni; «Dispense dei sei mesi» dice da dove arriva la delibera
 * e «Registra la dispensa»; «Recupero dei crediti» il pulsante nella card «Oggi»; l'assemblea, fra i sospesi del programma, la dispensa dei sei mesi.
 * Le righe di prova nuove (`<!-- prova: file:riga «testo» -->`) ci sono, con il testo a quella riga.
 *
 * Si leggono i sorgenti senza i commenti, come a video; le guide intere hanno le loro prove.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const aVideo = (file: string) => leggi(file).replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
const GUIDE = 'resources/js/components/guides/';

describe('le guide della dispensa in assemblea', () => {
    test('«Rate scadute»: il pulsante in alto per la dispensa, quello del menu ⋯ con i sei mesi della persona, il punto del programma a 60 giorni', () => {
        const t = aVideo(`${GUIDE}RateScaduteGuide.vue`);
        expect(t).toContain('«Da portare in assemblea», in alto, scrive per la prossima assemblea il punto della dispensa dei sei mesi più vicini, senza nomi.');
        expect(t).toContain('Quando mancano 60 giorni il programma lo propone da sé.');
        expect(t).toContain('Nel menu ⋯ della riga, «Da portare in assemblea» scrive lo stesso punto con i sei mesi più vicini di quella persona: il nome non va '
            + 'nel punto, perché l\'avviso di convocazione lo stamperebbe; se serve, scrivilo nella nota del memo.');
    });

    test('la dashboard: «Da portare in assemblea» in fondo alla riga aperta dei sei mesi', () => {
        expect(aVideo(`${GUIDE}DashboardCondominioGuide.vue`)).toContain('In fondo alla riga aperta, «Da portare in assemblea» scrive per la prossima assemblea il punto '
            + 'della dispensa per i crediti di quell\'esercizio, senza nomi; con la data entro 60 giorni il programma lo propone da sé fra i punti dell\'ordine del giorno.');
    });

    test('«Dispense dei sei mesi»: da dove arriva la delibera, e «Registra la dispensa» dal seguito', () => {
        const t = aVideo(`${GUIDE}DispenseSeiMesiGuide.vue`);
        expect(t).toContain('Quando mancano 60 giorni ai sei mesi e restano crediti da riscuotere, il programma propone il punto «Dispensa dall\'agire entro sei mesi» '
            + 'fra quelli della prossima assemblea, senza nomi. Prima lo scrivi tu, con «Da portare in assemblea» nel riquadro dei sei mesi o nelle rate scadute.');
        expect(t).toContain('Approvata la dispensa, «Registra la dispensa» nel seguito della delibera apre questa pagina con la delibera e l\'esercizio già scelti.');
    });

    test('«Recupero dei crediti»: il pulsante nella card «Oggi»', () => {
        expect(aVideo(`${GUIDE}RegoleRecuperoGuide.vue`)).toContain('<strong>In assemblea.</strong> «Da portare in assemblea», nella card «Oggi», scrive per la prossima '
            + 'assemblea il punto dei criteri per gli interessi e le spese, dalla regola di oggi.');
    });

    test('l\'assemblea: fra i sospesi del programma c\'è la dispensa dei sei mesi', () => {
        expect(aVideo(`${GUIDE}AssembleaGuide.vue`)).toContain('i sospesi del programma (una spesa da ratificare, un piano senza delibera, la dispensa dei sei mesi)');
    });

    test('le righe di prova nuove ci sono, con il testo a quella riga', () => {
        const prove = ['RateScaduteGuide.vue', 'DashboardCondominioGuide.vue'].flatMap((g) => [...leggi(`${GUIDE}${g}`)
            .matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)]).filter(([, , , testo]) => testo.includes('sei_mesi'));
        expect(prove.length).toBe(2);
        const mancanti = prove.filter(([, file, riga, testo]) => !(leggi(file).split('\n')[Number(riga) - 1] ?? '').includes(testo));
        expect(mancanti.map(([p]) => p)).toEqual([]);
    });
});

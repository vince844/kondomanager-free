// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le guide a cassetto delle pagine toccate dalle lettere per PEC (1.12.0-beta.13): le rate scadute («Come partono le lettere»: i quattro canali, quali tipi
 * vanno per PEC, che cosa dice la ricevuta, che cosa dice la legge, «PEC non comunicata»), l'estratto conto (la riga della PEC nella corrispondenza, la
 * ricevuta trascinata, il termine dalla consegna) e la posta (la casella serve anche alle lettere). A schema, una voce per frase, e accanto a ogni voce, nel
 * sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene il testo. Mai «vale come una raccomandata».
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');

/** La parte della guida fra i due segni: le voci (`<li class="flex gap-2">`) e le loro prove. */
function parte(file: string, testid: string): string {
    const s = leggi(file);
    const da = s.indexOf(`data-testid="${testid}"`);
    expect(da, `${testid} in ${file}`).toBeGreaterThan(-1);

    return s.slice(da, s.indexOf('</section>', da));
}
function proveDi(sezione: string) {
    const voci = sezione.match(/<li class="flex gap-2">/g) ?? [];
    const prove = [...sezione.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
    const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
        || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);

    return { voci: voci.length, prove: prove.length, mancanti };
}
const testoDi = (sezione: string) => sezione.replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

describe('le rate scadute: «Come partono le lettere»', () => {
    const sezione = parte('resources/js/components/guides/RateScaduteGuide.vue', 'guida-come-partono');

    test('i quattro canali, i tipi per PEC, la ricevuta, la legge e la PEC non comunicata, con le parole della pagina', () => {
        const t = testoDi(sezione);
        for (const pezzo of ['Email', 'PEC dello studio', 'PEC e carta', 'Stampa per la posta', 'PDF per persona (.zip)', 'sollecito, diffida e messa in mora',
            'Trascina qui la ricevuta di consegna (.eml)', 'equivale alla notificazione per posta, salvo che la legge disponga diversamente',
            'art. 48 c. 2 del Codice dell\'amministrazione digitale', 'anche se non l\'ha letta', 'PEC non comunicata dalla persona', 'Usa la PEC per chi ce l\'ha']) {
            expect(t, pezzo).toContain(pezzo);
        }
        expect(t).not.toMatch(/vale come una raccomandata/i);
    });

    test('ogni voce ha la riga di codice che la prova, e la riga c\'è', () => {
        const p = proveDi(sezione);
        expect(p.voci).toBeGreaterThanOrEqual(8);
        expect(p.prove).toBe(p.voci);
        expect(p.mancanti).toEqual([]);
    });

    test('il paragrafo delle lettere non dice più che diffida e messa in mora si stampano soltanto', () => {
        expect(leggi('resources/js/components/guides/RateScaduteGuide.vue')).not.toContain('la diffida e la messa in mora si stampano o si');
    });
});

describe('l\'estratto conto: la lettera per PEC nella corrispondenza', () => {
    const sezione = parte('resources/js/components/guides/EstrattoContoGuide.vue', 'guida-corrispondenza-pec');

    test('il badge, la ricevuta trascinata o caricata, il file che non si legge, il termine dalla consegna, «Manda di nuovo per PEC»', () => {
        const t = testoDi(sezione);
        for (const pezzo of ['Partita', 'Consegnata', 'Non partita', 'Trascina qui la ricevuta di consegna (.eml)', 'Carica la ricevuta',
            'Il giorno e l\'ora sono quelli dell\'invio: confermali o correggili.', 'ricevuta di consegna · non si toglie', 'contati dalla consegna',
            'Manda di nuovo per PEC, con le cifre di oggi']) {
            expect(t, pezzo).toContain(pezzo);
        }
    });

    test('ogni voce ha la riga di codice che la prova, e la riga c\'è', () => {
        const p = proveDi(sezione);
        expect(p.voci).toBeGreaterThanOrEqual(5);
        expect(p.prove).toBe(p.voci);
        expect(p.mancanti).toEqual([]);
    });
});

describe('la posta: la casella PEC serve anche alle lettere', () => {
    test('le voci nuove hanno la prova', () => {
        const p = proveDi(parte('resources/js/components/guides/MailSetupGuide.vue', 'guida-pec'));
        expect(p.prove).toBeGreaterThanOrEqual(2);
        expect(p.mancanti).toEqual([]);
    });
});

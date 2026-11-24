// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le guide a cassetto delle lettere per PEC e della convocazione, nei casi che contano (1.12.0-beta.13): ciò che cambia, una voce per frase, e accanto a
 * ogni voce nuova la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`), che c'è e contiene il testo.
 *
 * - Le rate scadute: una PEC già partita oggi non parte di nuovo; dopo un collegamento caduto «Manda le altre» manda chi non è stato provato; le PEC non
 *   partite si rimandano in blocco o si stampano; l'avviso di mancata consegna; «PEC non comunicata dalla persona».
 * - L'estratto conto: l'avviso di mancata consegna sulla riga, «Stampala per la posta», la PEC ferma in partenza, la prova della consegna scritta a mano.
 * - La convocazione: «da ristampare» per ogni cambio dell'avviso, la frase anche dopo il salvataggio, «Non più fra i convocati», «dalla persona».
 * - Le fatture: «Pagata in parte per l'urgenza»; e nessuna scritta sotto i 12 pixel.
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
function parte(file: string, testid: string): string {
    const s = leggi(file);
    const da = s.indexOf(`data-testid="${testid}"`);
    expect(da, `${testid} in ${file}`).toBeGreaterThan(-1);

    return s.slice(da, s.indexOf('</section>', da));
}
const prove = (sezione: string) => [...sezione.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
const mancanti = (sezione: string) => prove(sezione).filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
    || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
const testoDi = (sezione: string) => sezione.replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

describe('le rate scadute', () => {
    const sezione = parte('resources/js/components/guides/RateScaduteGuide.vue', 'guida-come-partono');

    test('la PEC già partita oggi, «Manda le altre» dopo un collegamento caduto, le PEC non partite, la mancata consegna, «dalla persona»', () => {
        const t = testoDi(sezione);
        for (const pezzo of ['Già partita oggi alle', 'chi non è stato provato', 'Rimanda le PEC non partite', 'Stampale per la posta', 'l\'avviso di mancata consegna',
            'il termine non corre', 'PEC non comunicata dalla persona', 'Ne partono 20 per volta']) {
            expect(t, pezzo).toContain(pezzo);
        }
        expect(t).not.toContain('da lui');
        // La colonna dice la consegna, non il termine; il termine lo dicono la corrispondenza e «Sei mesi per agire».
        expect(t).not.toContain('lo dicono la corrispondenza, la colonna «Ultima lettera» e «Sei mesi per agire»');
        expect(mancanti(sezione)).toEqual([]);
    });
});

describe('l\'estratto conto', () => {
    const sezione = parte('resources/js/components/guides/EstrattoContoGuide.vue', 'guida-corrispondenza-pec');

    test('l\'avviso di mancata consegna, «Stampala per la posta», la PEC ferma in partenza, la prova della consegna scritta a mano', () => {
        const t = testoDi(sezione);
        for (const pezzo of ['Non consegnata', 'Stampala per la posta', 'si è fermata mentre la mandava', 'prova della consegna · non si toglie',
            'Allega la prova']) {
            expect(t, pezzo).toContain(pezzo);
        }
        expect(mancanti(sezione)).toEqual([]);
    });
});

describe('la convocazione', () => {
    const file = 'resources/js/components/guides/ConvocazioneGuide.vue';

    test('«da ristampare» per ogni cambio dell\'avviso, la frase anche dopo il salvataggio, «Non più fra i convocati», «dalla persona»', () => {
        const t = testoDi(leggi(file));
        for (const pezzo of ['la piattaforma, il tipo, il perimetro', 'anche nel messaggio dopo «Salva le date»', 'Non più fra i convocati',
            'PEC comunicata dalla persona il', 'la ristampa ha i dati di oggi']) {
            expect(t, pezzo).toContain(pezzo);
        }
        expect(t).not.toContain('da lui');
        expect(mancanti(leggi(file))).toEqual([]);
    });
});

describe('le fatture e la scheda della persona', () => {
    test('«Pagata in parte per l\'urgenza» fino al saldo; nessuna scritta sotto i 12 pixel nella guida delle fatture', () => {
        const s = leggi('resources/js/components/guides/FattureGuide.vue');
        expect(testoDi(s)).toContain('Pagata in parte per l\'urgenza fino al saldo');
        expect(s).not.toMatch(/text-\[(9|10|11)px\]/);
    });

    test('la pagina della fattura prende dal server la parola dello sblocco («Pagata in parte» fino al saldo)', () => {
        const pagina = leggi('resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue');
        expect(pagina).toContain('props.ratifica?.sbloccata?.parola');
        expect(pagina).not.toContain('ratifica.sbloccata.pagata ? \'Pagata\' : \'Sbloccata\'');
    });

    test('la scheda della persona dice «PEC comunicata dalla persona il», neutro', () => {
        for (const file of ['resources/js/pages/anagrafiche/AnagraficheView.vue', 'resources/js/components/assemblee/convocazione/ComeSiConvoca.vue']) {
            expect(leggi(file), file).toContain('PEC comunicata dalla persona il');
            expect(leggi(file), file).not.toContain('PEC comunicata da lui il');
        }
    });
});

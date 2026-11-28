// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Incolla da un foglio di calcolo»: che cosa capisce la lettura, riga per riga.
 *
 * Il rischio di questa funzione non è fermarsi: è **capire male in silenzio**. Un valore letto
 * mille volte più piccolo, la colonna delle scale scritta nella tabella di proprietà, o un valore
 * finito sull'«Appartamento 1» di un'altra palazzina cambiano chi paga cosa e non danno errori.
 * Per questo metà delle prove sono sui casi in cui la funzione **non** deve decidere da sola.
 */

import { describe, expect, test } from 'vitest';
import { leggiIncolla, leggiValoreIncollato } from './incollaMillesimi';

const unita = [
    { id: 1, nome: 'Appartamento 1', particella_catasto: '658', subalterno_catasto: '10' },
    { id: 2, nome: 'Appartamento 2', particella_catasto: '658', subalterno_catasto: '11' },
    { id: 3, nome: 'Box', particella_catasto: '658', subalterno_catasto: '1' },
    { id: 4, nome: 'Box', particella_catasto: '658', subalterno_catasto: '2' },
    { id: 5, nome: 'Negozio', particella_catasto: null, subalterno_catasto: null },
];

describe('leggiValoreIncollato', () => {
    test.each([
        ['125', '125'],
        ['67,455', '67.455'],
        ['1.000,50', '1000.5'],
        ['1.250', '1250'],
        ['1.250.000', '1250000'],
        [' 94,91 ', '94.91'],
        ['250‰', '250'],
        ['0', '0'],
        [',5', '0.5'],
        ['0,123456', '0.12346'],
    ])('con la virgola per i decimali «%s» si legge %s', (cella, atteso) => {
        expect(leggiValoreIncollato(cella, 'virgola')).toBe(atteso);
    });

    test.each([
        ['125', '125'],
        ['67.455', '67.455'],
        ['1,000.50', '1000.5'],
        ['1.250', '1.25'],
        ['94.91', '94.91'],
    ])('col punto per i decimali «%s» si legge %s', (cella, atteso) => {
        expect(leggiValoreIncollato(cella, 'punto')).toBe(atteso);
    });

    test.each(['', 'abc', '-5', '1,2,3', '12 mq', '1e3', '5,', '67.5', '1.25', '12.3456'])(
        'con la virgola per i decimali «%s» non è un valore: il punto delle migliaia sta ogni tre cifre',
        (cella) => {
            expect(leggiValoreIncollato(cella, 'virgola')).toBeNull();
        },
    );
});

describe('leggiIncolla: le righe', () => {
    test('due colonne copiate da un foglio: tabulazione, virgola italiana, nome senza badare a maiuscole e spazi', () => {
        const esito = leggiIncolla('Appartamento 1\t94,91\n  appartamento   2 \t95,97\n', unita);

        expect(esito.riconosciute).toBe(2);
        expect(esito.daGuardare).toBe(0);
        expect(esito.righe.map((r) => [r.immobileId, r.valore])).toEqual([[1, '94.91'], [2, '95.97']]);
    });

    test('un CSV col punto e virgola e l\'intestazione in testa: la prima riga senza numero si salta', () => {
        const esito = leggiIncolla('Unità;Millesimi\nNegozio;250\n', unita);

        expect(esito.intestazioneSaltata).toBe(true);
        expect(esito.righe).toHaveLength(1);
        expect(esito.righe[0]).toMatchObject({ riga: 2, immobileId: 5, valore: '250', esito: 'riconosciuta' });
    });

    test('l\'unità si riconosce anche dai dati catastali, particella/subalterno', () => {
        const esito = leggiIncolla('658/10;94,91\n658 / 2;3,91', unita);

        expect(esito.righe.map((r) => [r.immobileId, r.esito])).toEqual([[1, 'riconosciuta'], [4, 'riconosciuta']]);
    });

    test('senza separatori vale l\'ultimo spazio', () => {
        const esito = leggiIncolla('Appartamento 1 94,91', unita);

        expect(esito.righe[0]).toMatchObject({ immobileId: 1, valore: '94.91', esito: 'riconosciuta' });
    });

    test('un nome di più unità è ambiguo e non si indovina', () => {
        const esito = leggiIncolla('Box\t3,95', unita);

        expect(esito.righe[0]).toMatchObject({ immobileId: null, esito: 'ambigua' });
        expect(esito.riconosciute).toBe(0);
        expect(esito.daGuardare).toBe(1);
    });

    test('un\'unità che non c\'è resta da guardare, con la riga del foglio', () => {
        const esito = leggiIncolla('Appartamento 1\t10\nAppartamento 9\t20', unita);

        expect(esito.righe[1]).toMatchObject({ riga: 2, chiave: 'Appartamento 9', immobileId: null, esito: 'non_trovata' });
    });

    test('la stessa unità due volte: vale la prima, la seconda è doppia', () => {
        const esito = leggiIncolla('Negozio\t250\nnegozio\t300', unita);

        expect(esito.righe.map((r) => r.esito)).toEqual(['riconosciuta', 'doppia']);
        expect(esito.riconosciute).toBe(1);
    });

    test('un valore che non si legge non diventa zero', () => {
        const esito = leggiIncolla('Appartamento 1\t10\nNegozio\tn.d.', unita);

        expect(esito.righe[1]).toMatchObject({ valore: null, esito: 'valore_non_letto' });
    });

    test('le righe vuote non contano, e il numero di riga resta quello del foglio', () => {
        const esito = leggiIncolla('\n\nNegozio\t250\r\n\r\nAppartamento 1\t10', unita);

        expect(esito.intestazioneSaltata).toBe(false);
        expect(esito.righe.map((r) => r.riga)).toEqual([3, 5]);
    });

    test('niente incollato, niente righe e niente domande', () => {
        expect(leggiIncolla('   \n', unita)).toMatchObject({
            righe: [], riconosciute: 0, daGuardare: 0, colonnaDaScegliere: false, convenzioneDaScegliere: false,
        });
    });
});

describe('leggiIncolla: prima fra le unità già nella tabella', () => {
    // Un complesso con più palazzine: un «Appartamento 1» in ciascuna.
    const complesso = [
        { id: 11, nome: 'Appartamento 1', particella_catasto: '651', subalterno_catasto: '1' },
        { id: 12, nome: 'Appartamento 2', particella_catasto: '651', subalterno_catasto: '2' },
        { id: 21, nome: 'Appartamento 1', particella_catasto: '658', subalterno_catasto: '1' },
        { id: 22, nome: 'Appartamento 2', particella_catasto: '658', subalterno_catasto: '2' },
    ];

    test('senza unità in tabella lo stesso nome in due palazzine è ambiguo', () => {
        expect(leggiIncolla('Appartamento 1\t500', complesso).righe[0].esito).toBe('ambigua');
    });

    test('dopo «Associa in blocco» di una palazzina il nome dice una cosa sola', () => {
        const esito = leggiIncolla('Appartamento 1\t500\nAppartamento 2\t500', complesso, { inTabella: [21, 22] });

        expect(esito.righe.map((r) => [r.immobileId, r.esito])).toEqual([[21, 'riconosciuta'], [22, 'riconosciuta']]);
    });

    test('un nome che fra le unità in tabella non c\'è si cerca fra le altre', () => {
        const esito = leggiIncolla('Appartamento 2\t500', complesso, { inTabella: [11, 21] });

        expect(esito.righe[0].esito).toBe('ambigua');
        expect(leggiIncolla('651/2\t500', complesso, { inTabella: [11, 21] }).righe[0]).toMatchObject({ immobileId: 12, esito: 'riconosciuta' });
    });

    test('due unità in tabella con lo stesso nome restano ambigue: i dati catastali le distinguono', () => {
        const opzioni = { inTabella: [11, 21] };

        expect(leggiIncolla('Appartamento 1\t500', complesso, opzioni).righe[0].esito).toBe('ambigua');
        expect(leggiIncolla('658/1\t500', complesso, opzioni).righe[0]).toMatchObject({ immobileId: 21, esito: 'riconosciuta' });
    });
});

describe('leggiIncolla: quale colonna sono i valori', () => {
    const foglio = 'Unità\tProprietà\tScale\nAppartamento 1\t94,91\t120\nAppartamento 2\t95,97\t80';

    test('con più di due colonne piene non si prende l\'ultima: si chiede, e nessuna riga è letta', () => {
        const esito = leggiIncolla(foglio, unita);

        expect(esito.colonnaDaScegliere).toBe(true);
        expect(esito.righe).toEqual([]);
        expect(esito.colonne).toEqual([
            { indice: 1, intestazione: 'Proprietà', esempio: '94,91' },
            { indice: 2, intestazione: 'Scale', esempio: '120' },
        ]);
    });

    test('scelta la colonna, i valori sono i suoi', () => {
        const proprieta = leggiIncolla(foglio, unita, { colonnaValori: 1 });
        const scale = leggiIncolla(foglio, unita, { colonnaValori: 2 });

        expect(proprieta.colonnaDaScegliere).toBe(false);
        expect(proprieta.righe.map((r) => r.valore)).toEqual(['94.91', '95.97']);
        expect(scale.righe.map((r) => r.valore)).toEqual(['120', '80']);
    });

    test('una colonna che nel foglio non c\'è non vale come scelta', () => {
        expect(leggiIncolla(foglio, unita, { colonnaValori: 7 }).colonnaDaScegliere).toBe(true);
    });

    test('le colonne vuote in mezzo non contano: una sola colonna piena non chiede niente', () => {
        const esito = leggiIncolla('Negozio\t\t\t250\t', unita);

        expect(esito.colonnaDaScegliere).toBe(false);
        expect(esito.colonnaValori).toBe(3);
        expect(esito.righe[0]).toMatchObject({ immobileId: 5, valore: '250', esito: 'riconosciuta' });
    });
});

describe('leggiIncolla: il punto e la virgola si decidono su tutta la colonna', () => {
    test('un valore con la virgola: il punto degli altri è delle migliaia', () => {
        const esito = leggiIncolla('Appartamento 1\t1.250\nAppartamento 2\t95,97', unita);

        expect(esito.convenzione).toBe('virgola');
        expect(esito.righe.map((r) => r.valore)).toEqual(['1250', '95.97']);
    });

    test('nessuna virgola e un punto con decimali diversi da tre: il punto è il decimale per tutti', () => {
        const esito = leggiIncolla('Appartamento 1\t1.250\nAppartamento 2\t95.97', unita);

        expect(esito.convenzione).toBe('punto');
        expect(esito.righe.map((r) => r.valore)).toEqual(['1.25', '95.97']);
    });

    test('solo punti con tre cifre dopo: non si può sapere, si chiede e si mostrano le due letture', () => {
        const esito = leggiIncolla('Appartamento 1\t1.250\nAppartamento 2\t250', unita);

        expect(esito.convenzioneDaScegliere).toBe(true);
        expect(esito.righe).toEqual([]);
        expect(esito.dubbio).toEqual({ testo: '1.250', conLaVirgola: '1250', colPunto: '1.25' });
    });

    test('la risposta di chi incolla vale per tutta la colonna', () => {
        const testo = 'Appartamento 1\t1.250\nAppartamento 2\t250';

        expect(leggiIncolla(testo, unita, { convenzione: 'virgola' }).righe.map((r) => r.valore)).toEqual(['1250', '250']);
        expect(leggiIncolla(testo, unita, { convenzione: 'punto' }).righe.map((r) => r.valore)).toEqual(['1.25', '250']);
    });

    test('due valori che si contraddicono: si chiede', () => {
        const esito = leggiIncolla('Appartamento 1\t1.000,50\nAppartamento 2\t1,000.50', unita);

        expect(esito.convenzioneDaScegliere).toBe(true);
    });

    test('numeri interi senza separatori: niente da decidere', () => {
        const esito = leggiIncolla('Appartamento 1\t125\nAppartamento 2\t250', unita);

        expect(esito.convenzione).toBeNull();
        expect(esito.convenzioneDaScegliere).toBe(false);
        expect(esito.righe.map((r) => r.valore)).toEqual(['125', '250']);
    });

    test('la convenzione si legge sulla colonna scelta, non sulle altre', () => {
        const esito = leggiIncolla('Appartamento 1\t1.250\t3,5\nAppartamento 2\t2.500\t4,5', unita, { colonnaValori: 1 });

        expect(esito.convenzioneDaScegliere).toBe(true);
    });
});

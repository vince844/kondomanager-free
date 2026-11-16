/**
 * I conti piccoli della finestra degli addebiti: i centesimi come li scrive l'amministratore, la chiave della gestione e dell'esercizio, la data di
 * serie dentro l'esercizio scelto, il nome della natura nell'elenco dell'estratto conto.
 */
import { describe, expect, test } from 'vitest';
import { centesimiInEuro, chiaveDove, daChiaveDove, dataDiSerie, nomeNatura, riepilogoAddebiti, testoRiepilogoAddebiti } from './addebiti';

describe('gli addebiti nella pagina', () => {
    test('i centesimi nel campo: la virgola, due cifre, senza simbolo né punto delle migliaia', () => {
        expect([centesimiInEuro(6795), centesimiInEuro(339), centesimiInEuro(5), centesimiInEuro(123456)]).toEqual(['67,95', '3,39', '0,05', '1234,56']);
    });

    test('la gestione e l\'esercizio in una chiave e ritorno; una chiave vuota o sbagliata non è una scelta', () => {
        expect(chiaveDove({ gestione_id: 2, esercizio_id: 1 })).toBe('2|1');
        expect(daChiaveDove('2|1')).toEqual({ gestione_id: 2, esercizio_id: 1 });
        expect([daChiaveDove(''), daChiaveDove('2|'), daChiaveDove('x|1')]).toEqual([null, null, null]);
    });

    test('la data di serie: oggi dentro l\'esercizio; la fine se oggi è dopo; l\'inizio se oggi è prima', () => {
        expect([dataDiSerie('2026-06-10', '2026-01-01', '2026-12-31'), dataDiSerie('2027-03-20', '2026-01-01', '2026-12-31'),
            dataDiSerie('2026-12-20', '2027-01-01', '2027-12-31'), dataDiSerie('2026-12-31', '2026-01-01', '2026-12-31')])
            .toEqual(['2026-06-10', '2026-12-31', '2027-01-01', '2026-12-31']);
    });

    test('la natura: contributo, spesa di una sola unità (con la fattura), spesa per il ritardo', () => {
        expect([nomeNatura('contributo', false), nomeNatura('contributo', true), nomeNatura('accessorio', true)])
            .toEqual(['Contributo', 'Spesa di una sola unità', 'Spesa per il ritardo']);
    });
});

describe('la card «Addebiti personali» dell\'estratto conto', () => {
    test('il totale e quanti sono vengono dalla stessa lista dell\'elenco, in centesimi', () => {
        expect(riepilogoAddebiti([{ importo: 5096 }, { importo: 2500 }, { importo: 118 }])).toEqual({ totale: 7714, numero: 3 });
        expect(riepilogoAddebiti([])).toEqual({ totale: 0, numero: 0 });
    });

    test('la riga sotto la cifra: quanti sono, e che sono già nel totale addebiti; senza addebiti lo dice', () => {
        expect([testoRiepilogoAddebiti(0), testoRiepilogoAddebiti(1), testoRiepilogoAddebiti(3)])
            .toEqual(['Nessun addebito personale', '1 addebito · nel totale addebiti', '3 addebiti · nel totale addebiti']);
    });
});

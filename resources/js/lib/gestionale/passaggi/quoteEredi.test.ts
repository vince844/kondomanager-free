import { describe, expect, test } from 'vitest';
import { centesimiDiPunto, partiUguali, quoteCheNonTornano, sommaQuote } from './quoteEredi';

describe('quoteEredi — le quote degli eredi nella successione (1.11.0-beta.44)', () => {
    test('una quota digitata diventa centesimi di punto, con la virgola o con il punto', () => {
        expect(centesimiDiPunto('33,33')).toBe(3333);
        expect(centesimiDiPunto('50')).toBe(5000);
        expect(centesimiDiPunto(12.5)).toBe(1250);
        expect(centesimiDiPunto('')).toBeNull();
        expect(centesimiDiPunto('mezzo')).toBeNull();
    });

    test('parti uguali: il centesimo di punto che avanza va ai primi, e la somma è proprio la quota del defunto', () => {
        expect(partiUguali(100, 3)).toEqual(['33,34', '33,33', '33,33']);
        expect(partiUguali(50, 2)).toEqual(['25', '25']);
        expect(partiUguali('33,33', 2)).toEqual(['16,67', '16,66']);
        expect(sommaQuote(partiUguali(100, 7))).toBe(10000);
        expect(partiUguali(100, 0)).toEqual([]);
    });

    test('le quote tornano solo se sommano proprio quella del defunto, senza errori di virgola mobile', () => {
        // 0,1 + 0,2 in virgola mobile non fa 0,3: in centesimi di punto sì.
        expect(quoteCheNonTornano(['0,1', '0,2'], '0,3')).toBeNull();
        expect(quoteCheNonTornano(['60', '40'], 100)).toBeNull();
        expect(quoteCheNonTornano(['60', '30'], 100)).toBe('le quote sommano 90 %, quella del defunto è 100 %: devono coincidere');
        expect(quoteCheNonTornano(['60', ''], 100)).toBe('scrivi la quota di ogni erede');
        // Rilievo L13 della Fase 1-bis: una quota che non è un numero non è una somma che non torna.
        expect(quoteCheNonTornano(['60', 'mezzo'], 100)).toBe('la quota di ogni erede si scrive in cifre, per esempio 33,33');
    });

    test('ogni quota come la vuole il server: più di zero, al massimo due decimali, almeno 0,01 % (rilievo X12)', () => {
        // Arrotondate, 33,333 e 66,667 sommerebbero 100: il server le rifiuta, e il modulo lo dice prima.
        expect(quoteCheNonTornano(['33,333', '66,667'], 100)).toBe('la quota di ogni erede può avere al massimo due decimali e dev\'essere almeno 0,01 %');
        expect(quoteCheNonTornano(['100', '0,004'], 100)).toBe('la quota di ogni erede può avere al massimo due decimali e dev\'essere almeno 0,01 %');
        expect(quoteCheNonTornano(['100', '0'], 100)).toBe('la quota di ogni erede dev\'essere più di zero');
        expect(quoteCheNonTornano(['99,99', '0,01'], 100)).toBeNull();
    });
});

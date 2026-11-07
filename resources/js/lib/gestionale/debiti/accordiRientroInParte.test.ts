/**
 * La parte di una rata coperta solo in parte da un accordo di rientro: il programma conta un versamento su quella rata prima per la parte
 * fuori dall'accordo, e la riga aperta lo dice. Le cifre: una rata da € 500,00 con € 200,00 riconosciuti, dopo un versamento di € 100,00.
 */
import { describe, expect, test } from 'vitest';
import { FRASE_IN_PARTE, testoParteInAccordo } from './accordiRientro';

describe('la rata coperta in parte', () => {
    test('la riga aperta dice come conta un versamento su quella rata', () => {
        expect(testoParteInAccordo({ riconosciuto: 20000, in_accordo: 20000, data_accordo: '2026-02-10', fuori_dalle_lettere: true, in_parte: true }))
            .toBe(`Nell'accordo del 10/02/2026: € 200,00 (riconosciuti € 200,00); non nelle lettere proposte. ${FRASE_IN_PARTE}`);
        expect(FRASE_IN_PARTE).toBe('Un versamento su questa rata conta prima per la parte fuori dall\'accordo.');
    });
    test('una rata coperta per intero non ha la frase', () => {
        expect(testoParteInAccordo({ riconosciuto: 50000, in_accordo: 50000, data_accordo: '2026-02-10', fuori_dalle_lettere: true, in_parte: false }))
            .toBe('Nell\'accordo del 10/02/2026: € 500,00 (riconosciuti € 500,00); non nelle lettere proposte.');
    });
});

import { describe, expect, it } from 'vitest';
import { nudiInteriPossibili } from './nudiInteri';

describe('nudiInteriPossibili (decisione 62)', () => {
    it('due nudi al 50 % sotto un usufrutto del 50 %: uno dei due basta', () => {
        expect(nudiInteriPossibili([50, 50], 50)).toBe(true);
    });

    it('nudi al 75 % e al 25 % sotto un usufrutto del 50 %: nessun insieme vale proprio il 50 %', () => {
        expect(nudiInteriPossibili([75, 25], 50)).toBe(false);
    });

    it('tre figli al 33,33 / 33,33 / 33,34 sotto un usufrutto del 50 %: nessun insieme', () => {
        expect(nudiInteriPossibili(['33.33', '33.33', '33.34'], 50)).toBe(false);
    });

    it('solo una somma di due nudi vale l\'usufrutto (25 + 25 su 50, con un terzo al 75)', () => {
        expect(nudiInteriPossibili([25, 25, 75], 50)).toBe(true);
    });

    it('le quote con due decimali si sommano senza errori di arrotondamento (16,67 + 33,33 = 50, con un terzo al 75)', () => {
        expect(nudiInteriPossibili(['16.67', '33.33', '75'], '50')).toBe(true);
    });

    it('ogni nudo si conta una volta sola: 30 + 30 non fa 50, e 30 non si raddoppia in 60', () => {
        expect(nudiInteriPossibili([30, 30], 50)).toBe(false);
        expect(nudiInteriPossibili([30], 60)).toBe(false);
    });
});

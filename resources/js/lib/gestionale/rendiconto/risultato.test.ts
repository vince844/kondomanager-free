import { describe, expect, test } from 'vitest';
import { comeRisultato } from './risultato';

describe('il risultato a parole (Coda 560)', () => {
    test('zero è in pareggio, sotto zero disavanzo, sopra zero avanzo', () => {
        expect(comeRisultato(0)).toBe('in pareggio');
        expect(comeRisultato(-1)).toBe('disavanzo');
        expect(comeRisultato(1)).toBe('avanzo');
    });
});

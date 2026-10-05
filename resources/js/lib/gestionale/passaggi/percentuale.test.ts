import { describe, expect, it } from 'vitest';
import { percentualeIt } from './percentuale';

describe('percentualeIt', () => {
    it.each([
        [0.5, 'lo 0,5\u00a0%'],
        [1, "l'1\u00a0%"],
        [8, "l'8\u00a0%"],
        [11, "l'11\u00a0%"],
        [18, 'il 18\u00a0%'],
        [80, "l'80\u00a0%"],
        [100, 'il 100\u00a0%'],
        [16.66, 'il 16,66\u00a0%'],
        ['50.00', 'il 50\u00a0%'],
    ])('%s → %s', (quota, attesa) => {
        expect(percentualeIt(quota)).toBe(attesa);
    });

    it('arrotonda prima di scegliere l\'articolo', () => {
        expect(percentualeIt(79.99999)).toBe("l'80\u00a0%");
        expect(percentualeIt(0.3 + 0.6 + 0.1)).toBe("l'1\u00a0%");
    });
});

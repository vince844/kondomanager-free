import { describe, expect, it } from 'vitest';
import { lordoRigaCents, sforaBudget } from './budget';

describe('sforaBudget — «questo documento provoca uno sforo?»', () => {
    it('sfora ciò che supera il residuo, lordo contro residuo', () => {
        expect(sforaBudget(12200, 10000)).toBe(true);
        expect(sforaBudget(10000, 10000)).toBe(false);
        expect(sforaBudget(1, 0)).toBe(true);
    });

    it('a zero non sfora niente, nemmeno su un capitolo già oltre il preventivo (residuo negativo)', () => {
        expect(sforaBudget(0, -28200)).toBe(false);
        expect(sforaBudget(0, 0)).toBe(false);
        // Appena l'importo c'è, su un capitolo già sforato ogni centesimo è sforo.
        expect(sforaBudget(1, -28200)).toBe(true);
    });

    it('una nota di credito non sfora mai (Coda 122), qualunque sia il residuo', () => {
        expect(sforaBudget(6100, -50000, true)).toBe(false);
        expect(sforaBudget(-6100, -50000, true)).toBe(false);
    });

    it('la soglia è il lordo della riga, come il residuo esposto dal backend', () => {
        expect(sforaBudget(lordoRigaCents(100, 22), 12100)).toBe(true);
        expect(sforaBudget(lordoRigaCents(100, 22), 12200)).toBe(false);
    });
});

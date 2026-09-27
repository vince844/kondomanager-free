import { describe, expect, it } from 'vitest';
import { descriviMargine, lordoRigaCents, sforaBudget } from './budget';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';

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

describe('descriviMargine — come si mostra il margine che resta sul capitolo (Coda 157)', () => {
    const { euro } = useCurrencyFormatter();
    const mostra = (delta: number) => euro(delta, descriviMargine(delta).opzioni);

    it('il «+» solo quando il margine è positivo, e il tono segue il segno del numero', () => {
        expect(descriviMargine(28200).tono).toBe('positivo');
        expect(descriviMargine(1).tono).toBe('positivo');
        expect(mostra(28200)).toBe('€\u00A0+282,00');
    });

    it('su un capitolo già oltre il preventivo il margine è negativo e si mostra negativo — prima usciva «+€ -282,00» in verde', () => {
        // Condominio Test, «Manutenzione giardino»: preventivo € 1.243,00, speso € 1.525,00, documento ancora a zero.
        expect(descriviMargine(-28200).tono).toBe('negativo');
        expect(mostra(-28200)).toBe('€\u00A0-282,00');
    });

    it('a zero niente segno e tono neutro', () => {
        expect(descriviMargine(0).tono).toBe('neutro');
        expect(mostra(0)).toBe('€\u00A00,00');
    });

    it('il segno sta dopo il simbolo sia sopra sia sotto zero, come nel resto del riquadro (R19 della Fase 1-bis)', () => {
        expect(mostra(50000).indexOf('+')).toBeGreaterThan(mostra(50000).indexOf('€'));
        expect(mostra(-50000).indexOf('-')).toBeGreaterThan(mostra(-50000).indexOf('€'));
    });
});

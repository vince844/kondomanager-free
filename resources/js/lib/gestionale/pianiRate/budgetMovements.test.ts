import { describe, it, expect } from 'vitest';
import { saldoNettoMovimenti, haStornoInAttesa } from './budgetMovements';

/**
 * Coda 73: la guardia di rimozione di una voce controllava se esisteva MAI stato un movimento
 * di Sposta Spesa su quella voce, non il suo saldo netto — restava bloccata per sempre, perché
 * fino alla beta.73 non esisteva nessuno storno che potesse riportarla a zero.
 */

const mov = (source: number, dest: number, amount: number) => ({ source_conto_id: source, destination_conto_id: dest, amount });

describe('saldoNettoMovimenti — quanto una voce ha ceduto o ricevuto, al netto', () => {
    it('nessun movimento: saldo zero', () => {
        expect(saldoNettoMovimenti([], 1)).toBe(0);
    });

    it('una voce che ha solo ricevuto: saldo positivo', () => {
        expect(saldoNettoMovimenti([mov(1, 2, 8000)], 2)).toBe(8000);
    });

    it('una voce che ha solo ceduto: saldo negativo', () => {
        expect(saldoNettoMovimenti([mov(1, 2, 8000)], 1)).toBe(-8000);
    });

    it('⚠️ il caso della Coda 73: un movimento più il suo storno tornano a zero', () => {
        const movimenti = [
            mov(1, 2, 8000), // 1 cede 8000 a 2
            mov(2, 1, 8000), // storno: 2 restituisce 8000 a 1
        ];
        expect(saldoNettoMovimenti(movimenti, 1)).toBe(0);
        expect(saldoNettoMovimenti(movimenti, 2)).toBe(0);
    });

    it('uno storno parziale lascia un residuo', () => {
        const movimenti = [mov(1, 2, 8000), mov(2, 1, 3000)];
        expect(saldoNettoMovimenti(movimenti, 1)).toBe(-5000);
        expect(saldoNettoMovimenti(movimenti, 2)).toBe(5000);
    });

    it('ignora i movimenti di voci diverse', () => {
        const movimenti = [mov(1, 2, 8000), mov(3, 4, 500)];
        expect(saldoNettoMovimenti(movimenti, 1)).toBe(-8000);
        expect(saldoNettoMovimenti(movimenti, 3)).toBe(-500);
    });
});

describe('haStornoInAttesa — la voce ha ancora un vincolo da sciogliere?', () => {
    it('falso a saldo zero', () => {
        expect(haStornoInAttesa([mov(1, 2, 8000), mov(2, 1, 8000)], 1)).toBe(false);
    });

    it('vero se il saldo netto non è zero, in entrambi i versi', () => {
        expect(haStornoInAttesa([mov(1, 2, 8000)], 1)).toBe(true);
        expect(haStornoInAttesa([mov(1, 2, 8000)], 2)).toBe(true);
    });

    it('falso senza nessun movimento', () => {
        expect(haStornoInAttesa([], 1)).toBe(false);
    });
});

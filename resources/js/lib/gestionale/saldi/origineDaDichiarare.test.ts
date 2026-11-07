/**
 * «Dichiarala dai saldi» dall'elenco delle rate scadute: la pagina dei saldi apre «Dichiara l'origine» con i saldi della persona già scelti,
 * quelli a debito senza un'origine dichiarata, di tutte le sue unità.
 */
import { describe, expect, test } from 'vitest';
import { saldiDellaPersonaSenzaOrigine } from './origineDaDichiarare';

const saldo = (id: number, anagrafica_id: number | null, extra: Record<string, unknown> = {}) => ({ id, anagrafica_id, saldo_iniziale: 5000, fornitore_id: null, origine_dichiarata: null, ...extra });

describe('i saldi della persona da dichiarare', () => {
    test('i saldi a debito della persona senza origine, di tutte le unità', () => {
        const immobili = [
            { saldi: [saldo(1, 7), saldo(2, 8), saldo(3, 7, { origine_dichiarata: 'esercizio' })] },
            { saldi: [saldo(4, 7), saldo(5, 7, { saldo_iniziale: -2000 }), saldo(6, 7, { origine_dichiarata: 'non_dichiarata' })] },
        ];
        expect(saldiDellaPersonaSenzaOrigine(immobili, 7)).toEqual([1, 4, 6]);
        expect(saldiDellaPersonaSenzaOrigine(immobili, 99)).toEqual([]);
    });

    test('la persona dall\'indirizzo: un numero, altrimenti nessuna', () => {
        expect(saldiDellaPersonaSenzaOrigine([{ saldi: [saldo(1, 7)] }], Number('x'))).toEqual([]);
    });
});

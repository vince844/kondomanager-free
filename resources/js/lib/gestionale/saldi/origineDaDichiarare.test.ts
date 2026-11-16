// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Dichiarala dai saldi» dall'elenco delle rate scadute: la pagina dei saldi apre «Dichiara l'origine» con i saldi della persona già scelti,
 * quelli a debito senza un'origine dichiarata, di tutte le sue unità.
 */
import { describe, expect, test } from 'vitest';
import { saldiDellaPersonaSenzaData, saldiDellaPersonaSenzaOrigine } from './origineDaDichiarare';

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

describe('i saldi della persona senza la data dei sei mesi', () => {
    test('dal riquadro dei sei mesi («Dichiara l\'origine»): anche quelli con l\'origine «prima del primo esercizio», che non danno la data', () => {
        const immobili = [{ saldi: [saldo(1, 7, { origine_dichiarata: 'prima_del_primo' }), saldo(2, 7), saldo(3, 7, { origine_dichiarata: 'esercizio' }),
            saldo(4, 7, { origine_dichiarata: 'con_il_piano' }), saldo(5, 8, { origine_dichiarata: 'prima_del_primo' })] }];
        expect(saldiDellaPersonaSenzaData(immobili, 7)).toEqual([1, 2]);
        expect(saldiDellaPersonaSenzaOrigine(immobili, 7)).toEqual([2]);
    });
});

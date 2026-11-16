/**
 * I segnaposto degli interessi, delle spese e del tasso: la finestra delle lettere dice per quante persone `[interessi]` e `[spese]` sono «€ 0,00»,
 * solo se il testo li usa; l'anteprima dei modelli li riempie con i valori d'esempio.
 */
import { describe, expect, test } from 'vitest';
import { testiSegnapostiAZero } from './lettere';
import { riempiEsempio } from './modelli';

describe('i segnaposto delle regole del recupero', () => {
    test('«€ 0,00» detto solo per i segnaposto che il testo usa', () => {
        expect(testiSegnapostiAZero('Interessi [interessi], spese [spese].', { interessi_a_zero: 1, spese_a_zero: 3 })).toEqual([
            'Per 1 persona [interessi] è € 0,00: fra le righe della lettera non hanno interessi di mora registrati.',
            'Per 3 persone [spese] è € 0,00: fra le righe della lettera non hanno spese per il ritardo registrate.',
        ]);
        expect(testiSegnapostiAZero('Gentile [nome], [totale].', { interessi_a_zero: 2, spese_a_zero: 2 })).toEqual([]);
        expect(testiSegnapostiAZero('Interessi [interessi].', { interessi_a_zero: 0 })).toEqual([]);
    });

    test('l\'anteprima dei modelli riempie [interessi], [spese] e [tasso] con l\'esempio', () => {
        const esempio = { nome: 'Maria Bianchi', totale: '€ 180,00', termine: '15 giorni', condominio: 'Le Rose', interessi: '€ 1,18', spese: '€ 25,00', tasso: '1,60 % annuo, tasso legale' };
        expect(riempiEsempio('[interessi] e [spese] al [tasso]', esempio)).toBe('€ 1,18 e € 25,00 al 1,60 % annuo, tasso legale');
        expect(riempiEsempio('[interessi]', { nome: 'x', totale: 'y', termine: 'z', condominio: 'c' })).toBe('[interessi]');
    });
});

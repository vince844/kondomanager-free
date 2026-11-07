/**
 * Le lettere rifatte senza il conto di prima («riprova», «Invia di nuovo»): con il conto solo di oggi, o senza l'IBAN quando oggi non ce n'è uno
 * solo. Il programma non sceglie fra più conti, e lo dice.
 */
import { describe, expect, test } from 'vitest';
import { testoContiCambiati, testoRiprova } from './lettere';

describe('il conto delle lettere rifatte', () => {
    test('con il conto di oggi, o senza l\'IBAN', () => {
        expect(testoContiCambiati([{ prima: 'Banca principale', ora: 'Banca nuova' }]))
            .toBe('Rifatta con il conto «Banca nuova»: il conto «Banca principale» non è più attivo o non ha più l\'IBAN.');
        expect(testoContiCambiati([{ prima: 'Banca principale', ora: null }, { prima: 'Banca principale', ora: null }]))
            .toBe('2 lettere rifatte senza l\'IBAN: il conto «Banca principale» non è più attivo o non ha più l\'IBAN, e oggi il condominio non ne ha uno solo.');
        expect(testoContiCambiati([])).toBeNull();
    });

    test('«riprova» lo dice in coda al resto', () => {
        expect(testoRiprova({ messaggi: 1, lettere: 1, saldate: 0, conti_cambiati: [{ prima: 'Banca principale', ora: null }] }))
            .toBe('1 lettera rifatta con le cifre di oggi. Rifatta senza l\'IBAN: il conto «Banca principale» non è più attivo o non ha più l\'IBAN, e oggi il condominio non ne ha uno solo.');
    });
});

describe('«riprova» e il termine passato', () => {
    test('dice quante lettere non si rifanno perché la data del termine è passata, e che cosa fare', () => {
        expect(testoRiprova({ messaggi: 0, lettere: 0, saldate: 0, termine_passato: 1 }))
            .toBe('0 lettere rifatte con le cifre di oggi. 1 lettera non si rifà: la data del termine è passata. Preparane una nuova dall\'elenco, con un termine nuovo.');
        expect(testoRiprova({ messaggi: 2, lettere: 2, saldate: 0, termine_passato: 3 }))
            .toBe('2 lettere rifatte con le cifre di oggi. 3 lettere non si rifanno: la data del termine è passata. Preparane di nuove dall\'elenco, con un termine nuovo.');
    });
});

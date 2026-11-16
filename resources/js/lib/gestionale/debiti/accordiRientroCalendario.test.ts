/**
 * Il calendario dell'accordo di rientro nella pagina: la proposta di N rate uguali con l'ultima che prende i centesimi, i giorni ogni mese, la somma che
 * deve fare il debito riconosciuto (la stessa frase del server), le rate versate o no, i segnali della regola di decadenza con le cifre esatte, la regola.
 *
 * Le cifre, rifatte a mano: € 225,92 in tre rate, 225,92 / 3 = 75,3066… → 75,31 + 75,31 + 75,30 (225,92 − 2 × 75,31 = 75,30); l'accordo di Gabriele,
 * € 1.517,67 in sei rate, 1.517,67 / 6 = 252,945 → 252,95 × 5 + 252,92 (1.517,67 − 5 × 252,95 = 252,92); € 100,00 in tre, 33,33 + 33,33 + 33,34.
 */
import { describe, expect, test } from 'vitest';
import {
    giornoOgniMese, proponiCalendario, sommaCheNonTorna, testoCalendario, testoRataDelCalendario, testoRegola, testoSegnale, testoSegnaleBreve, testoSegnoAccordo,
} from './accordiRientro';

const accordo = { id: 1, data_accordo: '2026-07-15', fino_al: '2026-09-30', riconosciuti: 22592, oggi: 7531, invariato: false, fino_al_passato: true, versati_oltre: 0,
    autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null };
const rata = (numero: number, data: string, importo: number, versato: number, scaduta = true) =>
    ({ numero, data, importo, versato, mancano: importo - versato, versata: versato === importo, scaduta });

describe('la proposta del calendario', () => {
    test('rate uguali al centesimo più vicino, l\'ultima con il resto: 75,31 + 75,31 + 75,30; Gabriele 252,95 × 5 + 252,92', () => {
        expect(proponiCalendario(22592, 3, '2026-07-31')).toEqual([
            { data: '2026-07-31', importo: 7531 }, { data: '2026-08-31', importo: 7531 }, { data: '2026-09-30', importo: 7530 },
        ]);
        expect(proponiCalendario(151767, 6, '2026-01-31').map((r) => r.importo)).toEqual([25295, 25295, 25295, 25295, 25295, 25292]);
        expect(proponiCalendario(10000, 3, '2026-01-10').map((r) => r.importo)).toEqual([3333, 3333, 3334]);
        // Ogni proposta torna al centesimo.
        for (const [totale, n] of [[22592, 3], [151767, 6], [10000, 3], [1, 1], [7, 3], [99999, 12]]) {
            expect(proponiCalendario(totale, n, '2026-01-31').reduce((s, r) => s + r.importo, 0)).toBe(totale);
            expect(proponiCalendario(totale, n, '2026-01-31').every((r) => r.importo > 0)).toBe(true);
        }
        // Meno centesimi delle rate, o nessuna rata: nessuna proposta.
        expect(proponiCalendario(2, 3, '2026-01-31')).toEqual([]);
        expect(proponiCalendario(22592, 0, '2026-01-31')).toEqual([]);
    });

    test('i giorni ogni mese: lo stesso giorno, l\'ultimo del mese se il primo lo è, il 31 che diventa il 30 o il 28', () => {
        expect([0, 1, 2].map((m) => giornoOgniMese('2026-07-31', m))).toEqual(['2026-07-31', '2026-08-31', '2026-09-30']);
        expect([0, 1, 2].map((m) => giornoOgniMese('2026-01-30', m))).toEqual(['2026-01-30', '2026-02-28', '2026-03-30']);
        expect([0, 1].map((m) => giornoOgniMese('2026-09-30', m))).toEqual(['2026-09-30', '2026-10-31']);
        expect(giornoOgniMese('2026-11-15', 2)).toBe('2027-01-15');
        expect(giornoOgniMese('2028-01-31', 1)).toBe('2028-02-29');
    });

    test('la somma che non torna: le due cifre e la differenza, come il server', () => {
        expect(sommaCheNonTorna([7531, 7531, 7530], 22592)).toBeNull();
        expect(sommaCheNonTorna([7531, 7530, 7530], 22592))
            .toBe('La somma delle rate del calendario è € 225,91 e il debito riconosciuto è € 225,92: correggi una rata, la differenza è € 0,01.');
    });
});

describe('versata o no, i segnali, la regola', () => {
    test('le rate del calendario: versata, versati € 75,30 di € 75,31, non versata, non ancora scaduta', () => {
        expect(testoRataDelCalendario(rata(1, '2026-07-31', 7531, 7531))).toBe('versata');
        expect(testoRataDelCalendario(rata(2, '2026-08-31', 7531, 7530))).toBe('versati € 75,30 di € 75,31: mancano € 0,01');
        expect(testoRataDelCalendario(rata(3, '2026-09-30', 7530, 0))).toBe('non versata');
        expect(testoRataDelCalendario(rata(3, '2026-09-30', 7530, 0, false))).toBe('');
        expect(testoRataDelCalendario(rata(3, '2026-09-30', 7530, 1000, false))).toBe('versati € 10,00 di € 75,30');
    });

    test('a che punto è il calendario, in una riga', () => {
        const cal = [rata(1, '2026-07-31', 7531, 7531), rata(2, '2026-08-31', 7531, 7530), rata(3, '2026-09-30', 7530, 0)];
        expect(testoCalendario({ calendario: cal, rate_non_versate: 2 })).toBe('2 rate non versate');
        expect(testoCalendario({ calendario: cal.slice(0, 2), rate_non_versate: 1 })).toBe('una rata non versata');
        expect(testoCalendario({ calendario: [rata(1, '2026-07-31', 7531, 7531)], rate_non_versate: 0 })).toBe('le rate scadute sono versate');
        expect(testoCalendario({ calendario: [rata(1, '2026-07-31', 7531, 0, false)], rate_non_versate: 0 })).toBe('nessuna rata ancora scaduta');
        expect(testoCalendario({ calendario: null, rate_non_versate: null })).toBeNull();
    });

    test('i segnali dicono al passato il giorno e quanto mancava, al centesimo; non scrivono che l\'accordo è decaduto', () => {
        const giorni = testoSegnale('giorni', { il: '2026-09-16', rate: [{ numero: 2, data: '2026-08-31', importo: 7531, versato: 0 }] }, { rate: 2, giorni: 15 });
        expect(giorni).toBe('Il 16/09/2026 la condizione di decadenza scritta nell\'accordo («15 giorni dopo una rata non versata») si è verificata: '
            + 'quel giorno la rata 2 del 31/08/2026 (€ 75,31) non risultava versata.');
        const rate = testoSegnale('rate', { il: '2026-10-01', rate: [{ numero: 2, data: '2026-08-31', importo: 7531, versato: 7530 }, { numero: 3, data: '2026-09-30', importo: 7530, versato: 0 }] },
            { rate: 2, giorni: 15 });
        expect(rate).toBe('Il 01/10/2026 la condizione di decadenza scritta nell\'accordo («2 rate del calendario non versate, anche non consecutive») si è verificata: '
            + 'quel giorno la rata 2 del 31/08/2026: versati € 75,30 di € 75,31, mancavano € 0,01; la rata 3 del 30/09/2026 (€ 75,30) non risultava versata.');
        for (const t of [giorni, rate]) {
            expect(t).not.toMatch(/\b(decadut[oai]|dovut[oai]|esigibil[ei]|moros[oai])\b/i);
        }
    });

    test('un segnale che il giornale di oggi smentisce lo dice, e anche la riga breve dell\'elenco', () => {
        const s = { il: '2026-09-16', rate: [{ numero: 2, data: '2026-08-31', importo: 7531, versato: 0 }], smentito: true };
        expect(testoSegnale('giorni', s, { rate: null, giorni: 15 })).toContain('non risultava versata. Nel giornale di oggi quel giorno la condizione non c\'era '
            + '(un versamento registrato dopo con una data di prima): controlla prima di registrare la decadenza.');
        expect(testoSegnaleBreve({ segnali: { giorni: s, rate: null } })).toBe('La condizione di decadenza scritta nell\'accordo si è verificata il 16/09/2026; nel giornale di oggi quel giorno non c\'era.');
        expect(testoSegnaleBreve({ segnali: { giorni: { ...s, smentito: false }, rate: null } })).toBe('La condizione di decadenza scritta nell\'accordo si è verificata il 16/09/2026.');
    });

    test('la regola, scritta come nell\'accordo', () => {
        expect(testoRegola({ rate: 2, giorni: 15 })).toBe('Regola di decadenza: 2 rate del calendario non versate, anche non consecutive; oppure 15 giorni dopo una rata non versata.');
        expect(testoRegola({ rate: 1, giorni: null })).toBe('Regola di decadenza: 1 rata del calendario non versata.');
        expect(testoRegola({ rate: null, giorni: 1 })).toBe('Regola di decadenza: 1 giorno dopo una rata non versata.');
        expect(testoRegola({ rate: null, giorni: null })).toBe('L\'accordo non ha una regola di decadenza.');
    });

    test('il segno dice a che punto è il calendario; la parte versata oltre il capitale non dice più che il programma non registra interessi e spese', () => {
        expect(testoSegnoAccordo({ ...accordo, calendario: [rata(1, '2026-07-31', 7531, 7531), rata(2, '2026-08-31', 7531, 7530), rata(3, '2026-09-30', 7530, 0)], rate_non_versate: 2 }))
            .toBe('Accordo di rientro del 15/07/2026, fino al 30/09/2026 (data passata): riconosciuti € 225,92, oggi € 75,31. Calendario: 2 rate non versate.');
        expect(testoSegnoAccordo({ ...accordo, oggi: 0, versati_oltre: 1767 })).toBe('Accordo di rientro del 15/07/2026, fino al 30/09/2026 (data passata): riconosciuti € 225,92, oggi € 0,00. '
            + 'Versati € 17,67 oltre il capitale riconosciuto: sono andati a credito della persona.');
    });
});

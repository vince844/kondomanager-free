/**
 * Le parole e i conti della finestra del fascicolo per il legale: che cosa è dentro (la parte scelta e almeno una rata scelta fra quelle del segno), le cifre
 * delle rate scelte per esercizio, i testi delle righe. La pagina filtra con i segni che il server ha messo: nessuna seconda regola.
 */
import { describe, expect, test } from 'vitest';
import {
    NOMI_DELLE_PARTI, cifreScelte, dentro, testoCifra, testoDiCui, testoEsercizio, testoProspetto, testoRata, type EsercizioDelFascicolo,
} from './fascicoloLegale';

const esercizi: EsercizioDelFascicolo[] = [
    { chiave: 'esercizio-25', id: 25, nome: 'Esercizio 2025', data: '2026-06-30', rate: [
        { chiave: 'a', nome: 'Preventivo 2026, rata 1', unita: 'Interno A', scadenza: '2025-11-05', residuo: 10000 },
        { chiave: 'b', nome: 'Preventivo 2026, rata 2', unita: 'Interno A', scadenza: '2025-12-05', residuo: 6000 },
    ] },
    { chiave: 'senza', id: null, nome: 'Senza la data dei sei mesi', data: null, rate: [
        { chiave: 'c', nome: 'Spese di sollecito', unita: null, scadenza: '2026-04-30', residuo: 2500 },
    ] },
];

describe('che cosa è dentro', () => {
    test('la parte scelta, e almeno una delle rate del segno; senza segno sempre', () => {
        const scelte = new Set(['a']);
        const parti = new Set(['lettere'] as const);
        expect(dentro({ parte: null, rate: null }, scelte, parti)).toBe(true);
        expect(dentro({ parte: 'lettere', rate: null }, scelte, parti)).toBe(true);
        expect(dentro({ parte: 'estratti', rate: null }, scelte, parti)).toBe(false);
        expect(dentro({ parte: null, rate: ['a', 'b'] }, scelte, parti)).toBe(true);
        expect(dentro({ parte: null, rate: ['b', 'c'] }, scelte, parti)).toBe(false);
    });

    test('le parti si chiamano con le parole della finestra', () => {
        expect(NOMI_DELLE_PARTI).toEqual({ lettere: 'Le lettere e le prove', estratti: 'Gli estratti conto', rendiconto: 'Il rendiconto approvato e il verbale',
            accordo: 'L\'accordo di rientro' });
    });
});

describe('le cifre delle rate scelte', () => {
    test('il totale e i «di cui» per esercizio, solo quelli rimasti', () => {
        const c = cifreScelte(esercizi, new Set(['b', 'c']));
        expect(c.totale).toBe(8500);
        expect(c.rate).toBe(2);
        expect(c.esercizi.map((e) => [e.nome, e.cifra, e.data])).toEqual([['Esercizio 2025', 6000, '2026-06-30'], ['Senza la data dei sei mesi', 2500, null]]);
        expect(cifreScelte(esercizi, new Set(['c'])).esercizi.map((e) => e.nome)).toEqual(['Senza la data dei sei mesi']);
    });

    test('i testi', () => {
        expect(testoCifra(50000, '2026-05-20')).toBe('Le rate scadute in questo fascicolo, al 20/05/2026: € 500,00');
        expect(testoDiCui({ nome: 'Esercizio 2025', cifra: 20000, data: '2026-06-30' })).toBe('di cui dell\'esercizio 2025: € 200,00 (data dei sei mesi: 30/06/2026)');
        expect(testoDiCui({ nome: 'Senza la data dei sei mesi', cifra: 2500, data: null })).toBe('di cui senza la data dei sei mesi: € 25,00');
        expect(testoProspetto(3, 124000, '2026-11-14')).toBe('Rate non versate: 3 rate, € 1.240,00 al 14/11/2026');
        expect(testoProspetto(1, 10000, '2026-11-14')).toBe('Rate non versate: 1 rata, € 100,00 al 14/11/2026');
        expect(testoEsercizio(esercizi[0], new Set(['a', 'b']))).toBe('Esercizio 2025 · 2 rate · € 160,00');
        expect(testoEsercizio(esercizi[0], new Set(['a']))).toBe('Esercizio 2025 · 1 rata su 2 · € 100,00');
        expect(testoEsercizio(esercizi[0], new Set())).toBe('Esercizio 2025 · 2 rate · € 160,00');
        expect(testoRata(esercizi[0].rate[0])).toBe('Preventivo 2026, rata 1 · scaduta il 05/11/2025 · € 100,00');
    });
});

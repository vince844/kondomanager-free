// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Due cose delle azioni consigliate, viste nella prova a video della dashboard:
 *
 * - le persone del recupero in una tabella, non dentro la frase: la frase finisce ai due punti («Le rate scadute sono di tre persone:»); sotto, una riga per
 *   persona nello stesso ordine, con il nome, le unità scritte una volta sola («Int. 1C, 1B»), le rate scadute in centesimi e l'ultima lettera («sollecito del
 *   07/11/2026», o nessuna); oltre le 8 persone, le prime con più rate scadute e una riga con quante restano e la loro cifra;
 * - «Prepara l'F24» porta alla delega della scadenza quando il server ne conosce una sola per quel giorno; altrimenti all'elenco, dove le deleghe si generano.
 */
import { describe, expect, test } from 'vitest';
import {
    azioniConsigliate, testoDelleAltre, testoDiPezzi, unitaUnaVoltaSola, type AzioneConsigliata, type DatiDelleAzioni, type PersonaDelRecupero, type Recupero,
} from './azioniConsigliate';
import type { SaldoPrevisto } from './saldoPrevisto';

const giornoN = (i: number) => new Date(Date.UTC(2026, 10, 19 + i)).toISOString().slice(0, 10);

/** Trentuno giorni dal 19/11/2026: il saldo di oggi e, al giorno dato, una fattura. «Con le rate» è uguale a «Senza»: nessuna rata in scadenza. */
function sp(saldo0: number, uscite: Record<number, number> = {}): SaldoPrevisto {
    let s = saldo0;
    const giorni = [];
    for (let i = 0; i <= 30; i++) {
        const u = uscite[i] ?? 0;
        s -= u;
        giorni.push({ giorno: giornoN(i), saldo: s, con_entrate: 0, uscite: u, quante_uscite: u ? 1 : 0, voci_uscite: [], fatture: [], entrate: 0, quante_entrate: 0,
            rate_in_scadenza: 0, quante_rate_in_scadenza: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [] });
    }

    return { giorni, primoSottoZero: null, minimoCents: 0, finaleCents: s, finaleConEntrateCents: 0, rateScaduteCents: 0 };
}

const persona = (id: number, nome: string, interno: string, cifra: number, lettera: PersonaDelRecupero['lettera'] = null): PersonaDelRecupero =>
    ({ anagraficaId: id, nome, interno, cifra, lettera });

/** Le tre persone della prova a video: € 183,11 di rate scadute in tutto; oggi il conto è a −€ 3.185,69. */
const TRE: Recupero = {
    persone: [
        persona(31, 'Tobia Cecchino', 'Int. 1C, Int. 1B', 12904, { tipo: 'sollecito', data: '2026-11-07' }),
        persona(32, 'Cecilia Angeli', 'Int. 1B, Int. 1A, Int. 1C', 3388),
        persona(33, 'Danilo Allegretti', 'Int. 1A', 2019),
    ],
    servono: 3, servonoCents: 18311, quante: 3, cifra: 18311,
};

const recupero = (azioni: AzioneConsigliata[]) => azioni.find((a) => a.chiave === 'recupero')!;

describe('le persone del recupero in una tabella', () => {
    test('da solo non basta: la frase finisce a «Le rate scadute sono di tre persone:»; sotto una riga per persona, nello stesso ordine', () => {
        const a = recupero(azioniConsigliate(sp(-318569), TRE));
        expect(a.titolo).toBe('Recupera tutte le rate scadute, € 183,11, entro oggi');
        expect(testoDiPezzi(a.frase)).toBe('Da solo non basta: anche con tutte le rate scadute il conto resta sotto zero già oggi, fino a −€ 3.002,58. '
            + 'Le rate scadute sono di tre persone:');
        // La cifra resta in centesimi: la vista la scrive in euro.
        expect(a.tabella).toEqual({
            righe: [
                { anagraficaId: 31, nome: 'Tobia Cecchino', unita: 'Int. 1C, 1B', cifra: 12904, lettera: 'sollecito del 07/11/2026' },
                { anagraficaId: 32, nome: 'Cecilia Angeli', unita: 'Int. 1B, 1A, 1C', cifra: 3388, lettera: null },
                { anagraficaId: 33, nome: 'Danilo Allegretti', unita: 'Int. 1A', cifra: 2019, lettera: null },
            ],
            altre: null,
        });
        // La lettera dei solleciti resta com'è: le tre persone, spuntate.
        expect(a.pulsante).toEqual({ testo: 'Manda i solleciti', tipo: 'lettera',
            persone: [{ id: 31, nome: 'Tobia Cecchino' }, { id: 32, nome: 'Cecilia Angeli' }, { id: 33, nome: 'Danilo Allegretti' }] });
    });

    test('il recupero basta: «Basta una persona:» e «Bastano due persone:», con le sole persone che bastano', () => {
        // Saldo € 100,00, il 22/11 una fattura di € 200,00: quanto basta € 100,00. Tobia da solo lo copre.
        const una = recupero(azioniConsigliate(sp(10000, { 3: 20000 }), TRE));
        expect(testoDiPezzi(una.frase)).toBe('È quanto manca il 22/11 per non andare sotto zero. Basta una persona:');
        expect(una.tabella?.righe.map((r) => r.nome)).toEqual(['Tobia Cecchino']);
        // Quanto basta € 150,00: Tobia (€ 129,04) e Cecilia (€ 33,88).
        const due = recupero(azioniConsigliate(sp(10000, { 3: 25000 }), TRE));
        expect(testoDiPezzi(due.frase)).toBe('È quanto manca il 22/11 per non andare sotto zero. Bastano due persone:');
        expect(due.tabella?.righe.map((r) => r.nome)).toEqual(['Tobia Cecchino', 'Cecilia Angeli']);
        expect(due.tabella?.altre).toBeNull();
    });

    test('oltre le 8 persone: «Servono 12 persone:», le prime 5 con più rate scadute e una riga con le altre 7 e la loro cifra', () => {
        const persone = [1, 2, 3, 4, 5].map((n) => persona(n, `Persona ${n}`, `Int. ${n}`, 100000));
        const a = recupero(azioniConsigliate(sp(10000, { 2: 1010000 }), { persone, servono: 12, servonoCents: 1200000, quante: 20, cifra: 2000000 }));
        expect(testoDiPezzi(a.frase)).toBe('È quanto manca il 21/11 per non andare sotto zero. Servono 12 persone:');
        expect(a.tabella?.righe).toHaveLength(5);
        expect(a.tabella?.altre).toEqual({ quante: 7, cifra: 700000 });
        expect(testoDelleAltre(a.tabella!.altre!)).toBe('e altre 7 persone, € 7.000,00');
        expect(a.pulsante).toEqual({ testo: 'Apri le rate scadute', tipo: 'rate_scadute' });
    });

    test('le unità una volta sola: «Int.» non si ripete fra due interni vicini; un nome o un codice resta com\'è', () => {
        expect(unitaUnaVoltaSola('Int. 1C, Int. 1B')).toBe('Int. 1C, 1B');
        expect(unitaUnaVoltaSola('Int. 4')).toBe('Int. 4');
        expect(unitaUnaVoltaSola('Int. 1C, Int. 1B, Box 3')).toBe('Int. 1C, 1B, Box 3');
        expect(unitaUnaVoltaSola('Box 3, Int. 1C, Int. 1B')).toBe('Box 3, Int. 1C, 1B');
        // Separati da un'unità senza interno, gli interni restano scritti per intero: «1B» da solo non si capirebbe.
        expect(unitaUnaVoltaSola('Int. 1C, Box 3, Int. 1B')).toBe('Int. 1C, Box 3, Int. 1B');
        expect(unitaUnaVoltaSola('')).toBe('');
    });

    test('l\'ultima lettera: il tipo e il giorno, in minuscolo; la messa in mora per intero', () => {
        const persone = [persona(1, 'Anna Neri', 'Int. 1', 50000, { tipo: 'messa_in_mora', data: '2026-10-01' }), persona(2, 'Bruno Sala', 'Int. 2', 40000, { tipo: 'promemoria', data: '2026-11-02' })];
        const a = recupero(azioniConsigliate(sp(-80000), { persone, servono: 2, servonoCents: 90000, quante: 2, cifra: 90000 }));
        expect(a.tabella?.righe.map((r) => r.lettera)).toEqual(['messa in mora del 01/10/2026', 'promemoria del 02/11/2026']);
    });
});

/** I dati delle sei azioni in più, vuoti: nessuna regola vera. */
const DATI = (altro: Partial<DatiDelleAzioni> = {}): DatiDelleAzioni => ({ rateDaEmettere: [], bonifici: { quanti: 0, cifra: 0 }, ritenute: { scadenze: [] },
    deficit: { quanti: 0, cifra: 0 }, esercizioId: 25, conti: [], fondi: [], ...altro });
const NESSUNO: Recupero = { persone: [], servono: 0, servonoCents: 0, quante: 0, cifra: 0 };
const ritenute = (scadenze: DatiDelleAzioni['ritenute']['scadenze']) => azioniConsigliate(sp(500000), NESSUNO, DATI({ ritenute: { scadenze } }))
    .find((a) => a.chiave === 'ritenute')!;

describe('«Prepara l\'F24» e la delega della scadenza', () => {
    test('la scadenza del titolo ha la sua delega: il pulsante la porta con sé, e la parola non cambia', () => {
        const h = ritenute([{ giorno: '2026-12-16', cifra: 30000, delega: 19 }, { giorno: '2027-01-16', cifra: 12000, delega: null }]);
        expect(h.titolo).toBe('Paga per prime le ritenute, € 300,00 entro il 16/12');
        expect(h.pulsante).toEqual({ testo: 'Prepara l\'F24', tipo: 'f24', delega: 19 });
    });

    test('la scadenza del titolo senza una delega sola: l\'elenco, anche se la scadenza dopo ne ha una', () => {
        expect(ritenute([{ giorno: '2026-12-16', cifra: 30000, delega: null }, { giorno: '2027-01-16', cifra: 12000, delega: 20 }]).pulsante)
            .toEqual({ testo: 'Prepara l\'F24', tipo: 'f24' });
    });

    test('una scadenza già passata con la sua delega: il titolo dice quella, il pulsante la apre', () => {
        const h = ritenute([{ giorno: '2026-11-16', cifra: 30000, delega: 18 }, { giorno: '2026-12-16', cifra: 12000, delega: 19 }]);
        expect(h.titolo).toBe('Paga per prime le ritenute, € 300,00: la scadenza era il 16/11');
        expect(h.pulsante.delega).toBe(18);
    });

    test('più scadenze già passate: il titolo ne dice più d\'una, e il pulsante resta l\'elenco', () => {
        const h = ritenute([{ giorno: '2026-10-16', cifra: 10000, delega: 17 }, { giorno: '2026-11-16', cifra: 30000, delega: 18 }]);
        expect(h.titolo).toBe('Paga per prime le ritenute, € 400,00: le scadenze erano dal 16/10');
        expect(h.pulsante).toEqual({ testo: 'Prepara l\'F24', tipo: 'f24' });
    });

    test('un server di prima, senza la delega: l\'elenco', () => {
        expect(ritenute([{ giorno: '2026-12-16', cifra: 30000 }]).pulsante).toEqual({ testo: 'Prepara l\'F24', tipo: 'f24' });
    });
});

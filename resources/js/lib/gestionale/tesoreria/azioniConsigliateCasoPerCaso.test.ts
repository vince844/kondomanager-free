// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// Le azioni consigliate A–H caso per caso, con le cifre e i giorni rifatti a mano: ogni attesa è calcolata nel commento della prova, dal saldo del giorno
// e dai dati.
import { describe, expect, test } from 'vitest';
import { azioniConsigliate, quantoBasta, testoDelPulsante, testoDiPezzi, type DatiDelleAzioni, type PianoDaEmettere, type Recupero } from './azioniConsigliate';
import type { SaldoPrevisto } from './saldoPrevisto';

const OGGI = '2026-11-16';
const giornoN = (i: number) => new Date(Date.UTC(2026, 10, 16 + i)).toISOString().slice(0, 10);

/** Il saldo previsto di 31 giorni (oggi e i 30 dopo): il saldo «Senza le rate» si costruisce da saldo0, uscite e registrati, giorno per giorno. */
function sp(o: { saldo0: number; uscite?: Record<number, number>; rate?: Record<number, number>; registrati?: Record<number, number>; scadute?: number }): SaldoPrevisto {
    let s = o.saldo0;
    const giorni = [];
    for (let i = 0; i <= 30; i++) {
        const u = o.uscite?.[i] ?? 0;
        const reg = o.registrati?.[i] ?? 0;
        s += reg - u;
        giorni.push({
            giorno: giornoN(i), saldo: s, con_entrate: 0, uscite: u, quante_uscite: u > 0 ? 1 : 0,
            voci_uscite: u > 0 ? [{ voce: `Fornitore ${i} — n. ${i}`, importo: u }] : [],
            fatture: u > 0 ? [{ id: 100 + i, fornitore: `Fornitore ${i}`, numero: String(i), importo: u }] : [],
            entrate: 0, quante_entrate: 0, rate_in_scadenza: o.rate?.[i] ?? 0, quante_rate_in_scadenza: o.rate?.[i] ? 1 : 0,
            registrati: reg, quanti_registrati: reg !== 0 ? 1 : 0, voci_registrati: [],
        });
    }

    return { giorni, primoSottoZero: null, minimoCents: 0, finaleCents: s, finaleConEntrateCents: 0, rateScaduteCents: o.scadute ?? 0 };
}

/** Le persone del recupero come le sceglie il server: le più alte prima, finché la somma copre min(basta, tutte); fino a 8 tutte, oltre le prime 5. */
function rec(persone: Array<[string, number]>, basta: number): Recupero {
    const ordinate = persone.map(([nome, cifra], i) => ({ anagraficaId: i + 1, nome, interno: `Int. ${i + 1}`, cifra, lettera: null })).sort((a, b) => b.cifra - a.cifra || a.anagraficaId - b.anagraficaId);
    const cifra = ordinate.reduce((t, p) => t + p.cifra, 0);
    const daCoprire = Math.min(basta, cifra);
    const servono = [];
    let coperto = 0;
    for (const p of ordinate) {
        if (coperto >= daCoprire) break;
        servono.push(p);
        coperto += p.cifra;
    }
    const mostrate = servono.length <= 8 ? servono : servono.slice(0, 5);

    return { persone: mostrate, servono: servono.length, servonoCents: servono.reduce((t, p) => t + p.cifra, 0), quante: ordinate.length, cifra };
}

const NESSUNO: Recupero = { persone: [], servono: 0, servonoCents: 0, quante: 0, cifra: 0 };

function dati(d: Partial<DatiDelleAzioni> = {}): DatiDelleAzioni {
    return { rateDaEmettere: [], bonifici: { quanti: 0, cifra: 0 }, ritenute: { scadenze: [] }, deficit: { quanti: 0, cifra: 0 }, esercizioId: 9, conti: [], fondi: [], ...d };
}

const piano = (rate: Array<[number, string, number]>, quante = rate.length, cifra = rate.reduce((t, r) => t + r[2], 0)): PianoDaEmettere => ({
    pianoId: 5, piano: 'Preventivo 2026', esercizioId: 9, rate: rate.map(([numero, scadenza, c], i) => ({ rataId: 50 + i, numero, scadenza, cifra: c })), quante, cifra,
});

const chiavi = (a: ReturnType<typeof azioniConsigliate>) => a.map((x) => x.chiave);
const una = (a: ReturnType<typeof azioniConsigliate>, chiave: string) => a.find((x) => x.chiave === chiave);
const SANO = sp({ saldo0: 100000 });

describe('H — le ritenute', () => {
    test('(a) compare con la cifra e il giorno: € 17,80 entro il 16/12', () => {
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ ritenute: { scadenze: [{ giorno: '2026-12-16', cifra: 1780 }] } })), 'ritenute');
        expect(a?.titolo).toBe('Paga per prime le ritenute, € 17,80 entro il 16/12');
        expect(a?.pulsante).toEqual({ testo: 'Prepara l\'F24', tipo: 'f24' });
    });
    test('(b) non compare: nessuna scadenza, o una cifra a zero', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati()), 'ritenute')).toBeUndefined();
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ ritenute: { scadenze: [{ giorno: '2026-12-16', cifra: 0 }] } })), 'ritenute')).toBeUndefined();
    });
    test('(c) il 16 del mese: oggi è il 16/11, la scadenza di oggi è «entro il 16/11», quella di ieri è «era il 15/11»', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ ritenute: { scadenze: [{ giorno: OGGI, cifra: 30000 }] } })), 'ritenute')?.titolo)
            .toBe('Paga per prime le ritenute, € 300,00 entro il 16/11');
        // Due scadenze: ieri (scaduta) e il 16/12. Il titolo dice la scaduta, la frase la successiva.
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ ritenute: { scadenze: [{ giorno: '2026-11-15', cifra: 20000 }, { giorno: '2026-12-16', cifra: 12000 }] } })), 'ritenute');
        expect(a?.titolo).toBe('Paga per prime le ritenute, € 200,00: la scadenza era il 15/11');
        expect(testoDiPezzi(a!.frase)).toContain('Poi € 120,00 entro il 16/12.');
    });
});

describe('D — i bonifici segnalati', () => {
    test('(a) uno e due, con la somma', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ bonifici: { quanti: 1, cifra: 15337 } })), 'bonifici')?.titolo).toBe('1 bonifico segnalato, € 153,37: verificalo prima di sollecitare');
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ bonifici: { quanti: 2, cifra: 35000 } })), 'bonifici')?.titolo).toBe('2 bonifici segnalati, € 350,00: verificali prima di sollecitare');
    });
    test('(b) nessuno: nessuna azione', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati()), 'bonifici')).toBeUndefined();
    });
});

describe('C — le rate non ancora emesse', () => {
    test('(a) una rata del 30/11: € 1.000,00 entro il 30/11, il pulsante sul piano 5 dell\'esercizio 9', () => {
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ rateDaEmettere: [piano([[6, '2026-11-30', 100000]])] })), 'emissione');
        expect(a?.titolo).toBe('Emetti la rata 6 del piano «Preventivo 2026»: porterebbe € 1.000,00 entro il 30/11');
        expect(a?.pulsante).toEqual({ testo: 'Emetti la rata', tipo: 'emissione', pianoId: 5, esercizioId: 9 });
    });
    test('(c) il bordo di oggi: una rata che scade oggi «entro il 16/11», una di ieri «scadeva il 15/11»', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ rateDaEmettere: [piano([[6, OGGI, 100000]])] })), 'emissione')?.titolo)
            .toBe('Emetti la rata 6 del piano «Preventivo 2026»: porterebbe € 1.000,00 entro il 16/11');
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ rateDaEmettere: [piano([[6, '2026-11-15', 100000]])] })), 'emissione')?.titolo)
            .toBe('Emetti la rata 6 del piano «Preventivo 2026»: porterebbe € 1.000,00, e scadeva il 15/11');
    });
    test('(g) rate già scadute e una nei 30 giorni: tre rate, € 3.000,00, ognuna con «scadeva» o «scade»', () => {
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ rateDaEmettere: [piano([[4, '2026-10-05', 100000], [5, '2026-11-05', 100000], [6, '2026-11-30', 100000]])] })), 'emissione');
        expect(a?.titolo).toBe('Emetti 3 rate del piano «Preventivo 2026»: porterebbero € 3.000,00, scadono dal 05/10 al 30/11');
        expect(testoDiPezzi(a!.frase)).toBe('Non sono nel saldo previsto, che conta solo le rate emesse.');
        expect(a!.dettagli).toEqual(['Rata 4, € 1.000,00, scadeva il 05/10', 'Rata 5, € 1.000,00, scadeva il 05/11', 'Rata 6, € 1.000,00, scade il 30/11']);
    });
    test('(b) un piano con la cifra a zero non dà l\'azione', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ rateDaEmettere: [piano([[6, '2026-11-30', 0]])] })), 'emissione')).toBeUndefined();
    });
});

describe('A — il recupero', () => {
    // Saldo € 1.000,00; il 20/11 (giorno 4) una fattura di € 1.500,00: da quel giorno −€ 500,00. Nessuna rata in scadenza: «Con le rate» = «Senza».
    // Quanto basta = € 500,00, da recuperare entro il 19/11 (il giorno prima).
    const buco = sp({ saldo0: 100000, uscite: { 4: 150000 } });
    test('quanto basta, rifatto a mano: € 500,00', () => {
        expect(quantoBasta(buco)).toBe(50000);
    });
    test('(a)(c) la cifra esatta che basta: una persona con € 500,00 di rate scadute', () => {
        const a = una(azioniConsigliate(buco, rec([['Rossi', 50000]], 50000)), 'recupero');
        expect(a?.titolo).toBe('Recupera almeno € 500,00 delle rate scadute entro il 19/11');
        expect(testoDiPezzi(a!.frase)).toBe('È quanto manca il 20/11 per non andare sotto zero. Basta una persona:');
        expect(a?.tabella?.righe).toEqual([{ anagraficaId: 1, nome: 'Rossi', unita: 'Int. 1', cifra: 50000, lettera: null }]);
        expect(a?.pulsante).toEqual({ testo: 'Manda un sollecito', tipo: 'lettera', persone: [{ id: 1, nome: 'Rossi' }] });
        expect(a?.secondo).toEqual({ testo: 'Registra gli incassi', tipo: 'incassi' });
    });
    test('(c) un centesimo in meno: «da solo non basta», il conto va sotto zero il 20/11 fino a −€ 0,01', () => {
        const a = una(azioniConsigliate(buco, rec([['Rossi', 49999]], 50000)), 'recupero');
        expect(a?.titolo).toBe('Recupera tutte le rate scadute, € 499,99, entro il 19/11');
        expect(testoDiPezzi(a!.frase)).toBe('Da solo non basta: anche con tutte le rate scadute il conto va sotto zero il 20/11, fino a −€ 0,01. Le rate scadute sono di una persona:');
        expect(a?.tabella?.righe.map((r) => r.cifra)).toEqual([49999]);
    });
    test('(c) un centesimo in più: basta, e la cifra resta € 500,00', () => {
        expect(una(azioniConsigliate(buco, rec([['Rossi', 50001]], 50000)), 'recupero')?.titolo).toBe('Recupera almeno € 500,00 delle rate scadute entro il 19/11');
    });
    test('(c) i giorni: sotto zero già oggi o domani → «entro oggi»; dopodomani → «entro il 17/11»', () => {
        expect(una(azioniConsigliate(sp({ saldo0: -100 }), rec([['Rossi', 500]], 100)), 'recupero')?.titolo).toBe('Recupera almeno € 1,00 delle rate scadute entro oggi');
        expect(una(azioniConsigliate(sp({ saldo0: 0, uscite: { 1: 100 } }), rec([['Rossi', 500]], 100)), 'recupero')?.titolo).toBe('Recupera almeno € 1,00 delle rate scadute entro oggi');
        expect(una(azioniConsigliate(sp({ saldo0: 0, uscite: { 2: 100 } }), rec([['Rossi', 500]], 100)), 'recupero')?.titolo).toBe('Recupera almeno € 1,00 delle rate scadute entro il 17/11');
    });
    test('(b) non compare: il conto non va sotto zero; o va sotto zero ma non ci sono rate scadute', () => {
        expect(una(azioniConsigliate(SANO, rec([['Rossi', 50000]], 0)), 'recupero')).toBeUndefined();
        expect(una(azioniConsigliate(buco, NESSUNO), 'recupero')).toBeUndefined();
    });
    test('(b) le rate in scadenza coprono il buco: «Con le rate» resta sopra zero, nessun recupero anche se «Senza» va sotto', () => {
        // Il 20/11 −€ 500,00 senza le rate; il 18/11 (giorno 2) entrano € 600,00 di rate: «Con le rate» minimo € 100,00.
        expect(quantoBasta(sp({ saldo0: 100000, uscite: { 4: 150000 }, rate: { 2: 60000 } }))).toBe(0);
        expect(una(azioniConsigliate(sp({ saldo0: 100000, uscite: { 4: 150000 }, rate: { 2: 60000 } }), rec([['Rossi', 50000]], 0)), 'recupero')).toBeUndefined();
    });
    test('(g) da solo non basta con due persone: € 300,00 + € 100,00 su € 500,00', () => {
        const a = una(azioniConsigliate(buco, rec([['Rossi', 30000], ['Bianchi', 10000]], 50000)), 'recupero');
        expect(a?.titolo).toBe('Recupera tutte le rate scadute, € 400,00, entro il 19/11');
        expect(testoDiPezzi(a!.frase)).toContain('anche con tutte le rate scadute il conto va sotto zero il 20/11, fino a −€ 100,00.');
        expect(a?.pulsante.tipo).toBe('lettera');
        expect(a?.pulsante.testo).toBe('Manda i solleciti');
    });
    test('(g) più di 8 persone: «Apri le rate scadute», le prime 5 nella tabella e le altre contate', () => {
        // Nove persone da € 100,00; buco € 900,00: servono tutte e nove.
        const r = rec(Array.from({ length: 9 }, (_, i) => [`P${i + 1}`, 10000] as [string, number]), 90000);
        const a = una(azioniConsigliate(sp({ saldo0: 0, uscite: { 3: 90000 } }), r), 'recupero');
        expect(a?.pulsante).toEqual({ testo: 'Apri le rate scadute', tipo: 'rate_scadute' });
        expect(testoDiPezzi(a!.frase)).toMatch(/ Servono 9 persone:$/);
        expect(a?.tabella?.righe).toHaveLength(5);
        expect(a?.tabella?.altre).toEqual({ quante: 4, cifra: 40000 });
    });
});

describe('B — le fatture dopo le rate', () => {
    // Saldo € 1.000,00; il 19/11 (giorno 3) una fattura di € 1.200,00 → −€ 200,00; il 22/11 (giorno 6) entrano € 500,00 di rate → € 300,00, e resta.
    test('(a) compare: «Chiedi di pagare le fatture del 19/11 dopo il 22/11»', () => {
        const a = una(azioniConsigliate(sp({ saldo0: 100000, uscite: { 3: 120000 }, rate: { 6: 50000 } }), NESSUNO), 'fatture');
        expect(a?.titolo).toBe('Chiedi di pagare le fatture del 19/11 dopo il 22/11');
        expect(testoDiPezzi(a!.frase)).toBe('Il 22/11 entrano € 500,00 di rate: se i fornitori accettano, il conto resta sopra zero. Le fatture: Fornitore 3 € 1.200,00.');
        expect(a?.pulsante).toEqual({ testo: 'Apri la fattura', tipo: 'fattura', fatture: [103] });
    });
    test('(b) non compare: dopo le rate il conto torna sotto zero il 26/11', () => {
        expect(una(azioniConsigliate(sp({ saldo0: 100000, uscite: { 3: 120000, 10: 40000 }, rate: { 6: 50000 } }), NESSUNO), 'fatture')).toBeUndefined();
    });
    test('(c) bordo: le rate riportano il conto esattamente a zero (€ 200,00): l\'azione c\'è; un centesimo in meno no', () => {
        expect(una(azioniConsigliate(sp({ saldo0: 100000, uscite: { 3: 120000 }, rate: { 6: 20000 } }), NESSUNO), 'fatture')?.titolo).toBe('Chiedi di pagare le fatture del 19/11 dopo il 22/11');
        expect(una(azioniConsigliate(sp({ saldo0: 100000, uscite: { 3: 120000 }, rate: { 6: 19999 } }), NESSUNO), 'fatture')).toBeUndefined();
    });
});

describe('G — il giroconto', () => {
    const conti = (banca: number, contanti: number, attiva = true) => [
        { cassaId: 1, nome: 'Banca', tipo: 'banca', saldo: banca, attiva: true }, { cassaId: 2, nome: 'Contanti', tipo: 'contanti', saldo: contanti, attiva },
    ];
    test('(a)(f) la banca a −€ 300,00 e i contanti a € 500,00: «Sposta € 300,00 da Contanti a Banca», il giroconto con i due conti e la cifra', () => {
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ conti: conti(-30000, 50000) })), 'giroconto');
        expect(a?.titolo).toBe('Sposta € 300,00 da Contanti a Banca');
        expect(testoDiPezzi(a!.frase)).toBe('Oggi Banca è sotto zero di € 300,00 e Contanti ha € 500,00: con il giroconto Banca torna a zero.');
        expect(a?.pulsante).toEqual({ testo: 'Fai un giroconto', tipo: 'giroconto', origine: 2, destinazione: 1, importo: 30000 });
    });
    test('(c) il bordo: i contanti esattamente € 300,00 → torna a zero; € 299,99 → resta sotto di € 0,01', () => {
        expect(testoDiPezzi(una(azioniConsigliate(SANO, NESSUNO, dati({ conti: conti(-30000, 30000) })), 'giroconto')!.frase)).toContain('con il giroconto Banca torna a zero.');
        const a = una(azioniConsigliate(SANO, NESSUNO, dati({ conti: conti(-30000, 29999) })), 'giroconto');
        expect(a?.titolo).toBe('Sposta € 299,99 da Contanti a Banca');
        expect(testoDiPezzi(a!.frase)).toContain('è tutto quello che può dare, e Banca resta sotto zero di € 0,01.');
    });
    test('(b) non compare: nessun conto sotto zero (la banca a € 0,00); o l\'unico con denaro è disattivato', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ conti: conti(0, 50000) })), 'giroconto')).toBeUndefined();
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ conti: conti(-30000, 50000, false) })), 'giroconto')).toBeUndefined();
    });
});

describe('F — il fondo vincolato', () => {
    // «Senza le rate»: saldo € 0,00, il 21/11 (giorno 5) una fattura di € 1.000,00 → −€ 1.000,00 fino alla fine: il buco è € 1.000,00.
    const buco = sp({ saldo0: 0, uscite: { 5: 100000 } });
    const fondo = (saldo: number, sottotipo: string | null, nome = 'Fondo facciata', cassaId = 11) => ({ cassaId, nome, saldo, sottotipo, vincolo: sottotipo === 'vincolato_lavori' ? 'facciata' : null });
    test('(a)(f) un fondo vincolato ai lavori con € 1.000,00: compare, «Registra lo sblocco» sul fondo 11', () => {
        const a = una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(100000, 'vincolato_lavori')] })), 'fondo');
        expect(a?.titolo).toBe('«Fondo facciata» ha € 1.000,00: l\'assemblea può deliberarne lo sblocco');
        expect(testoDiPezzi(a!.frase)).toContain('Senza le rate il conto va sotto zero di € 1.000,00 al punto più basso, il 21/11: il fondo basterebbe a coprirlo.');
        expect(a?.pulsante).toEqual({ testo: 'Registra lo sblocco', tipo: 'fondo', cassaId: 11 });
    });
    test('(c) un centesimo in meno del buco: nessuna azione; un centesimo in più: c\'è', () => {
        expect(una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(99999, 'vincolato_lavori')] })), 'fondo')).toBeUndefined();
        expect(una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(100001, 'vincolato_lavori')] })), 'fondo')).toBeDefined();
    });
    test('(g) mai il TFR, non il fondo morosità, anche con molto denaro; il fondo senza tipo sì, con la nota', () => {
        expect(una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(900000, 'tfr', 'TFR portiere')] })), 'fondo')).toBeUndefined();
        expect(una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(900000, 'morosita', 'Fondo morosità')] })), 'fondo')).toBeUndefined();
        const a = una(azioniConsigliate(buco, NESSUNO, dati({ fondi: [fondo(100000, null, 'Riserva')] })), 'fondo');
        expect(a?.titolo).toBe('Il fondo «Riserva» ha € 1.000,00: l\'assemblea può deliberarne lo sblocco');
        expect(a?.nota).toBe('Il fondo non ha un tipo: nella sua pagina scegli il tipo prima di registrare lo sblocco.');
    });
    test('(b) «Senza le rate» non va sotto zero: nessun fondo', () => {
        expect(una(azioniConsigliate(SANO, NESSUNO, dati({ fondi: [fondo(100000, 'vincolato_lavori')] })), 'fondo')).toBeUndefined();
    });
});

describe('E — il piano straordinario', () => {
    // Saldo € 0,00; € 300,00 di rate scadute (entrano oggi); il 21/11 (giorno 5) una fattura di € 1.000,00; il 26/11 (giorno 10) € 200,00 di rate.
    // «Se pagano tutti»: € 300,00 fino al 20/11, −€ 700,00 dal 21/11 al 25/11, −€ 500,00 dal 26/11: manca € 700,00, sotto zero il 21/11.
    const caso = sp({ saldo0: 0, uscite: { 5: 100000 }, rate: { 10: 20000 }, scadute: 30000 });
    test('(a)(f) compare: «Manca € 700,00 anche con tutte le rate», il pulsante nell\'esercizio aperto 9', () => {
        const a = una(azioniConsigliate(caso, rec([['Rossi', 30000]], 100000), dati()), 'straordinario');
        expect(a?.titolo).toBe('Manca € 700,00 anche con tutte le rate: serve un versamento straordinario');
        expect(testoDiPezzi(a!.frase)).toContain('il conto va sotto zero il 21/11; il punto più basso è il 21/11, −€ 700,00.');
        expect(a?.pulsante).toEqual({ testo: 'Prepara un piano straordinario', tipo: 'piano_straordinario', esercizioId: 9 });
    });
    test('(c) bordo: «Se pagano tutti» tocca esattamente zero → niente; un centesimo sotto → «Manca € 0,01»', () => {
        expect(una(azioniConsigliate(sp({ saldo0: 0, uscite: { 5: 30000 }, scadute: 30000 }), NESSUNO, dati()), 'straordinario')).toBeUndefined();
        expect(una(azioniConsigliate(sp({ saldo0: 0, uscite: { 5: 30001 }, scadute: 30000 }), NESSUNO, dati()), 'straordinario')?.titolo)
            .toBe('Manca € 0,01 anche con tutte le rate: serve un versamento straordinario');
    });
    test('(g) il deficit ereditato aperto si ricorda nella frase', () => {
        const a = una(azioniConsigliate(caso, NESSUNO, dati({ deficit: { quanti: 1, cifra: 50000 } })), 'straordinario');
        expect(testoDiPezzi(a!.frase)).toContain(' C\'è anche un debito ereditato da ripianare, € 500,00: nessuna rata lo chiede.');
    });
});

describe('(d)(e) le combinazioni, l\'ordine, il conto sul pulsante, «Scelgo io»', () => {
    // Saldo € 1.000,00; il 19/11 una fattura di € 1.200,00 (−€ 200,00 fino al 21/11); il 22/11 € 500,00 di rate (→ € 300,00); € 100,00 di rate scadute.
    // «Se pagano tutti»: −€ 100,00 dal 19/11 al 21/11 → E. Quanto basta € 200,00 > € 100,00 di rate scadute → A «da solo non basta». B: le rate del 22/11 coprono.
    // «Senza le rate» −€ 200,00: un fondo vincolato di € 200,00 basta → F. Banca −€ 50,00 e contanti € 80,00 → G. Ritenute, bonifici, una rata da emettere: H, D, C.
    const caso = sp({ saldo0: 100000, uscite: { 3: 120000 }, rate: { 6: 50000 }, scadute: 10000 });
    const tutti = dati({
        ritenute: { scadenze: [{ giorno: '2026-12-16', cifra: 1780 }] }, bonifici: { quanti: 1, cifra: 5000 }, rateDaEmettere: [piano([[7, '2026-12-05', 40000]])],
        conti: [{ cassaId: 1, nome: 'Banca', tipo: 'banca', saldo: -5000, attiva: true }, { cassaId: 2, nome: 'Contanti', tipo: 'contanti', saldo: 8000, attiva: true }],
        fondi: [{ cassaId: 11, nome: 'Fondo facciata', saldo: 20000, sottotipo: 'vincolato_lavori', vincolo: null }],
    });
    test('tutte e otto, nell\'ordine H, D, C, A, B, G, F, E; il pulsante «8 azioni consigliate»', () => {
        const a = azioniConsigliate(caso, rec([['Rossi', 10000]], 20000), tutti);
        expect(chiavi(a)).toEqual(['ritenute', 'bonifici', 'emissione', 'recupero', 'fatture', 'giroconto', 'fondo', 'straordinario']);
        expect(testoDelPulsante(a.length, true)).toBe('8 azioni consigliate');
        expect(una(a, 'recupero')?.titolo).toBe('Recupera tutte le rate scadute, € 100,00, entro il 18/11');
        // La rata da emettere arriva dopo il punto più basso: manca ancora, e il titolo dice che la conta.
        expect(una(a, 'straordinario')?.titolo).toBe('Manca € 100,00 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
    });
    test('(e) con «Scelgo io» il pulsante dice «Simula il saldo», qualunque sia il numero', () => {
        expect(testoDelPulsante(8, false)).toBe('Simula il saldo');
        expect(testoDelPulsante(0, false)).toBe('Simula il saldo');
        expect(testoDelPulsante(0, true)).toBeNull();
        expect(testoDelPulsante(1, true)).toBe('1 azione consigliata');
    });
    test('senza i dati del server restano solo A e B', () => {
        expect(chiavi(azioniConsigliate(caso, rec([['Rossi', 10000]], 20000)))).toEqual(['recupero', 'fatture']);
    });
});

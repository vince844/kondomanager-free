// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le azioni consigliate della tesoreria, con regole fisse e verificabili:
 *
 * - «quanto basta»: la cifra che, entrata il giorno prima del primo giorno sotto zero dello scenario «Con le rate in scadenza», tiene quello scenario a zero o
 *   sopra per tutti i 30 giorni; se è più delle rate scadute, la tacca sta in fondo e la frase dice che il recupero da solo non basta;
 * - la simulazione: lo scenario con le rate in scadenza più la parte recuperata, il punto più basso, i giorni sotto zero, la cifra fra 30 giorni;
 * - (A) «Recupera almeno € … delle rate scadute entro il …», con le persone che bastano (le rate scadute più alte prima finché la somma copre la cifra), in
 *   una tabella sotto la frase;
 * - (B) «Chiedi di pagare le fatture del … dopo il …», quando le fatture scadono nei giorni sotto zero e un'entrata di rate dopo riporta sopra zero;
 * - la situazione in una frase, e i fatti scritti sul grafico.
 *
 * I casi: va sotto zero e il recupero basta (l'esempio della guida); non basta; non va mai sotto zero; va sotto zero solo senza le rate; nessuna fattura da spostare; una
 * persona sola basta; servono tre persone; ne servono più di 8; con «Scelgo io» nessuna azione.
 *
 * Le sei in più, dai dati del server, ognuna con la cifra fatta a mano, un caso in cui non c'è e il pulsante: le ritenute (H), i bonifici segnalati (D), le rate
 * da emettere (C), il giroconto (G, anche quando l'altro conto non basta), il fondo da sbloccare (F: mai il TFR né il fondo morosità), il piano straordinario
 * (E, con il deficit ereditato); l'ordine nel cassetto e il conto sul pulsante.
 */
import { describe, expect, test } from 'vitest';
import {
    RIGA_DELLE_SCADENZE, azioniConsigliate, cifreDellaSimulazione, datiDelGraficoGrande, descrizioneDelGraficoGrande, domandaDelRecupero, fattiDelGrafico,
    indiceDelRecupero, opzioniDelGraficoGrande, percentuale, pluginDeiFatti, quantoBasta, simulazione, situazione, tacca, testoDelPulsante, testoDiPezzi, testoGiorniSottoZero,
    type AzioneConsigliata, type DatiDelleAzioni, type PersonaDelRecupero, type Recupero,
} from './azioniConsigliate';
import type { FatturaDelGiorno, GiornoDelSaldo, SaldoPrevisto } from './saldoPrevisto';

const vuoto = { uscite: 0, quante_uscite: 0, voci_uscite: [], entrate: 0, quante_entrate: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [], rate_in_scadenza: 0,
    quante_rate_in_scadenza: 0, fatture: [] as FatturaDelGiorno[] };

/** Trentuno giorni dal 15/11/2026: il saldo di oggi, e per giorno le fatture, i registrati e le rate in scadenza; le rate già scadute. */
function tesoreria(oggi: number, eventi: Record<number, { fatture?: FatturaDelGiorno[]; registrati?: number; rate?: number }>, scadute: number): SaldoPrevisto {
    const giorni: GiornoDelSaldo[] = [];
    let s = oggi;
    let rate = 0;
    for (let i = 0; i <= 30; i++) {
        const e = eventi[i] ?? {};
        const uscite = (e.fatture ?? []).reduce((t, f) => t + f.importo, 0);
        s += (e.registrati ?? 0) - uscite;
        rate += e.rate ?? 0;
        giorni.push({ ...vuoto, giorno: new Date(Date.UTC(2026, 10, 15 + i)).toISOString().slice(0, 10), saldo: s, con_entrate: s + scadute + rate, uscite,
            quante_uscite: (e.fatture ?? []).length, fatture: e.fatture ?? [], registrati: e.registrati ?? 0, quanti_registrati: e.registrati ? 1 : 0, rate_in_scadenza: e.rate ?? 0,
            quante_rate_in_scadenza: e.rate ? 4 : 0, entrate: (e.rate ?? 0) + (i === 0 ? scadute : 0) });
    }

    return { giorni, primoSottoZero: giorni.find((g) => g.saldo < 0)?.giorno ?? null, minimoCents: Math.min(0, ...giorni.map((g) => g.saldo)), finaleCents: s,
        finaleConEntrateCents: s + scadute + rate, rateScaduteCents: scadute };
}

const fattura = (id: number, fornitore: string, importo: number): FatturaDelGiorno => ({ id, fornitore, numero: `${id}/2026`, importo });
const persona = (n: number, nome: string, cifra: number, lettera: PersonaDelRecupero['lettera'] = null): PersonaDelRecupero =>
    ({ anagraficaId: n, nome, interno: `Int. ${n}`, cifra, lettera });

/** L'esempio della guida: € 1.462,03 oggi; il 20/11 tre fatture per € 3.830,80; il 30/11 € 2.400,00 di rate; € 6.592,97 di rate già scadute. */
const ESEMPIO = () => tesoreria(146203, {
    5: { fatture: [fattura(1, 'Vetreria Rapida Demo', 183000), fattura(2, 'Idraulica Urgenze Demo', 170800), fattura(3, 'Pulizie Aurora Demo', 29280)] },
    15: { rate: 240000 },
}, 659297);
const GIULIA: Recupero = { persone: [persona(4, 'Giulia Esposito', 397410)], servono: 1, servonoCents: 397410, quante: 6, cifra: 659297 };

describe('quanto basta e la simulazione', () => {
    test('l\'esempio: quanto basta € 2.368,77, entra il 19/11; senza recupero −€ 2.368,77 per 10 giorni e € 31,23 fra 30 giorni; con quanto basta mai sotto zero', () => {
        const sp = ESEMPIO();
        expect(quantoBasta(sp)).toBe(236877);
        expect(indiceDelRecupero(sp)).toBe(4);
        expect(domandaDelRecupero(sp)).toBe('Quanto recuperi delle rate già scadute prima del 20/11?');
        expect(cifreDellaSimulazione(simulazione(sp, 0))).toEqual({ minimo: -236877, giorniSottoZero: 10, fine: 3123 });
        expect(cifreDellaSimulazione(simulazione(sp, 236877))).toEqual({ minimo: 0, giorniSottoZero: 0, fine: 240000 });
        expect(cifreDellaSimulazione(simulazione(sp, 236876)).giorniSottoZero).toBe(10);
        expect(testoGiorniSottoZero(0)).toBe('nessuno');
        expect(testoGiorniSottoZero(1)).toBe('1 giorno');
        expect(testoGiorniSottoZero(10)).toBe('10 giorni');
        expect(percentuale(236877, 659297)).toBe('36 %');
        expect(tacca(236877, 659297)).toEqual({ posizione: (236877 / 659297) * 100, oltre: false, testo: 'quanto basta: € 2.368,77 (36 %)' });
    });

    test('sotto zero da oggi: il recupero entra oggi, e la domanda lo dice', () => {
        const sp = tesoreria(-50000, { 10: { rate: 80000 } }, 100000);
        expect(indiceDelRecupero(sp)).toBe(0);
        expect(quantoBasta(sp)).toBe(50000);
        expect(domandaDelRecupero(sp)).toBe('Quanto recuperi delle rate già scadute oggi?');
        expect(cifreDellaSimulazione(simulazione(sp, 50000))).toEqual({ minimo: 0, giorniSottoZero: 0, fine: 80000 });
    });

    test('il recupero non basta: quanto basta è più delle rate scadute, la tacca sta in fondo e lo dice', () => {
        expect(tacca(500000, 100000)).toEqual({ posizione: 100, oltre: true, testo: 'quanto basta: € 5.000,00, più delle rate scadute' });
        expect(tacca(0, 100000)).toBeNull();
    });
});

describe('le azioni consigliate', () => {
    test('va sotto zero e il recupero basta: due azioni, come nell\'esempio', () => {
        const azioni = azioniConsigliate(ESEMPIO(), GIULIA);
        expect(azioni.map((a) => a.chiave)).toEqual(['recupero', 'fatture']);
        expect(azioni[0].titolo).toBe('Recupera almeno € 2.368,77 delle rate scadute entro il 19/11');
        // La frase finisce ai due punti: la persona sta nella tabella sotto.
        expect(testoDiPezzi(azioni[0].frase)).toBe('È quanto manca il 20/11 per non andare sotto zero. Basta una persona:');
        expect(azioni[0].tabella).toEqual({ righe: [{ anagraficaId: 4, nome: 'Giulia Esposito', unita: 'Int. 4', cifra: 397410, lettera: null }], altre: null });
        expect(azioni[0].pulsante).toEqual({ testo: 'Manda un sollecito', tipo: 'lettera', persone: [{ id: 4, nome: 'Giulia Esposito' }] });
        expect(azioni[1].titolo).toBe('Chiedi di pagare le fatture del 20/11 dopo il 30/11');
        expect(testoDiPezzi(azioni[1].frase)).toBe('Il 30/11 entrano € 2.400,00 di rate: se i fornitori accettano, il conto resta sopra zero. Le fatture: '
            + 'Vetreria Rapida Demo € 1.830,00, Idraulica Urgenze Demo € 1.708,00, Pulizie Aurora Demo € 292,80.');
        expect(azioni[1].pulsante).toEqual({ testo: 'Apri le fatture', tipo: 'fatture', fatture: [1, 2, 3] });
        expect(testoDelPulsante(azioni.length, true)).toBe('2 azioni consigliate');
        expect(RIGA_DELLE_SCADENZE).toBe('Le scadenze delle rate non sono fra le azioni: le approva l\'assemblea con il piano rate.');
    });

    test('il recupero non basta: «Recupera tutte le rate scadute», e la frase dice che da solo non basta', () => {
        const sp = tesoreria(146203, { 5: { fatture: [fattura(1, 'Vetreria Rapida Demo', 900000)] }, 15: { rate: 240000 } }, 100000);
        const recupero: Recupero = { persone: [persona(1, 'Anna Neri', 60000), persona(2, 'Bruno Sala', 40000)], servono: 2, servonoCents: 100000, quante: 2, cifra: 100000 };
        const [a] = azioniConsigliate(sp, recupero);
        expect(quantoBasta(sp)).toBe(753797);
        expect(a.titolo).toBe('Recupera tutte le rate scadute, € 1.000,00, entro il 19/11');
        expect(testoDiPezzi(a.frase)).toBe('Da solo non basta: anche con tutte le rate scadute il conto va sotto zero il 20/11, fino a −€ 6.537,97. '
            + 'Le rate scadute sono di due persone:');
        expect(a.tabella?.righe.map((r) => [r.nome, r.unita, r.cifra, r.lettera])).toEqual([['Anna Neri', 'Int. 1', 60000, null], ['Bruno Sala', 'Int. 2', 40000, null]]);
        expect(a.pulsante).toEqual({ testo: 'Manda i solleciti', tipo: 'lettera', persone: [{ id: 1, nome: 'Anna Neri' }, { id: 2, nome: 'Bruno Sala' }] });
    });

    test('non va mai sotto zero: nessuna azione e nessun pulsante', () => {
        const sp = tesoreria(500000, { 5: { fatture: [fattura(1, 'Enel', 10000)] }, 15: { rate: 240000 } }, 100000);
        expect(azioniConsigliate(sp, { ...GIULIA, persone: [], servono: 0, servonoCents: 0 })).toEqual([]);
        expect(testoDelPulsante(0, true)).toBeNull();
        expect(quantoBasta(sp)).toBe(0);
    });

    test('va sotto zero solo senza le rate: con le rate in scadenza resta sopra, nessuna azione', () => {
        const sp = tesoreria(100000, { 3: { rate: 200000 }, 5: { fatture: [fattura(1, 'Edil Rossi srl', 250000)] } }, 50000);
        expect(quantoBasta(sp)).toBe(0);
        expect(azioniConsigliate(sp, { persone: [], servono: 0, servonoCents: 0, quante: 1, cifra: 50000 })).toEqual([]);
    });

    test('nessuna fattura da spostare: il saldo va sotto zero per un pagamento già registrato, e c\'è solo il recupero', () => {
        const sp = tesoreria(100000, { 5: { registrati: -300000 }, 15: { rate: 240000 } }, 659297);
        const azioni = azioniConsigliate(sp, GIULIA);
        expect(azioni.map((a) => a.chiave)).toEqual(['recupero']);
        expect(testoDelPulsante(azioni.length, true)).toBe('1 azione consigliata');
    });

    test('le fatture dopo l\'entrata delle rate non bastano a restare sopra zero: niente richiesta ai fornitori', () => {
        const sp = tesoreria(146203, { 5: { fatture: [fattura(1, 'Vetreria Rapida Demo', 383080)] }, 15: { rate: 100000 } }, 659297);
        expect(azioniConsigliate(sp, GIULIA).map((a) => a.chiave)).toEqual(['recupero']);
    });

    test('servono tre persone: le rate scadute più alte prima, ognuna con la sua cifra e l\'ultima lettera', () => {
        const sp = tesoreria(10000, { 2: { fatture: [fattura(1, 'Edil Rossi srl', 260000)] }, 20: { rate: 300000 } }, 400000);
        const recupero: Recupero = {
            persone: [persona(1, 'Anna Neri', 120000), persona(2, 'Bruno Sala', 90000, { tipo: 'sollecito', data: '2026-11-02' }), persona(3, 'Carla Riva', 60000)],
            servono: 3, servonoCents: 270000, quante: 5, cifra: 400000,
        };
        const [a] = azioniConsigliate(sp, recupero);
        expect(a.titolo).toBe('Recupera almeno € 2.500,00 delle rate scadute entro il 16/11');
        expect(testoDiPezzi(a.frase)).toBe('È quanto manca il 17/11 per non andare sotto zero. Bastano tre persone:');
        expect(a.tabella?.righe.map((r) => [r.nome, r.unita, r.cifra, r.lettera])).toEqual([['Anna Neri', 'Int. 1', 120000, null],
            ['Bruno Sala', 'Int. 2', 90000, 'sollecito del 02/11/2026'], ['Carla Riva', 'Int. 3', 60000, null]]);
        expect(a.pulsante.tipo).toBe('lettera');
        expect(a.pulsante.persone?.map((p) => p.id)).toEqual([1, 2, 3]);
    });

    test('ne servono più di 8: le prime 5 con quante mancano, e il pulsante apre «Rate scadute»', () => {
        const sp = tesoreria(10000, { 2: { fatture: [fattura(1, 'Edil Rossi srl', 1010000)] }, 20: { rate: 2000000 } }, 2000000);
        const persone = [1, 2, 3, 4, 5].map((n) => persona(n, `Persona ${n}`, 100000));
        const [a] = azioniConsigliate(sp, { persone, servono: 12, servonoCents: 1200000, quante: 20, cifra: 2000000 });
        expect(testoDiPezzi(a.frase)).toMatch(/ Servono 12 persone:$/);
        expect(a.tabella?.righe.map((r) => r.nome)).toEqual(['Persona 1', 'Persona 2', 'Persona 3', 'Persona 4', 'Persona 5']);
        expect(a.tabella?.altre).toEqual({ quante: 7, cifra: 700000 });
        expect(a.pulsante).toEqual({ testo: 'Apri le rate scadute', tipo: 'rate_scadute' });
    });

    test('il punto più basso viene dopo il primo giorno sotto zero: la frase dice quanto serve per tutti i 30 giorni', () => {
        const sp = tesoreria(10000, { 2: { fatture: [fattura(1, 'Edil Rossi srl', 30000)] }, 8: { fatture: [fattura(2, 'Enel', 50000)] } }, 400000);
        const [a] = azioniConsigliate(sp, { persone: [persona(1, 'Anna Neri', 120000)], servono: 1, servonoCents: 120000, quante: 1, cifra: 400000 });
        expect(a.titolo).toBe('Recupera almeno € 700,00 delle rate scadute entro il 16/11');
        expect(testoDiPezzi(a.frase)).toMatch(/^È quanto serve per non andare sotto zero nei 30 giorni: il punto più basso è il 23\/11, −€ 700,00\./);
    });

    test('con «Scelgo io» il pulsante dice «Simula il saldo»', () => {
        expect(testoDelPulsante(2, false)).toBe('Simula il saldo');
        expect(testoDelPulsante(0, false)).toBe('Simula il saldo');
    });
});

/** I dati delle sei azioni in più, vuoti: nessuna regola vera. */
const DATI = (altro: Partial<DatiDelleAzioni> = {}): DatiDelleAzioni => ({ rateDaEmettere: [], bonifici: { quanti: 0, cifra: 0 }, ritenute: { scadenze: [] },
    deficit: { quanti: 0, cifra: 0 }, esercizioId: 25, conti: [], fondi: [], ...altro });
const NESSUNO: Recupero = { persone: [], servono: 0, servonoCents: 0, quante: 0, cifra: 0 };
/** Un saldo sempre sopra zero, in tutti gli scenari: nessuna azione del saldo. */
const SERENO = () => tesoreria(500000, { 5: { fatture: [fattura(1, 'Enel', 10000)] } }, 0);
const azione = (azioni: AzioneConsigliata[], chiave: AzioneConsigliata['chiave']) => azioni.find((a) => a.chiave === chiave);
const frase = (a: AzioneConsigliata | undefined) => testoDiPezzi(a?.frase ?? []);

describe('le sei azioni in più', () => {
    test('(H) le ritenute: la prima scadenza e la sua cifra, poi le altre; il pulsante prepara l\'F24', () => {
        const azioni = azioniConsigliate(SERENO(), NESSUNO, DATI({ ritenute: { scadenze: [{ giorno: '2026-11-16', cifra: 30000 }, { giorno: '2026-12-16', cifra: 12000 }] } }));
        const h = azione(azioni, 'ritenute')!;
        expect(h.titolo).toBe('Paga per prime le ritenute, € 300,00 entro il 16/11');
        expect(frase(h)).toBe('Le ritenute d\'acconto dei fornitori si versano all\'Erario con l\'F24, alle scadenze della legge, e non sono nel saldo previsto. '
            + 'Poi € 120,00 entro il 16/12.');
        expect(h.pulsante).toEqual({ testo: 'Prepara l\'F24', tipo: 'f24' });
        expect(azioni.map((a) => a.chiave)).toEqual(['ritenute']);
    });

    test('(H) le ritenute già scadute vengono prima, sommate, con il giorno in cui scadevano; senza ritenute nessuna azione', () => {
        const h = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ ritenute: { scadenze: [{ giorno: '2026-09-16', cifra: 5000 }, { giorno: '2026-10-16', cifra: 15000 },
            { giorno: '2026-11-16', cifra: 30000 }] } })), 'ritenute')!;
        expect(h.titolo).toBe('Paga per prime le ritenute, € 200,00: le scadenze erano dal 16/09');
        expect(frase(h)).toMatch(/ Poi € 300,00 entro il 16\/11\.$/);
        const una = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ ritenute: { scadenze: [{ giorno: '2026-10-16', cifra: 20000 }] } })), 'ritenute')!;
        expect(una.titolo).toBe('Paga per prime le ritenute, € 200,00: la scadenza era il 16/10');
        expect(azioniConsigliate(SERENO(), NESSUNO, DATI())).toEqual([]);
    });

    test('(D) i bonifici segnalati: quanti e quanto, «Verifica i bonifici»; uno solo al singolare; nessuno, nessuna azione', () => {
        const d = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ bonifici: { quanti: 2, cifra: 35000 } })), 'bonifici')!;
        expect(d.titolo).toBe('2 bonifici segnalati, € 350,00: verificali prima di sollecitare');
        expect(frase(d)).toBe('I condòmini li hanno segnalati dal portale. Se sono arrivati sul conto, registra l\'incasso: le rate risultano pagate e non vanno sollecitate.');
        expect(d.pulsante).toEqual({ testo: 'Verifica i bonifici', tipo: 'inbox' });
        const uno = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ bonifici: { quanti: 1, cifra: 12000 } })), 'bonifici')!;
        expect(uno.titolo).toBe('1 bonifico segnalato, € 120,00: verificalo prima di sollecitare');
        expect(uno.pulsante.testo).toBe('Verifica il bonifico');
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ bonifici: { quanti: 0, cifra: 0 } })), 'bonifici')).toBeUndefined();
    });

    test('(C) le rate non ancora emesse: una per piano, la rata, la cifra, la scadenza; non sono nel saldo previsto, e la frase lo dice', () => {
        const uno = { pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, rate: [{ rataId: 61, numero: 6, scadenza: '2026-11-30', cifra: 100000 }], quante: 1, cifra: 100000 };
        const due = { pianoId: 12, piano: 'Facciata', esercizioId: 25, quante: 2, cifra: 220000,
            rate: [{ rataId: 71, numero: 4, scadenza: '2026-11-05', cifra: 100000 }, { rataId: 72, numero: 5, scadenza: '2026-12-05', cifra: 120000 }] };
        const azioni = azioniConsigliate(SERENO(), NESSUNO, DATI({ rateDaEmettere: [uno, due] })).filter((a) => a.chiave === 'emissione');
        expect(azioni).toHaveLength(2);
        expect(azioni[0].titolo).toBe('Emetti la rata 6 del piano «Preventivo 2026»: porterebbe € 1.000,00 entro il 30/11');
        expect(frase(azioni[0])).toBe('Non è nel saldo previsto, che conta solo le rate emesse.');
        expect(azioni[0].pulsante).toEqual({ testo: 'Emetti la rata', tipo: 'emissione', pianoId: 9, esercizioId: 25 });
        // Il titolo resta corto (quante rate, la cifra, dalla prima all'ultima scadenza); l'elenco si legge con «Mostra le rate».
        expect(azioni[1].titolo).toBe('Emetti 2 rate del piano «Facciata»: porterebbero € 2.200,00, scadono dal 05/11 al 05/12');
        expect(frase(azioni[1])).toBe('Non sono nel saldo previsto, che conta solo le rate emesse.');
        expect(azioni[1].dettagli).toEqual(['Rata 4, € 1.000,00, scadeva il 05/11', 'Rata 5, € 1.200,00, scade il 05/12']);
        expect(azioni[1].pulsante).toEqual({ testo: 'Emetti le rate', tipo: 'emissione', pianoId: 12, esercizioId: 25 });
    });

    test('(C) una rata già scaduta e non emessa lo dice; più di 8 rate, le altre con la loro cifra; senza rate da emettere nessuna azione', () => {
        const passata = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ rateDaEmettere: [{ pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, quante: 1, cifra: 50000,
            rate: [{ rataId: 51, numero: 3, scadenza: '2026-10-05', cifra: 50000 }] }] })), 'emissione')!;
        expect(passata.titolo).toBe('Emetti la rata 3 del piano «Preventivo 2026»: porterebbe € 500,00, e scadeva il 05/10');
        const rate = Array.from({ length: 8 }, (_, i) => ({ rataId: i + 1, numero: i + 1, scadenza: `2026-0${i + 1}-05`, cifra: 10000 }));
        const tante = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ rateDaEmettere: [{ pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, quante: 10, cifra: 100000, rate }] })), 'emissione')!;
        expect(tante.titolo).toBe('Emetti 10 rate del piano «Preventivo 2026»: porterebbero € 1.000,00, scadevano dal 05/01 in poi');
        expect(tante.dettagli).toHaveLength(9);
        expect(tante.dettagli?.at(-1)).toBe('E altre 2 rate, € 200,00');
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ rateDaEmettere: [] })), 'emissione')).toBeUndefined();
    });

    test('(G) un conto sotto zero e un altro con il denaro: la cifra che lo riporta a zero; il giroconto con i due conti e la cifra', () => {
        const conti = [{ cassaId: 1, nome: 'Banca Intesa', tipo: 'banca', saldo: -30000, attiva: true }, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 50000, attiva: true }];
        const g = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti })), 'giroconto')!;
        expect(g.titolo).toBe('Sposta € 300,00 da Cassa contanti a Banca Intesa');
        expect(frase(g)).toBe('Oggi Banca Intesa è sotto zero di € 300,00 e Cassa contanti ha € 500,00: con il giroconto Banca Intesa torna a zero.');
        expect(g.pulsante).toEqual({ testo: 'Fai un giroconto', tipo: 'giroconto', origine: 2, destinazione: 1, importo: 30000 });
    });

    test('(G) quando l\'altro conto non basta, la cifra è tutto quello che ha, e la frase dice quanto resta sotto zero; senza un conto sotto zero o senza denaro altrove nessuna azione', () => {
        const banca = { cassaId: 1, nome: 'Banca Intesa', tipo: 'banca', saldo: -30000, attiva: true };
        const g = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti: [banca, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 12000, attiva: true }] })), 'giroconto')!;
        expect(g.titolo).toBe('Sposta € 120,00 da Cassa contanti a Banca Intesa');
        expect(frase(g)).toBe('Oggi Banca Intesa è sotto zero di € 300,00 e Cassa contanti ha € 120,00: è tutto quello che può dare, e Banca Intesa resta sotto zero di € 180,00.');
        expect(g.pulsante.importo).toBe(12000);
        // Due conti con il denaro: quello che ne ha di più.
        const piu = azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti: [banca, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 12000, attiva: true },
            { cassaId: 3, nome: 'Banca Posta', tipo: 'banca', saldo: 80000, attiva: true }] })), 'giroconto')!;
        expect(piu.titolo).toBe('Sposta € 300,00 da Banca Posta a Banca Intesa');
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti: [{ ...banca, saldo: 30000 }, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 500, attiva: true }] })), 'giroconto')).toBeUndefined();
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti: [banca, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 0, attiva: true }] })), 'giroconto')).toBeUndefined();
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ conti: [banca, { cassaId: 2, nome: 'Cassa chiusa', tipo: 'contanti', saldo: 90000, attiva: false }] })), 'giroconto')).toBeUndefined();
    });

    test('(F) un fondo vincolato ai lavori che copre il punto più basso senza le rate: l\'assemblea può deliberarne lo sblocco; mai «usa il fondo»', () => {
        const fondi = [{ cassaId: 6, nome: 'Fondo facciata', saldo: 300000, sottotipo: 'vincolato_lavori', vincolo: 'facciata' }, { cassaId: 7, nome: 'Riserva', saldo: 500000, sottotipo: null, vincolo: null }];
        const f = azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI({ fondi })), 'fondo')!;
        expect(f.titolo).toBe('«Fondo facciata» ha € 3.000,00: l\'assemblea può deliberarne lo sblocco');
        expect(frase(f)).toBe('Senza le rate il conto va sotto zero di € 2.368,77 al punto più basso, il 20/11: il fondo basterebbe a coprirlo. È vincolato ai lavori (facciata): il suo '
            + 'denaro si può spendere solo se l\'assemblea ne delibera lo sblocco, e la delibera si registra nella pagina del fondo. Basterebbe anche il fondo «Riserva», € 5.000,00.');
        expect(f.pulsante).toEqual({ testo: 'Registra lo sblocco', tipo: 'fondo', cassaId: 6 });
        expect(f.nota).toBeUndefined();
        expect(`${f.titolo} ${frase(f)}`.toLowerCase()).not.toContain('usa il fondo');
        // Senza tipo: conta come vincolato, e la riga dice di sceglierlo prima di registrare lo sblocco.
        const senzaTipo = azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI({ fondi: [fondi[1]] })), 'fondo')!;
        expect(senzaTipo.titolo).toBe('Il fondo «Riserva» ha € 5.000,00: l\'assemblea può deliberarne lo sblocco');
        expect(frase(senzaTipo)).toContain('È senza un tipo, e conta come vincolato:');
        expect(senzaTipo.nota).toBe('Il fondo non ha un tipo: nella sua pagina scegli il tipo prima di registrare lo sblocco.');
    });

    test('(F) il TFR e il fondo morosità non compaiono mai, anche se basterebbero; un fondo che non basta no; senza un buco nessuna azione', () => {
        const tfr = { cassaId: 8, nome: 'TFR portiere', saldo: 900000, sottotipo: 'tfr', vincolo: null };
        const morosita = { cassaId: 9, nome: 'Fondo morosità', saldo: 900000, sottotipo: 'morosita', vincolo: null };
        expect(azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI({ fondi: [tfr, morosita] })), 'fondo')).toBeUndefined();
        expect(azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI({ fondi: [{ cassaId: 6, nome: 'Fondo facciata', saldo: 236876, sottotipo: 'vincolato_lavori', vincolo: null }] })), 'fondo')).toBeUndefined();
        expect(azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI({ fondi: [{ cassaId: 6, nome: 'Fondo facciata', saldo: 236877, sottotipo: 'vincolato_lavori', vincolo: null }] })), 'fondo')).toBeDefined();
        expect(azione(azioniConsigliate(SERENO(), NESSUNO, DATI({ fondi: [{ cassaId: 6, nome: 'Fondo facciata', saldo: 300000, sottotipo: 'vincolato_lavori', vincolo: null }] })), 'fondo')).toBeUndefined();
    });

    test('(E) anche se pagano tutti il conto va sotto zero: quanto manca al punto più basso, il piano straordinario; il deficit ereditato si ricorda', () => {
        const sp = tesoreria(146203, { 5: { fatture: [fattura(1, 'Vetreria Rapida Demo', 900000)] }, 15: { rate: 240000 } }, 100000);
        const e = azione(azioniConsigliate(sp, NESSUNO, DATI()), 'straordinario')!;
        expect(e.titolo).toBe('Manca € 6.537,97 anche con tutte le rate: serve un versamento straordinario');
        expect(frase(e)).toBe('Anche se pagano tutti, rate già scadute comprese, il conto va sotto zero il 20/11; il punto più basso è il 20/11, −€ 6.537,97. '
            + 'Un piano rate straordinario chiede ai condòmini la cifra che manca, e lo delibera l\'assemblea.');
        expect(e.pulsante).toEqual({ testo: 'Prepara un piano straordinario', tipo: 'piano_straordinario', esercizioId: 25 });
        const conDeficit = azione(azioniConsigliate(sp, NESSUNO, DATI({ deficit: { quanti: 1, cifra: 50000 } })), 'straordinario')!;
        expect(frase(conDeficit)).toMatch(/ C'è anche un debito ereditato da ripianare, € 500,00: nessuna rata lo chiede\.$/);
        const due = azione(azioniConsigliate(sp, NESSUNO, DATI({ deficit: { quanti: 2, cifra: 80000 } })), 'straordinario')!;
        expect(frase(due)).toMatch(/ Ci sono anche 2 debiti ereditati da ripianare, € 800,00: nessuna rata li chiede\.$/);
        // Con tutte le rate il conto resta sopra zero: nessun piano straordinario.
        expect(azione(azioniConsigliate(ESEMPIO(), GIULIA, DATI()), 'straordinario')).toBeUndefined();
    });

    test('l\'ordine nel cassetto: le ritenute, i bonifici, le rate da emettere, il recupero, le fatture, il giroconto, il fondo, il piano straordinario; il pulsante le conta tutte', () => {
        // Oggi € 100,00 e € 50,00 di rate scadute; il 17/11 una fattura di € 300,00; il 25/11 € 500,00 di rate. Con le rate in scadenza −€ 200,00 dal 17/11
        // al 24/11, poi € 300,00; se pagano tutti −€ 150,00; senza le rate −€ 200,00 fino alla fine.
        const sp = tesoreria(10000, { 2: { fatture: [fattura(1, 'Edil Rossi srl', 30000)] }, 10: { rate: 50000 } }, 5000);
        const dati = DATI({
            ritenute: { scadenze: [{ giorno: '2026-11-16', cifra: 4000 }] }, bonifici: { quanti: 1, cifra: 5000 },
            rateDaEmettere: [{ pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, quante: 1, cifra: 100000, rate: [{ rataId: 61, numero: 6, scadenza: '2026-11-30', cifra: 100000 }] }],
            conti: [{ cassaId: 1, nome: 'Banca Intesa', tipo: 'banca', saldo: -2000, attiva: true }, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 12000, attiva: true }],
            fondi: [{ cassaId: 6, nome: 'Fondo facciata', saldo: 30000, sottotipo: 'vincolato_lavori', vincolo: null }],
        });
        const recupero: Recupero = { persone: [persona(1, 'Anna Neri', 5000)], servono: 1, servonoCents: 5000, quante: 1, cifra: 5000 };
        const azioni = azioniConsigliate(sp, recupero, dati);
        expect(azioni.map((a) => a.chiave)).toEqual(['ritenute', 'bonifici', 'emissione', 'recupero', 'fatture', 'giroconto', 'fondo', 'straordinario']);
        expect(testoDelPulsante(azioni.length, true)).toBe('8 azioni consigliate');
        // La rata da emettere entra il 30/11, dopo il punto più basso del 17/11: manca ancora, e il titolo dice che la conta.
        expect(azione(azioni, 'straordinario')!.titolo).toBe('Manca € 150,00 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
        // Senza i dati del server (una tesoreria di prima) solo il recupero e le fatture, come prima.
        expect(azioniConsigliate(sp, recupero).map((a) => a.chiave)).toEqual(['recupero', 'fatture']);
    });
});

describe('la situazione in una frase', () => {
    test('l\'esempio: quando e di quanto va sotto zero, perché, che cosa entra e quando, le rate già scadute', () => {
        const pezzi = situazione(ESEMPIO(), GIULIA);
        expect(testoDiPezzi(pezzi)).toBe('Il conto va sotto zero il 20/11 di € 2.368,77, per le fatture che scadono quel giorno. Il 30/11 entrano € 2.400,00 di rate. '
            + 'Intanto ci sono € 6.592,97 di rate già scadute.');
        expect(pezzi.find((p) => p.tono === 'rosso')?.testo).toBe('sotto zero il 20/11 di € 2.368,77');
        expect(pezzi.find((p) => p.tono === 'forte')?.testo).toBe('€ 6.592,97 di rate già scadute');
    });

    test('non va mai sotto zero, anche senza le rate', () => {
        const sp = tesoreria(500000, { 5: { fatture: [fattura(1, 'Enel', 10000)] } }, 100000);
        expect(testoDiPezzi(situazione(sp, GIULIA))).toBe('Il conto resta sopra zero per tutti i 30 giorni, anche senza le rate: il punto più basso è € 4.900,00, il 20/11. '
            + 'Intanto ci sono € 6.592,97 di rate già scadute.');
    });

    test('va sotto zero solo senza le rate', () => {
        const sp = tesoreria(100000, { 3: { rate: 200000 }, 5: { fatture: [fattura(1, 'Edil Rossi srl', 250000)] } }, 50000);
        expect(testoDiPezzi(situazione(sp, { persone: [], servono: 0, servonoCents: 0, quante: 1, cifra: 50000 }))).toBe(
            'Senza le rate il conto va sotto zero il 20/11 di € 1.500,00, per le fatture che scadono quel giorno. Con le rate che scadono nei 30 giorni resta sopra zero, '
            + 'se arrivano in tempo: il 18/11 entrano € 2.000,00. Intanto ci sono € 500,00 di rate già scadute.');
    });

    test('già sotto zero oggi, anche se pagano tutti, senza rate in arrivo', () => {
        const sp = tesoreria(-50000, {}, 20000);
        expect(testoDiPezzi(situazione(sp, { persone: [], servono: 1, servonoCents: 20000, quante: 1, cifra: 20000 }))).toBe(
            'Il conto è sotto zero già oggi, di € 500,00. Nei 30 giorni non scadono rate. Intanto ci sono € 200,00 di rate già scadute. '
            + 'Anche se pagano tutti, oggi il conto è sotto zero.');
    });
});

describe('il grafico con tre linee', () => {
    const colori = { linea: 'rgb(79, 70, 229)', zona: 'rgba(220, 38, 38, 0.16)', zonaSopra: 'rgba(79, 70, 229, 0.06)', verde: 'rgb(16, 185, 129)', grigio: 'rgb(148, 163, 184)',
        testo: 'rgb(100, 116, 139)', griglia: 'rgb(226, 232, 240)', zero: 'rgb(15, 23, 42)', sfondo: 'rgb(255, 255, 255)', bordo: 'rgb(226, 232, 240)', scritta: 'rgb(51, 65, 85)' };

    test('la simulazione piena in indaco con il rosso sotto zero; «Se pagano tutti» tratteggiata verde; «Senza le rate» tratteggiata grigia; a gradini', () => {
        const d = datiDelGraficoGrande(ESEMPIO(), 236877, colori);
        expect(d.datasets.map((s) => s.label)).toEqual(['Simulazione', 'Se pagano tutti', 'Senza le rate']);
        expect(d.datasets.every((s) => s.stepped === true)).toBe(true);
        expect(d.datasets[0]).toMatchObject({ borderColor: colori.linea, fill: { target: { value: 0 }, below: colori.zona, above: colori.zonaSopra } });
        expect(d.datasets[0].borderDash).toBeUndefined();
        expect(d.datasets[1]).toMatchObject({ borderColor: colori.verde, borderDash: [5, 4], fill: false });
        expect(d.datasets[2]).toMatchObject({ borderColor: colori.grigio, borderDash: [5, 4], fill: false });
        expect(d.datasets[0].data[4]).toBe(3830.8);
        expect(d.datasets[0].data[5]).toBe(0);
        expect(d.labels[0]).toBe('15/11');
    });

    test('i fatti scritti sul grafico: le fatture del giorno, le rate del giorno, il recupero', () => {
        expect(fattiDelGrafico(ESEMPIO(), 0)).toEqual([
            { indice: 5, testo: '20/11 · fatture −€ 3.830,80', sopra: false },
            { indice: 15, testo: '30/11 · rate +€ 2.400,00', sopra: true },
        ]);
        expect(fattiDelGrafico(ESEMPIO(), 236877)[0]).toEqual({ indice: 4, testo: '19/11 · recupero +€ 2.368,77', sopra: true });
    });

    test('i fatti si scrivono in un cartellino sopra o sotto il punto della simulazione, dentro il grafico, a 12 pixel', () => {
        const scritte: Array<{ testo: string; x: number; y: number }> = [];
        const ctx = { save() {}, restore() {}, beginPath() {}, fill() {}, stroke() {}, roundRect() {}, rect() {}, measureText: (t: string) => ({ width: t.length * 6 }),
            fillText: (testo: string, x: number, y: number) => scritte.push({ testo, x, y }), font: '', fillStyle: '', strokeStyle: '', lineWidth: 0, textBaseline: '' };
        const punti = Array.from({ length: 31 }, (_, i) => ({ x: 40 + i * 20, y: 150 }));
        const fatti = fattiDelGrafico(ESEMPIO(), 236877);
        pluginDeiFatti(() => fatti, () => colori).afterDatasetsDraw({ ctx: ctx as unknown as CanvasRenderingContext2D, chartArea: { left: 40, right: 640, top: 0, bottom: 300 },
            getDatasetMeta: () => ({ data: punti }) });
        expect(scritte.map((s) => s.testo)).toEqual(['19/11 · recupero +€ 2.368,77', '20/11 · fatture −€ 3.830,80', '30/11 · rate +€ 2.400,00']);
        expect(ctx.font).toContain('12px');
        // Sopra il punto il recupero e le rate, sotto le fatture; mai fuori dal grafico.
        expect(scritte[0].y).toBeLessThan(150);
        expect(scritte[1].y).toBeGreaterThan(150);
        expect(scritte.every((s) => s.x >= 40 && s.x <= 640)).toBe(true);
    });

    test('il riquadro dice le tre linee; le scritte almeno a 11 px; la descrizione per chi non vede il grafico', () => {
        const o = opzioniDelGraficoGrande(ESEMPIO(), colori);
        expect(o.plugins.legend.display).toBe(false);
        expect(o.plugins.tooltip.callbacks.label({ dataset: { label: 'Se pagano tutti' }, parsed: { y: 8055 } })).toBe('Se pagano tutti: € 8.055,00');
        expect(o.scales.x.ticks.font.size).toBeGreaterThanOrEqual(11);
        expect(o.scales.y.grid.color({ tick: { value: 0 } })).toBe(colori.zero);
        expect(descrizioneDelGraficoGrande(ESEMPIO(), 0)).toBe('Il saldo nei prossimi 30 giorni in tre linee. La simulazione, con € 0,00 recuperati delle rate scadute: '
            + 'al più basso −€ 2.368,77, fra 30 giorni € 31,23. Se pagano tutti: fra 30 giorni € 6.624,20. Senza le rate: fra 30 giorni −€ 2.368,77.');
    });
});

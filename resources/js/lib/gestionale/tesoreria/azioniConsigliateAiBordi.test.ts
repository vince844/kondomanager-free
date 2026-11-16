// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le azioni consigliate ai bordi delle loro regole, con le cifre fatte a mano (oggi il 15/11/2026, trentuno giorni):
 *
 * - (E) «Anche con tutte le rate» conta anche le rate approvate e non ancora emesse (C), al loro giorno: se coprono il buco il piano straordinario non c'è; se
 *   non lo coprono, la cifra che manca le conta e il titolo lo dice. Con il punto più basso esattamente a € 0,00 non c'è.
 * - (C) Con più di 8 rate da emettere il titolo dice «dal primo giorno in poi», mai la data dell'ottava come se fosse quella di tutte; il verbo («scadono»,
 *   «scadevano») guarda l'ultima rata del piano nella finestra. Il server manda, oltre alle 8 nominate, le scadenze di tutte (`scadenze`, per giorno).
 * - (G) Un conto in banca è sotto zero quando lo dice la banca, con i fondi dentro (`saldo`); un conto dà a un altro solo la sua parte libera (`libero`).
 * - (H) Una ritenuta che scade oggi non è «scaduta»: si paga entro oggi.
 */
import { describe, expect, test } from 'vitest';
import { azioniConsigliate, testoDiPezzi, type AzioneConsigliata, type DatiDelleAzioni, type PianoDaEmettere, type Recupero } from './azioniConsigliate';
import type { FatturaDelGiorno, GiornoDelSaldo, SaldoPrevisto } from './saldoPrevisto';

const vuoto = { uscite: 0, quante_uscite: 0, voci_uscite: [], entrate: 0, quante_entrate: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [], rate_in_scadenza: 0,
    quante_rate_in_scadenza: 0, fatture: [] as FatturaDelGiorno[] };

/** Trentuno giorni dal 15/11/2026: il saldo di oggi, le fatture per giorno (l'indice), le rate emesse in scadenza per giorno, le rate già scadute. */
function tesoreria(oggi: number, fatture: Record<number, number>, scadute = 0, rate: Record<number, number> = {}): SaldoPrevisto {
    const giorni: GiornoDelSaldo[] = [];
    let s = oggi;
    for (let i = 0; i <= 30; i++) {
        const uscite = fatture[i] ?? 0;
        s -= uscite;
        giorni.push({ ...vuoto, giorno: new Date(Date.UTC(2026, 10, 15 + i)).toISOString().slice(0, 10), saldo: s, con_entrate: s + scadute, uscite,
            quante_uscite: uscite ? 1 : 0, fatture: uscite ? [{ id: i, fornitore: 'Enel', numero: `${i}/2026`, importo: uscite }] : [], rate_in_scadenza: rate[i] ?? 0,
            quante_rate_in_scadenza: rate[i] ? 1 : 0 });
    }

    return { giorni, primoSottoZero: giorni.find((g) => g.saldo < 0)?.giorno ?? null, minimoCents: Math.min(0, ...giorni.map((g) => g.saldo)), finaleCents: s,
        finaleConEntrateCents: s + scadute, rateScaduteCents: scadute };
}

const DATI = (altro: Partial<DatiDelleAzioni> = {}): DatiDelleAzioni => ({ rateDaEmettere: [], bonifici: { quanti: 0, cifra: 0 }, ritenute: { scadenze: [] },
    deficit: { quanti: 0, cifra: 0 }, esercizioId: 25, conti: [], fondi: [], ...altro });
const NESSUNO: Recupero = { persone: [], servono: 0, servonoCents: 0, quante: 0, cifra: 0 };
const piano = (rate: Array<[number, string, number]>, altro: Partial<PianoDaEmettere> = {}): PianoDaEmettere => ({ pianoId: 5, piano: 'Preventivo 2026', esercizioId: 25,
    rate: rate.map(([numero, scadenza, cifra], i) => ({ rataId: 50 + i, numero, scadenza, cifra })), quante: rate.length, cifra: rate.reduce((t, r) => t + r[2], 0), ...altro });
const azione = (azioni: AzioneConsigliata[], chiave: AzioneConsigliata['chiave']) => azioni.find((a) => a.chiave === chiave);
const chiavi = (azioni: AzioneConsigliata[]) => azioni.map((a) => a.chiave);

describe('(E) e le rate non ancora emesse', () => {
    test('una rata da emettere di € 1.500,00 il 20/11 copre il buco di € 1.000,00 del 25/11: c\'è «Emetti la rata», non il piano straordinario', () => {
        // Saldo € 0,00; il 25/11 (giorno 10) una fattura di € 1.000,00; nessuna rata emessa. Emessa e pagata, la rata entra prima: il conto resta a € 500,00.
        const azioni = azioniConsigliate(tesoreria(0, { 10: 100000 }), NESSUNO, DATI({ rateDaEmettere: [piano([[1, '2026-11-20', 150000]])] }));
        expect(chiavi(azioni)).toContain('emissione');
        expect(chiavi(azioni)).not.toContain('straordinario');
    });

    test('una rata da emettere già scaduta entra oggi, e copre il buco', () => {
        const azioni = azioniConsigliate(tesoreria(0, { 10: 100000 }), NESSUNO, DATI({ rateDaEmettere: [piano([[3, '2026-10-05', 100000]])] }));
        expect(chiavi(azioni)).not.toContain('straordinario');
    });

    test('una rata da emettere che arriva dopo il buco non lo copre in tempo: manca ancora, e il titolo dice che conta anche le rate da emettere', () => {
        // Saldo € 0,00; il 20/11 (giorno 5) una fattura di € 1.000,00; la rata da emettere di € 300,00 scade il 30/11. Il punto più basso è il 20/11, −€ 1.000,00.
        const e = azione(azioniConsigliate(tesoreria(0, { 5: 100000 }), NESSUNO, DATI({ rateDaEmettere: [piano([[1, '2026-11-30', 30000]])] })), 'straordinario')!;
        expect(e.titolo).toBe('Manca € 1.000,00 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
        expect(testoDiPezzi(e.frase)).toContain('Anche se pagano tutti, rate già scadute e rate da emettere comprese, il conto va sotto zero il 20/11');
    });

    test('le rate da emettere riducono la cifra che manca: € 1.000,00 di buco, € 400,00 di rate da emettere prima, mancano € 600,00', () => {
        const e = azione(azioniConsigliate(tesoreria(0, { 10: 100000 }), NESSUNO, DATI({ rateDaEmettere: [piano([[1, '2026-11-17', 40000]])] })), 'straordinario')!;
        expect(e.titolo).toBe('Manca € 600,00 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
    });

    test('le rate oltre le 8 nominate entrano al loro giorno: il server manda le scadenze di tutte', () => {
        // Dieci rate da € 100,00: le 8 nominate dal 16/11 al 23/11, la nona il 25/11, la decima il 12/12. Una fattura di € 850,00 il 01/12 (giorno 16).
        const date = ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23'];
        const dieci = (nona: string) => piano(date.map((d, i) => [i + 1, d, 10000] as [number, string, number]), { quante: 10, cifra: 100000,
            scadenze: [...date.map((giorno) => ({ giorno, cifra: 10000 })), { giorno: nona, cifra: 10000 }, { giorno: '2026-12-12', cifra: 10000 }] });
        // La nona arriva il 25/11: il 01/12 il conto ha € 900,00 e scende a € 50,00. Nessun piano straordinario.
        expect(azione(azioniConsigliate(tesoreria(0, { 16: 85000 }), NESSUNO, DATI({ rateDaEmettere: [dieci('2026-11-25')] })), 'straordinario')).toBeUndefined();
        // La nona arriva il 05/12, dopo la fattura: il 01/12 il conto ha € 800,00 e scende a −€ 50,00.
        expect(azione(azioniConsigliate(tesoreria(0, { 16: 85000 }), NESSUNO, DATI({ rateDaEmettere: [dieci('2026-12-05')] })), 'straordinario')!.titolo)
            .toBe('Manca € 50,00 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
    });

    test('senza rate da emettere il titolo resta com\'era', () => {
        const e = azione(azioniConsigliate(tesoreria(0, { 5: 70000 }), NESSUNO, DATI()), 'straordinario')!;
        expect(e.titolo).toBe('Manca € 700,00 anche con tutte le rate: serve un versamento straordinario');
    });

    test('se pagano tutti il conto arriva a zero ma non sotto: nessun piano straordinario', () => {
        // € 100,00 oggi, una fattura di € 100,00 il 20/11, nessuna rata: il punto più basso è € 0,00.
        expect(azione(azioniConsigliate(tesoreria(10000, { 5: 10000 }), NESSUNO, DATI()), 'straordinario')).toBeUndefined();
    });
});

describe('(C) con più di 8 rate da emettere', () => {
    const date = ['2026-11-17', '2026-11-20', '2026-11-24', '2026-11-27', '2026-12-01', '2026-12-04', '2026-12-08', '2026-12-11'];
    const nove = (nona: string) => piano(date.map((d, i) => [i + 1, d, 10000] as [number, string, number]), { quante: 9, cifra: 90000,
        scadenze: [...date.map((giorno) => ({ giorno, cifra: 10000 })), { giorno: nona, cifra: 10000 }] });

    test('il titolo dice «dal primo giorno in poi», non che tutte e nove portano il denaro entro il giorno dell\'ottava', () => {
        const c = azione(azioniConsigliate(tesoreria(100000, {}), NESSUNO, DATI({ rateDaEmettere: [nove('2026-12-14')] })), 'emissione')!;
        expect(c.titolo).toBe('Emetti 9 rate del piano «Preventivo 2026»: porterebbero € 900,00, scadono dal 17/11 in poi');
        expect(c.titolo).not.toContain('11/12');
    });

    test('le 8 nominate già scadute e l\'ultima del piano ancora da venire: «scadono», non «scadevano»', () => {
        const passate = ['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10', '2026-10-17', '2026-10-24'];
        const con = (nona: string) => piano(passate.map((d, i) => [i + 1, d, 10000] as [number, string, number]), { quante: 9, cifra: 90000,
            scadenze: [...passate.map((giorno) => ({ giorno, cifra: 10000 })), { giorno: nona, cifra: 10000 }] });
        const p = con('2026-11-20');
        const c = azione(azioniConsigliate(tesoreria(100000, {}), NESSUNO, DATI({ rateDaEmettere: [p] })), 'emissione')!;
        expect(c.titolo).toBe('Emetti 9 rate del piano «Preventivo 2026»: porterebbero € 900,00, scadono dal 05/09 in poi');
        // Tutte già scadute: «scadevano».
        expect(azione(azioniConsigliate(tesoreria(100000, {}), NESSUNO, DATI({ rateDaEmettere: [con('2026-10-31')] })), 'emissione')!.titolo)
            .toBe('Emetti 9 rate del piano «Preventivo 2026»: porterebbero € 900,00, scadevano dal 05/09 in poi');
    });
});

describe('(G) il conto in banca come lo dice la banca', () => {
    test('la banca a € 1.000,00 con il fondo dentro e la parte libera a −€ 1.000,00 non è sotto zero: nessun giroconto', () => {
        const azioni = azioniConsigliate(tesoreria(500000, {}), NESSUNO, DATI({ conti: [
            { cassaId: 1, nome: 'Conto corrente', tipo: 'banca', saldo: 100000, libero: -100000, attiva: true },
            { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 50000, libero: 50000, attiva: true },
        ] }));
        expect(azione(azioni, 'giroconto')).toBeUndefined();
    });

    test('la banca davvero sotto zero: il giroconto dai contanti, della cifra che dice la banca', () => {
        const g = azione(azioniConsigliate(tesoreria(500000, {}), NESSUNO, DATI({ conti: [
            { cassaId: 1, nome: 'Conto corrente', tipo: 'banca', saldo: -30000, libero: -230000, attiva: true },
            { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 50000, libero: 50000, attiva: true },
        ] })), 'giroconto')!;
        expect(g.titolo).toBe('Sposta € 300,00 da Cassa contanti a Conto corrente');
        expect(testoDiPezzi(g.frase)).toBe('Oggi Conto corrente è sotto zero di € 300,00 e Cassa contanti ha € 500,00: con il giroconto Conto corrente torna a zero.');
        expect(g.pulsante).toMatchObject({ origine: 2, destinazione: 1, importo: 30000 });
    });

    test('una banca dà solo la sua parte libera, non il denaro dei fondi', () => {
        // I contanti sono sotto zero di € 500,00; la banca dice € 1.500,00, ma la parte libera è € 200,00 (€ 1.300,00 sono nei fondi).
        const g = azione(azioniConsigliate(tesoreria(500000, {}), NESSUNO, DATI({ conti: [
            { cassaId: 1, nome: 'Conto corrente', tipo: 'banca', saldo: 150000, libero: 20000, attiva: true },
            { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: -50000, libero: -50000, attiva: true },
        ] })), 'giroconto')!;
        expect(g.titolo).toBe('Sposta € 200,00 da Conto corrente a Cassa contanti');
        expect(testoDiPezzi(g.frase)).toBe('Oggi Cassa contanti è sotto zero di € 500,00 e Conto corrente ha € 200,00 fuori dai fondi: è tutto quello che può dare, '
            + 'e Cassa contanti resta sotto zero di € 300,00.');
    });
});

describe('(H) le ritenute ai bordi', () => {
    test('una ritenuta che scade oggi non è «scaduta»: si paga entro oggi', () => {
        const h = azione(azioniConsigliate(tesoreria(500000, {}), NESSUNO, DATI({ ritenute: { scadenze: [{ giorno: '2026-11-15', cifra: 30000 }] } })), 'ritenute')!;
        expect(h.titolo).toBe('Paga per prime le ritenute, € 300,00 entro il 15/11');
    });
});

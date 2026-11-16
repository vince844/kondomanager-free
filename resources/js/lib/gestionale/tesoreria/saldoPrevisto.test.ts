// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * I calcoli del grafico «Saldo previsto» della tesoreria: un punto per giorno, a gradini, dal giorno di oggi a fra 30 giorni; le date in
 * basso come «18/11»; il primo giorno sotto zero, lo stesso della scritta in testa; la linea dello zero con «€ 0»; la zona sotto zero rossa (`fill` con
 * `below`); una serie sola, senza legenda; il riquadro al passaggio del mouse in italiano, con il saldo del giorno e che cosa scade; la descrizione per chi non
 * vede il grafico e la tabella dei giorni.
 *
 * I tre scenari: «Senza le rate» (il saldo del server), «Con le rate in scadenza» (in più le rate che scadono nei 30 giorni, ognuna al suo
 * giorno), «Se pagano tutti» (in più le rate già scadute, al giorno di oggi). Lo scenario cambia la linea, la scritta in testa, il punto e la cifra a 30 giorni.
 */
import { describe, expect, test } from 'vitest';
import {
    SCENARI, datiDelGrafico, descrizioneDelGrafico, euroAsse, euroSegno, finaleDelloScenario, giornoBreve, opzioniDelGrafico, puntiDelGrafico, righeDelRiquadro,
    righeDellaTabella, saldiDelloScenario, testoInTesta, type GiornoDelSaldo, type SaldoPrevisto,
} from './saldoPrevisto';

const vuoto = { uscite: 0, quante_uscite: 0, voci_uscite: [], entrate: 0, quante_entrate: 0, registrati: 0, quanti_registrati: 0, voci_registrati: [], rate_in_scadenza: 0,
    quante_rate_in_scadenza: 0, fatture: [] };
/** Trentuno giorni dal 15/11/2026; i cambi dati per giorno: [indice, saldo, altro]. */
function saldo(iniziale: number, cambi: Array<[number, number, Partial<GiornoDelSaldo>?]>, rateScadute = 0): SaldoPrevisto {
    const giorni: GiornoDelSaldo[] = [];
    let s = iniziale;
    let rate = 0;
    for (let i = 0; i <= 30; i++) {
        const d = new Date(Date.UTC(2026, 10, 15 + i)).toISOString().slice(0, 10);
        const c = cambi.find(([k]) => k === i);
        if (c) s = c[1];
        const g: GiornoDelSaldo = { giorno: d, saldo: s, con_entrate: 0, ...vuoto, ...(c?.[2] ?? {}) };
        rate += g.rate_in_scadenza ?? 0;
        g.con_entrate = s + rateScadute + rate;
        giorni.push(g);
    }
    const primo = giorni.find((g) => g.saldo < 0)?.giorno ?? null;

    return { giorni, primoSottoZero: primo, minimoCents: Math.min(0, ...giorni.map((g) => g.saldo)), finaleCents: s, finaleConEntrateCents: s + rateScadute + rate,
        rateScaduteCents: rateScadute };
}

const colori = { linea: 'rgb(13, 148, 136)', rosso: 'rgb(220, 38, 38)', zona: 'rgba(220, 38, 38, 0.15)', testo: 'rgb(100, 116, 139)', griglia: 'rgb(226, 232, 240)', zero: 'rgb(15, 23, 42)', sfondo: 'rgb(255, 255, 255)' };

/**
 * Il condominio dell'esempio della guida: oggi € 1.462,03; il 20/11 tre fatture, € 3.830,80; il 30/11 scadono € 2.400,00 di rate; € 6.592,97 di rate già
 * scadute.
 */
const esempio = () => saldo(146203, [
    [5, -236877, { uscite: 383080, quante_uscite: 3 }],
    [15, -236877, { rate_in_scadenza: 240000, quante_rate_in_scadenza: 6 }],
], 659297);

describe('le cifre e le date all\'italiana', () => {
    test('«18/11», «−€ 1.852,00», «€ 0», «€ 1.000», «−€ 500»', () => {
        expect(giornoBreve('2026-11-18')).toBe('18/11');
        expect(euroSegno(-185200)).toBe('−€ 1.852,00');
        expect(euroSegno(0)).toBe('€ 0,00');
        expect(euroSegno(185200)).toBe('€ 1.852,00');
        expect(euroAsse(0)).toBe('€ 0');
        expect(euroAsse(1000)).toBe('€ 1.000');
        expect(euroAsse(-500)).toBe('−€ 500');
    });
});

describe('i tre scenari', () => {
    test('le tre scelte, con la frase di che cosa contano; all\'apertura «Senza le rate»', () => {
        expect(SCENARI.map((s) => s.etichetta)).toEqual(['Senza le rate', 'Con le rate in scadenza', 'Se pagano tutti']);
        expect(SCENARI[0].chiave).toBe('senza');
        expect(SCENARI.map((s) => s.frase)).toEqual([
            'Solo le fatture e i movimenti già registrati.',
            'In più le rate dei condòmini che scadono nei 30 giorni, ognuna al suo giorno; le rate già scadute no.',
            'In più anche le rate già scadute, al giorno di oggi.',
        ]);
    });

    test('«Senza le rate» è il saldo del server; «Con le rate in scadenza» aggiunge le rate al loro giorno; «Se pagano tutti» anche le scadute, da oggi', () => {
        const sp = esempio();
        const senza = saldiDelloScenario(sp, 'senza');
        const con = saldiDelloScenario(sp, 'con_rate');
        const tutti = saldiDelloScenario(sp, 'tutti');
        expect(senza.slice(0, 6)).toEqual([146203, 146203, 146203, 146203, 146203, -236877]);
        expect(senza[30]).toBe(-236877);
        expect(con[14]).toBe(-236877);
        expect(con[15]).toBe(3123);
        expect(con[30]).toBe(3123);
        expect(tutti[0]).toBe(805500);
        expect(tutti[5]).toBe(422420);
        expect(tutti[15]).toBe(662420);
        // «Se pagano tutti» è il saldo con le entrate attese che il server manda.
        expect(tutti).toEqual(sp.giorni.map((g) => g.con_entrate));
    });

    test('per ogni scenario: i gradini, il primo giorno sotto zero con il suo punto, la scritta in testa, la cifra a 30 giorni', () => {
        const sp = esempio();
        const senza = puntiDelGrafico(sp, 'senza');
        expect(senza.indiceSottoZero).toBe(5);
        expect(senza.raggi.filter((r) => r > 0)).toHaveLength(1);
        expect(testoInTesta(sp, 'senza')).toBe('sotto zero dal 20/11');
        expect(finaleDelloScenario(sp, 'senza')).toBe(-236877);

        const con = puntiDelGrafico(sp, 'con_rate');
        expect(con.valori[15]).toBe(31.23);
        expect(con.indiceSottoZero).toBe(5);
        expect(testoInTesta(sp, 'con_rate')).toBe('sotto zero dal 20/11');
        expect(finaleDelloScenario(sp, 'con_rate')).toBe(3123);

        const tutti = puntiDelGrafico(sp, 'tutti');
        expect(tutti.indiceSottoZero).toBeNull();
        expect(tutti.raggi.every((r) => r === 0)).toBe(true);
        expect(testoInTesta(sp, 'tutti')).toBe('mai sotto zero');
        expect(finaleDelloScenario(sp, 'tutti')).toBe(662420);
    });

    test('lo scenario senza le rate sotto zero solo con le fatture, sopra con le rate: la scritta segue lo scenario', () => {
        const sp = saldo(50000, [[3, -30000, { uscite: 80000, quante_uscite: 1 }], [2, 50000, { rate_in_scadenza: 40000, quante_rate_in_scadenza: 2 }]]);
        expect(testoInTesta(sp, 'senza')).toBe('sotto zero dal 18/11');
        expect(testoInTesta(sp, 'con_rate')).toBe('mai sotto zero');
        expect(testoInTesta(saldo(-1000, []), 'senza')).toBe('sotto zero da oggi');
    });

    test('i dati per Chart.js seguono lo scenario: una serie sola a gradini, la zona rossa sotto zero, il punto sul primo giorno sotto zero', () => {
        const sp = esempio();
        for (const scenario of ['senza', 'con_rate', 'tutti'] as const) {
            const d = datiDelGrafico(sp, colori, scenario);
            expect(d.datasets).toHaveLength(1);
            expect(d.datasets[0].stepped).toBe(true);
            expect(d.datasets[0].data).toEqual(saldiDelloScenario(sp, scenario).map((v) => v / 100));
            expect(d.datasets[0].fill).toEqual({ target: { value: 0 }, above: 'transparent', below: colori.zona });
        }
        expect((datiDelGrafico(sp, colori, 'con_rate').datasets[0].pointRadius as number[])[5]).toBeGreaterThan(0);
        expect((datiDelGrafico(sp, colori, 'tutti').datasets[0].pointRadius as number[]).every((r) => r === 0)).toBe(true);
    });
});

describe('i punti del grafico', () => {
    test('un punto per giorno, da oggi a fra 30 giorni, in euro; le etichette in basso come «15/11»', () => {
        const p = puntiDelGrafico(saldo(50000, [[3, -30000], [10, 10000]]));
        expect(p.etichette).toHaveLength(31);
        expect(p.etichette[0]).toBe('15/11');
        expect(p.etichette[30]).toBe('15/12');
        expect(p.valori.slice(0, 4)).toEqual([500, 500, 500, -300]);
        expect(p.valori[10]).toBe(100);
    });

    test('il primo giorno sotto zero è quello della scritta in testa, e il suo è l\'unico punto disegnato', () => {
        const sp = saldo(50000, [[3, -30000], [5, -40000], [10, 10000]]);
        const p = puntiDelGrafico(sp);
        expect(p.indiceSottoZero).toBe(3);
        expect(p.etichette[p.indiceSottoZero!]).toBe('18/11');
        expect(testoInTesta(sp)).toBe('sotto zero dal 18/11');
        expect(p.raggi.filter((r) => r > 0)).toHaveLength(1);
        expect(p.raggi[3]).toBeGreaterThan(0);
    });

    test('sempre sopra zero: nessun punto e la scritta «mai sotto zero»; sotto zero da oggi, il punto sul primo giorno', () => {
        expect(puntiDelGrafico(saldo(50000, [])).indiceSottoZero).toBeNull();
        expect(testoInTesta(saldo(50000, []))).toBe('mai sotto zero');
        const daOggi = saldo(-1000, []);
        expect(puntiDelGrafico(daOggi).indiceSottoZero).toBe(0);
        expect(testoInTesta(daOggi)).toBe('sotto zero da oggi');
    });
});

describe('le opzioni per Chart.js', () => {
    test('nessuna legenda; le date in basso; la linea dello zero marcata con «€ 0»; le scritte almeno a 11 px; il riquadro in italiano con lo scenario', () => {
        const sp = saldo(50000, [[3, -30000, { uscite: 80000, quante_uscite: 1, voci_uscite: [{ voce: 'Edil Rossi srl — n. 45/2026', importo: 80000 }] }]]);
        const o = opzioniDelGrafico(sp, colori);
        expect(o.plugins.legend.display).toBe(false);
        expect(o.scales.x.position).toBe('bottom');
        expect(o.scales.y.ticks.callback(0)).toBe('€ 0');
        expect(o.scales.y.ticks.callback(1500)).toBe('€ 1.500');
        expect(o.scales.y.grid.color({ tick: { value: 0 } })).toBe(colori.zero);
        expect(o.scales.y.grid.color({ tick: { value: 500 } })).toBe(colori.griglia);
        expect(o.scales.y.grid.lineWidth({ tick: { value: 0 } })).toBeGreaterThan(o.scales.y.grid.lineWidth({ tick: { value: 500 } }));
        expect(o.scales.x.ticks.font.size).toBeGreaterThanOrEqual(11);
        expect(o.scales.y.ticks.font.size).toBeGreaterThanOrEqual(11);
        expect(o.plugins.tooltip.callbacks.title([{ dataIndex: 3 }])).toBe('18/11/2026');
        expect(o.plugins.tooltip.callbacks.label({ dataIndex: 3 })).toEqual(righeDelRiquadro(sp, 3));
        expect(opzioniDelGrafico(sp, colori, 'con_rate').plugins.tooltip.callbacks.label({ dataIndex: 3 })).toEqual(righeDelRiquadro(sp, 3, 'con_rate'));
        // Lo zero c'è sempre sull'asse, anche quando il saldo non ci arriva.
        expect(o.scales.y.beginAtZero).toBe(true);
    });
});

describe('il riquadro al passaggio del mouse, la descrizione e la tabella', () => {
    const giorno: Partial<GiornoDelSaldo> = { uscite: 95000, quante_uscite: 5,
        voci_uscite: [{ voce: 'Edil Rossi srl — n. 45/2026', importo: 80000 }, { voce: 'Enel — n. 7', importo: 10000 }, { voce: 'Acqua — n. 3', importo: 3000 }],
        entrate: 40000, quante_entrate: 4, rate_in_scadenza: 40000, quante_rate_in_scadenza: 4, registrati: -5000, quanti_registrati: 1,
        voci_registrati: [{ voce: 'Pagamento Ascensori', importo: -5000 }] };

    test('il saldo del giorno nello scenario e che cosa scade: le fatture, le altre contate, i movimenti registrati, le rate dei condòmini', () => {
        const sp = saldo(50000, [[3, -30000, giorno]], 20000);
        expect(righeDelRiquadro(sp, 3)).toEqual([
            'Saldo previsto senza le rate: −€ 300,00',
            'Scade: Edil Rossi srl — n. 45/2026, € 800,00',
            'Scade: Enel — n. 7, € 100,00',
            'Scade: Acqua — n. 3, € 30,00',
            'e altre 2 fatture, € 20,00',
            'Già registrato: Pagamento Ascensori, −€ 50,00',
            'Rate dei condòmini che scadono: 4, € 400,00 (non sono in questo saldo)',
        ]);
        expect(righeDelRiquadro(sp, 3, 'con_rate')[0]).toBe('Saldo previsto con le rate in scadenza: € 100,00');
        expect(righeDelRiquadro(sp, 3, 'con_rate').at(-1)).toBe('Rate dei condòmini che scadono: 4, € 400,00');
        expect(righeDelRiquadro(sp, 3, 'tutti')[0]).toBe('Saldo previsto se pagano tutti: € 300,00');
        expect(righeDelRiquadro(sp, 0, 'tutti')).toEqual(['Saldo previsto se pagano tutti: € 700,00', 'Rate già scadute, se pagano tutti: € 200,00']);
        expect(righeDelRiquadro(saldo(50000, [[3, -30000]]), 3)).toEqual(['Saldo previsto senza le rate: −€ 300,00', 'Niente in scadenza']);
    });

    test('la descrizione dice lo scenario, oggi, fra 30 giorni e da quando è sotto zero', () => {
        expect(descrizioneDelGrafico(saldo(50000, [[3, -30000]]))).toBe(
            'Saldo previsto giorno per giorno, senza le rate: oggi € 500,00, fra 30 giorni −€ 300,00; sotto zero dal 18/11/2026, al più basso −€ 300,00.');
        expect(descrizioneDelGrafico(saldo(50000, []))).toBe('Saldo previsto giorno per giorno, senza le rate: oggi € 500,00, fra 30 giorni € 500,00; mai sotto zero.');
        expect(descrizioneDelGrafico(esempio(), 'con_rate')).toBe(
            'Saldo previsto giorno per giorno, con le rate in scadenza: oggi € 1.462,03, fra 30 giorni € 31,23; sotto zero dal 20/11/2026, al più basso −€ 2.368,77.');
        expect(descrizioneDelGrafico(esempio(), 'tutti')).toBe('Saldo previsto giorno per giorno, se pagano tutti: oggi € 8.055,00, fra 30 giorni € 6.624,20; mai sotto zero.');
    });

    test('la tabella dei giorni nello scenario: oggi, ogni giorno in cui cambia qualcosa, l\'ultimo', () => {
        const sp = saldo(50000, [[3, -30000, { uscite: 80000, quante_uscite: 1, voci_uscite: [{ voce: 'Edil Rossi srl — n. 45/2026', importo: 80000 }] }],
            [10, 10000, { registrati: 40000, quanti_registrati: 1, voci_registrati: [{ voce: 'Incasso', importo: 40000 }] }]]);
        const r = righeDellaTabella(sp);
        expect(r.map((x) => x.giorno)).toEqual(['15/11/2026', '18/11/2026', '25/11/2026', '15/12/2026']);
        expect(r[1]).toEqual({ giorno: '18/11/2026', saldo: '−€ 300,00', sottoZero: true, cheCosa: 'Scade: Edil Rossi srl — n. 45/2026, € 800,00' });
        expect(r[0].cheCosa).toBe('Oggi');
        expect(r[3].cheCosa).toBe('Fra 30 giorni');
        expect(righeDellaTabella(esempio(), 'con_rate').map((x) => [x.giorno, x.saldo])).toEqual([
            ['15/11/2026', '€ 1.462,03'], ['20/11/2026', '−€ 2.368,77'], ['30/11/2026', '€ 31,23'], ['15/12/2026', '€ 31,23']]);
    });
});

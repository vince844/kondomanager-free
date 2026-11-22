// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I conti della sala sul posto, senza rete: sullo stesso file di casi del server (`tests/Fixtures/sala/conti.json`, che `ContiDellaSalaTest` legge con
 * `ContiDellaSala`), la stessa costituzione, lo stesso esito proposto, lo stesso conflitto determinante, lo stesso quinto, lo stesso esito per condizione,
 * con le stesse frasi. Le condizioni dicono l'esito e non lo rifanno: su ogni caso dell'esito passano tutte e due solo quando la proposta è «approvata».
 * In più: chi è presente dalle presenze e dalle deleghe, e la barra dalla fotografia.
 *
 * Che cosa non copre: la fotografia (la fa il server all'apertura) e il voto confermato (lo conferma il server, che ricalcola da sé).
 */
import { describe, expect, test } from 'vitest';
import casi from '../../../../../tests/Fixtures/sala/conti.json';
import { barra, condizioni, conflitto, contiSenzaSoglia, costituzione, delegaConta, esito, millesimi, millesimiPerEccesso, personePresenti, presentiSenzaVoto, quinto, testePresenti } from './conti';

type Caso = Record<string, any>;
const del = (tipo: string) => (casi as Caso[]).filter((c) => c.tipo === tipo).map((c) => [c.che_cosa, c] as const);
const soloLeChiavi = (r: Record<string, unknown>, atteso: Record<string, unknown>) => Object.fromEntries(Object.keys(atteso).map((k) => [k, r[k]]));

describe('i conti della sala sono quelli del server', () => {
    test('il file ha tutti e quattro i conti, e la regola delle deleghe', () => {
        expect(del('delega_conta').length).toBeGreaterThanOrEqual(7);
        // Con i quattro confini della costituzione (decisione 640 (4)): 13 su 26 in prima, 9 su 27 in seconda, 666,66667 e 666,66666 in prima.
        expect(del('costituzione').length).toBeGreaterThanOrEqual(10);
        expect(del('esito').length).toBeGreaterThanOrEqual(10);
        expect(del('conflitto').length).toBeGreaterThanOrEqual(2);
        expect(del('quinto').length).toBeGreaterThanOrEqual(5);
        expect(del('condizioni').length).toBeGreaterThanOrEqual(10);
    });

    test.each(del('costituzione'))('la costituzione: %s', (_n, c) => {
        expect(soloLeChiavi(costituzione(c.convocazione, c.totale_teste, c.totale_valore, c.teste, c.valore), c.atteso)).toEqual(c.atteso);
    });

    test.each(del('esito'))('l\'esito proposto: %s', (_n, c) => {
        expect(esito(c.maggioranza, c.convocazione, c.totale_teste, c.totale_valore, c.intervenuti, c.favorevoli_teste, c.favorevoli_valore)).toBe(c.atteso.esito);
    });

    test.each(del('condizioni'))('l\'esito per condizione: %s', (_n, c) => {
        expect(condizioni(c.maggioranza, c.convocazione, c.totale_teste, c.totale_valore, c.intervenuti, c.favorevoli_teste, c.favorevoli_valore)).toEqual(c.atteso);
    });

    test.each(del('esito'))('le condizioni dicono l\'esito, non lo rifanno: %s', (_n, c) => {
        const r = condizioni(c.maggioranza, c.convocazione, c.totale_teste, c.totale_valore, c.intervenuti, c.favorevoli_teste, c.favorevoli_valore);
        if (c.atteso.esito !== 'approvata' && c.atteso.esito !== 'respinta') {
            expect(r).toBeNull();
            return;
        }
        expect(r!.map((x) => x.condizione)).toEqual(['teste', 'valore']);
        expect(r!.every((x) => x.passa)).toBe(c.atteso.esito === 'approvata');
    });

    test.each(del('conflitto'))('il conflitto: %s', (_n, c) => {
        const r = conflitto(c.maggioranza, c.convocazione, c.totale_teste, c.totale_valore, c.intervenuti, c.favorevoli_teste, c.favorevoli_valore,
            c.conflitto_teste, c.conflitto_valore);
        expect(soloLeChiavi(r, c.atteso)).toEqual(c.atteso);
    });

    test.each(del('delega_conta'))('la delega conta: %s', (_n, c) => {
        expect(delegaConta(c.punti, c.da_decidere, c.punto)).toBe(c.atteso);
    });

    test.each(del('quinto'))('il quinto: %s', (_n, c) => {
        expect(soloLeChiavi(quinto(c.totale_teste, c.totale_valore, c.teste, c.valore), c.atteso)).toEqual(c.atteso);
    });

    test('i millesimi all\'italiana, quelli che servono per eccesso', () => {
        expect(millesimi(48460000)).toBe('484,60');
        expect(millesimi(100000000)).toBe('1.000,00');
        expect(millesimi(33333334)).toBe('333,33');
        expect(millesimiPerEccesso(33333334)).toBe('333,34');
        expect(millesimiPerEccesso(33280000)).toBe('332,80');
    });
});

describe('chi è presente, e la barra', () => {
    const teste = [
        { chiave: 'p-1', nome: 'Rossi Mario', persone: [1], unita: [3], millesimi: 6000000 },
        { chiave: 'p-2-3', nome: 'Russo Anna e Russo Marco', persone: [2, 3], unita: [5], millesimi: 7000000 },
        { chiave: 'p-4', nome: 'Gallo Enzo', persone: [4], unita: [7], millesimi: 7500000 },
    ];

    test('la persona in sala, quella in collegamento, non quella uscita; il delegante con il delegato presente, non il delegato assente', () => {
        const presenti = personePresenti(
            [
                { chiave: 'anagrafica:1', anagrafica_id: 1, uscita_il: null },
                { chiave: 'anagrafica:3', anagrafica_id: 3, uscita_il: '2026-12-15T18:50:00Z' },
                { chiave: 'delega:9', anagrafica_id: null, uscita_il: null },
            ],
            [
                { id: 9, delegante_anagrafica_id: 4, delegato_anagrafica_id: null, stato: 'valida' },
                { id: 10, delegante_anagrafica_id: 2, delegato_anagrafica_id: 1, stato: 'rifiutata' },
            ],
        );
        expect([...presenti].sort()).toEqual([1, 4]);
        expect(testePresenti(teste, presenti)).toEqual({ teste: 2, valore: 13500000 });
    });

    test('la delega per alcuni punti porta presente chi ha delegato, e sul voto vale solo per i suoi punti; la delega «da decidere» non conta', () => {
        const presenze = [{ chiave: 'anagrafica:4', anagrafica_id: 4, uscita_il: null }];
        const deleghe = [
            { id: 1, delegante_anagrafica_id: 1, delegato_anagrafica_id: 4, stato: 'valida', punti: [41] },
            { id: 2, delegante_anagrafica_id: 2, delegato_anagrafica_id: 4, stato: 'valida', punti: null, da_decidere: true },
        ];
        expect([...personePresenti(presenze, deleghe)].sort()).toEqual([1, 4]);
        expect([...personePresenti(presenze, deleghe, 41)].sort()).toEqual([1, 4]);
        expect([...personePresenti(presenze, deleghe, 44)].sort()).toEqual([4]);
    });

    test('una testa di due persone è presente con una sola di loro', () => {
        const presenti = personePresenti([{ chiave: 'anagrafica:3', anagrafica_id: 3, uscita_il: null }], []);
        expect(testePresenti(teste, presenti)).toEqual({ teste: 1, valore: 7000000 });
    });

    test('la barra della costituzione dalla fotografia', () => {
        const fotografia = { convocazione: 'seconda' as const, costituzione: { totale_teste: 3, totale_valore: 20500000, teste } };
        expect(barra(fotografia, new Set([1]))).toMatchObject({ costituita: false, barra: '1 su 3 · 60,00 su 205,00', mancano: 'mancano 8,34 millesimi' });
        expect(barra(fotografia, new Set([1, 4]))).toMatchObject({ costituita: true, barra: '2 su 3 · 135,00 su 205,00', mancano: null });
    });
});

describe('i conti senza soglia (B1): quando decide il presidente le teste e il valore dei favorevoli si dicono come gli altri punti', () => {
    test('12 su 12 e 732,60 su 1.000,00', () => {
        expect(contiSenzaSoglia(100000000, 12, 12, 73260000)).toEqual([
            { condizione: 'teste', passa: null, frase: 'Teste: 12 su 12' },
            { condizione: 'valore', passa: null, frase: 'Valore: 732,60 millesimi su 1.000,00' },
        ]);
    });
});

describe('i contitolari entrati con chi rappresenta l\'unità (C53)', () => {
    test('sono in sala senza voto: non fra le persone presenti, ma fra quelle presenti senza voto; usciti con la riga, non più', () => {
        const riga = { chiave: 'anagrafica:7', anagrafica_id: 7, uscita_il: null as string | null, insieme: [{ anagrafica_id: 6, nome: 'Russo Anna' }] };
        expect([...personePresenti([riga], [])]).toEqual([7]);
        expect([...presentiSenzaVoto([riga])]).toEqual([6]);
        expect([...presentiSenzaVoto([{ ...riga, uscita_il: '2026-12-15T19:00:00Z' }])]).toEqual([]);
    });
});

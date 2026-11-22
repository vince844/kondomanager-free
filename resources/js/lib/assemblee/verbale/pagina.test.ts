// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole della pagina «Il verbale», con l'esempio di Via Roma 12: i punti in parole, i trenta giorni di ognuno («calcolato in modo prudente»), chi ha la
 * copia di prima dopo «Sostituisci la copia», le discussioni già scritte, «entro il 22/12», chi scrive.
 */
import { describe, expect, test } from 'vitest';
import {
    CALCOLO_PRUDENTE, conLaCopiaDiPrima, daQuando, discussioniScritte, entroIl, fraseDiChiScrive, gruppiDeiDestinatari, lettereSoloStampate, puntiInParole,
    righeDeiTrentaGiorni, stampateConLaCopiaDiPrima,
} from './pagina';
import type { DestinatarioDelVerbale, StatoDiChiScrive, TrasmissioneDelVerbale } from '@/types/verbale';

const persona = (p: Partial<DestinatarioDelVerbale>): DestinatarioDelVerbale => ({ anagrafica_id: 1, nome: 'Galli Franco', unita: 'Int. 20', gruppo: 'mai_entrati',
    perche: 'assente a tutti i punti', punti_assente: [1, 2], voti: [], proposto: true, riceve: true, tolto: null, aggiunto: null, convocato: true,
    mezzo: { mezzo: 'pec', parola: 'PEC', dalla_convocazione: true, indirizzo: 'galli@pec.it', perche: null }, invio: null, termini: [], ...p });

describe('i punti in parole', () => {
    test('uno, due, una fila', () => {
        expect(puntiInParole([1])).toBe('punto 1');
        expect(puntiInParole([2, 5])).toBe('punti 2 e 5');
        expect(puntiInParole([4, 5, 6])).toBe('punti 4–6');
        expect(puntiInParole([1, 3, 4])).toBe('punti 1, 3 e 4');
    });
});

describe('i trenta giorni di una persona', () => {
    test('dalla consegna, fino al, contato come astenuto; sempre con il calcolo prudente', () => {
        const righe = righeDeiTrentaGiorni(persona({ termini: [
            { punti: [4, 5, 6], perche: 'assente', dal: null, fino_al: null, da_consegnare: true },
            { punti: [1], perche: 'non_votato', dal: '2026-12-15', fino_al: '2027-01-14', da_consegnare: false },
        ], voti: { 2: 'favorevole' } }));
        // Con la virgola, come si legge sotto «Può impugnare» (la prova a video del 22/11/2026, notte).
        expect(righe).toEqual(['punti 4–6, dalla consegna', 'punto 1, non ha votato, conta astenuto, fino al 14/01/2027', 'punto 2, favorevole, nessun termine']);
        expect(CALCOLO_PRUDENTE).toBe('calcolato in modo prudente');
    });
});

describe('i gruppi della lista', () => {
    test('chi non è mai entrato, chi era fuori a un punto, chi è stato aggiunto; chi c\'era resta a parte', () => {
        const t = { videoconferenza: false, punti: [], quanti: 3, spediti: 0, consegnati: 0, per_mezzo: {}, persone: [persona({}),
            persona({ anagrafica_id: 2, nome: 'Rossi Mario', gruppo: 'fuori' }), persona({ anagrafica_id: 3, nome: 'Romano Ida', gruppo: 'cera', riceve: false, proposto: false }),
            persona({ anagrafica_id: 4, nome: 'Ricci Elena', gruppo: 'aggiunti' }), persona({ anagrafica_id: 5, nome: 'Bianchi Anna', riceve: false, tolto: { perche: 'x', il: '', da: '' } })] } as TrasmissioneDelVerbale;
        const g = gruppiDeiDestinatari(t);
        expect(g.mai_entrati.map((p) => p.nome)).toEqual(['Galli Franco']);
        expect(g.fuori.map((p) => p.nome)).toEqual(['Rossi Mario']);
        expect(g.aggiunti.map((p) => p.nome)).toEqual(['Ricci Elena']);
        expect(g.cera.map((p) => p.nome)).toEqual(['Romano Ida']);
        expect(g.tolti.map((p) => p.nome)).toEqual(['Bianchi Anna']);
    });
});

describe('la copia di prima', () => {
    test('chi ha l\'invio partito prima della sostituzione ha la copia di prima', () => {
        const invio = (creato: string) => ({ id: 1, canale: 'pec', mezzo: 'pec', stato: 'inviata', esito: null, esito_parola: null, esito_il: null, ricevuta_il: null,
            spedito: true, errore: null, creato_il: creato });
        const t = { persone: [persona({ invio: invio('2026-12-16T10:00:00Z') }), persona({ anagrafica_id: 2, nome: 'Rossi Mario', invio: invio('2026-12-18T10:00:00Z') }),
            persona({ anagrafica_id: 3, nome: 'Neri Carla' })] } as unknown as TrasmissioneDelVerbale;
        expect(conLaCopiaDiPrima(t, '2026-12-17T09:00:00Z').map((p) => p.nome)).toEqual(['Galli Franco']);
        expect(conLaCopiaDiPrima(t, null)).toEqual([]);
    });
});

describe('le lettere stampate con la copia di prima, e da quando contano i trenta giorni', () => {
    const invio = (stato: string, creato: string) => ({ id: 1, canale: 'stampa', mezzo: 'raccomandata', stato, esito: null, esito_parola: null, esito_il: null,
        ricevuta_il: null, spedito: stato !== 'stampata', errore: null, creato_il: creato }) as unknown as DestinatarioDelVerbale['invio'];
    test('stampata il 16/12 e la copia sostituita il 17/12: si ristampa prima di spedirla; prima della sostituzione, la finestra lo dice', () => {
        const t = { persone: [persona({ nome: 'Bianchi Anna', invio: invio('stampata', '2026-12-16T09:00:00Z') }),
            persona({ anagrafica_id: 2, nome: 'Galli Franco', invio: invio('stampata', '2026-12-17T10:00:00Z') })] } as unknown as TrasmissioneDelVerbale;
        expect(stampateConLaCopiaDiPrima(t, '2026-12-17T09:00:00Z').map((p) => p.nome)).toEqual(['Bianchi Anna']);
        expect(lettereSoloStampate(t).map((p) => p.nome)).toEqual(['Bianchi Anna', 'Galli Franco']);
    });
    test('la raccomandata in giacenza: dal ritiro, o dalla compiuta giacenza', () => {
        expect(daQuando('ritiro', '2026-12-28')).toBe(' (dal ritiro del 28/12/2026)');
        expect(daQuando('compiuta_giacenza', '2026-12-28')).toBe(' (dalla compiuta giacenza, il 28/12/2026)');
        expect(daQuando('consegna', '2026-12-18')).toBe('');
        expect(righeDeiTrentaGiorni(persona({ termini: [{ punti: [1, 2], perche: 'assente', dal: '2026-12-28', fino_al: '2027-01-27', da_consegnare: false,
            dal_come: 'ritiro' }] }))[0]).toBe('punti 1 e 2, fino al 27/01/2027 (dal ritiro del 28/12/2026)');
    });
});

describe('le altre parole', () => {
    test('le discussioni già scritte e il giorno entro cui registrare', () => {
        const parti = { 'punto:1': { caratteri: 40 }, 'punto:2': { caratteri: 0 }, 'punto:3': { caratteri: 12 }, intero: { caratteri: 900 } };
        expect(discussioniScritte(parti as never, 8)).toBe('le discussioni di 2 punti su 8 già scritte');
        expect(discussioniScritte({} as never, 8)).toBe('nessuna discussione scritta, su 8 punti');
        expect(entroIl('2026-12-15T19:55:00Z')).toBe('22/12');
    });

    test('chi scrive: io, un altro, la richiesta per me, la risposta', () => {
        const base: StatoDiChiScrive = { parte: 'intero', scrive: null, richiesta: null, esito_della_richiesta: null, aperto_da: [] };
        expect(fraseDiChiScrive(base)).toBeNull();
        expect(fraseDiChiScrive({ ...base, scrive: { nome: 'Andrea Costa', dispositivo: 'x', sono_io: false, dal: '' } })).toBe('Andrea Costa sta scrivendo il verbale.');
        expect(fraseDiChiScrive({ ...base, parte: 'punto:4', scrive: { nome: 'Andrea Costa', dispositivo: 'x', sono_io: false, dal: '' } }, 4))
            .toBe('Andrea Costa scrive il punto 4.');
        expect(fraseDiChiScrive({ ...base, scrive: { nome: 'Andrea Costa', dispositivo: 'x', sono_io: true, dal: '' },
            aperto_da: [{ dispositivo: 'y', nome: 'Marco Galli', parte: 'intero' }] })).toBe('Il verbale è aperto anche da Marco Galli.');
    });
});

describe('«Rimetti 506,40»', () => {
    test('rimette la cifra dei voti al suo posto nella parte segnata, solo lì e solo quella volta', async () => {
        const { rimettiLaCifra } = await import('./pagina');
        const { datiDellaSala, documento, paragrafo, blocchi } = await import('./struttura');
        const doc = documento([paragrafo('Nel testo 496,40 resta.'), datiDellaSala('voto:12', ['Favorevoli: 9 condòmini per 496,40 millesimi (… 496,40 …).'])]);
        const nuovo = rimettiLaCifra(doc, { chiave: 'voto:12', occorrenza: 1, nel_testo: '496,40', adesso: '506,40' });
        expect(blocchi(nuovo)['voto:12']).toBe('Favorevoli: 9 condòmini per 506,40 millesimi (… 496,40 …).');
        expect(JSON.stringify(nuovo)).toContain('Nel testo 496,40 resta.');
        expect(rimettiLaCifra(doc, { chiave: 'voto:99', occorrenza: 1, nel_testo: '496,40', adesso: '506,40' })).toEqual(doc);
    });
});

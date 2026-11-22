// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Niente del lavoro senza rete si perde (decisione 519):
 *
 * - **la sala proiettata segue «Segna deserta»**: la prima deserta e non aperta porta «da aprire» sulla seconda (si apre oggi, il controllo lo rifà il server),
 *   e «Apri» apre la seconda; la seconda deserta (anche dopo l'appello) fa l'assemblea deserta; «Sospendi» e «Chiudi» chiudono le presenze;
 * - **lo stato tenuto avanza con il calendario**: «si apre» e il controllo si ricalcolano dal giorno della seduta e dall'ora di adesso;
 * - **i conti sul posto**: la delega «da decidere» non conta; quella per alcuni punti vale nella costituzione e sul voto solo dei suoi punti;
 * - **la coda**: l'ordine non dipende dall'orologio del dispositivo; un gesto che il server rifiuta non si toglie dal computer: resta «da rivedere», si
 *   rimanda (con un numero nuovo, e i gesti che ne dipendono con lui) o si toglie, solo per scelta.
 */
import { describe, expect, test } from 'vitest';
import { salaDaAprire, salaAperta, delega, presenza } from '@/components/assemblee/sala/salaDiProva';
import { CodaDeiGesti, riferimento } from './coda';
import { DepositoInMemoria } from './deposito';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';
import { proiettaLaSala } from './sala';
import { gestoDiProva as g } from './salaDiProva';

/** La sala prima della serata: la prima di lunedì 14/12 non ancora segnata deserta, il controllo letto alle 17:00 del 15/12 pulito. */
function primaDaSegnare() {
    const base = salaDaAprire();

    return { ...base, ora_del_server: '2026-12-15T16:00:00Z', fotografia: { ...base.fotografia!, convocazione: 'prima' as const },
        sedute: base.sedute.map((s) => (s.id === 1 ? { ...s, esito: null, chiusa_il: null, aperta_il: null } : s)),
        da_aprire: { seduta_id: 1, convocazione: 'prima' as const, numero: 1, si_apre: true, perche: null },
        controllo: { problemi: [], da_guardare: [], serve_la_scelta: false, frase: null } };
}

const adesso = new Date('2026-12-15T17:40:00Z');

describe('la sala proiettata segue gli stati', () => {
    test('«Segna deserta» la prima non aperta: «da aprire» passa alla seconda, il controllo lo rifà il server, e «Apri» apre la seconda', () => {
        const sala = primaDaSegnare();
        const p = proiettaLaSala(sala, [g('deserta', { seduta_id: 1 }, '2026-12-15T17:35:00Z')], adesso);
        expect(p.sedute.find((s) => s.id === 1)?.esito).toBe('deserta');
        expect(p.da_aprire).toEqual({ seduta_id: 2, convocazione: 'seconda', numero: 1, si_apre: true, perche: null });
        expect(p.controllo).toBeNull();
        expect(p.controllo_da_rifare).toBe(true);
        expect(p.fotografia?.convocazione).toBe('seconda');

        const q = proiettaLaSala(sala, [g('deserta', { seduta_id: 1 }, '2026-12-15T17:35:00Z'), g('apri', { seduta_id: p.da_aprire!.seduta_id,
            presidente: { anagrafica_id: 1 }, segretario: { nome: 'L\'amministratore' } }, '2026-12-15T17:42:00Z')], adesso);
        expect(q.seduta_id).toBe(2);
        expect(q.assemblea.stato).toBe('in_corso');
    });

    test('la seconda segnata deserta, anche dopo l\'appello: l\'assemblea è deserta, chi è in sala esce all\'ora del gesto, niente più da aprire', () => {
        const aperta = salaAperta();
        const p = proiettaLaSala(aperta, [g('deserta', { seduta_id: 2 }, '2026-12-15T18:15:00Z')], adesso);
        expect(p.assemblea.stato).toBe('deserta');
        expect(p.seduta_id).toBeNull();
        expect(p.da_aprire).toBeNull();
        expect(p.presenze.every((x) => x.uscita_il === '2026-12-15T18:15:00Z')).toBe(true);
        expect(p.sedute.find((s) => s.id === 2)).toMatchObject({ esito: 'deserta', chiusa_il: '2026-12-15T18:15:00Z' });
    });

    test('«Sospendi» e «Chiudi» chiudono le presenze all\'ora del gesto', () => {
        const sospesa = proiettaLaSala(salaAperta(), [g('sospendi', { seduta_id: 2, prosegue_seduta_id: 3 }, '2026-12-15T19:30:00Z')], adesso);
        expect(sospesa.presenze.filter((x) => x.uscita_il === null)).toEqual([]);
        const chiusa = proiettaLaSala(salaAperta(), [g('chiudi', { seduta_id: 2, non_trattati: 'rinviata' }, '2026-12-15T19:40:00Z')], adesso);
        expect(chiusa.presenze.every((x) => x.uscita_il === '2026-12-15T19:40:00Z')).toBe(true);
        expect(chiusa.assemblea.stato).toBe('chiusa');
    });

    test('lo stato tenuto del giorno prima, riaperto il giorno della seduta: «si apre» si ricalcola, e il controllo lo rifà il server', () => {
        const ieri = { ...salaDaAprire(), ora_del_server: '2026-12-14T21:00:00Z', da_aprire: { seduta_id: 2, convocazione: 'seconda' as const, numero: 1, si_apre: false,
            perche: 'La sala si apre il giorno della seduta, il 15/12/2026.' } };
        const oggi = proiettaLaSala(ieri, [], adesso);
        expect(oggi.da_aprire).toMatchObject({ seduta_id: 2, si_apre: true, perche: null });
        expect(oggi.controllo).toBeNull();
        expect(oggi.controllo_da_rifare).toBe(true);
        // Il giorno prima, invece, resta chiusa.
        expect(proiettaLaSala(ieri, [], new Date('2026-12-14T21:30:00Z')).da_aprire).toMatchObject({ si_apre: false,
            perche: 'La sala si apre il giorno della seduta, il 15/12/2026.' });
    });
});

describe('i conti sul posto delle deleghe', () => {
    test('la delega «da decidere» non conta nella barra; quella di un proprietario per alcuni punti sì, e sul voto solo per i suoi punti', () => {
        const base = salaAperta();
        const sala = { ...base, presenze: [presenza(30, '2026-12-15T17:43:00Z')], deleghe: [
            delega(1, 1, 30, 'Condomino 30', { punti: [41] }),
            delega(2, 12, 30, 'Condomino 30', { da_decidere: true }),
        ] };
        const p = proiettaLaSala(sala, [], adesso);
        expect(p.barra?.presenti?.sort((a, b) => a - b)).toEqual([1, 30]);
        const v41 = proiettaLaSala(sala, [g('si_vota', { seduta_id: 2, punto_id: 41 }, '2026-12-15T18:00:00Z')], adesso).punti.find((x) => x.id === 41)!.votazioni.at(-1)!;
        const v44 = proiettaLaSala(sala, [g('si_vota', { seduta_id: 2, punto_id: 44 }, '2026-12-15T18:00:00Z')], adesso).punti.find((x) => x.id === 44)!.votazioni.at(-1)!;
        expect(v41.intervenuti.numero).toBe(2);
        expect(v44.intervenuti.numero).toBe(1);
    });
});

/** Il server finto: rifiuta i gesti dei tipi dati, la prima volta. */
function server(rifiuta: string[] = []) {
    const s = { lotti: [] as LottoDaMandare[], rete: true, rifiuta: new Set(rifiuta), votazioni: 80 };
    const invia = async (lotto: LottoDaMandare): Promise<RispostaDelServer> => {
        s.lotti.push(JSON.parse(JSON.stringify(lotto)));
        if (!s.rete) throw new ErroreDellInvio('rete');
        return { ora_del_server: '2026-12-15T20:00:00Z', risposte: lotto.gesti.map((x) => {
            if (s.rifiuta.has(x.tipo)) return { gesto: x.gesto, tipo: x.tipo, esito: 'rifiutato', messaggio: 'Questa seduta è già deserta.' };
            return { gesto: x.gesto, tipo: x.tipo, esito: 'applicato', messaggio: null, ...(x.tipo === 'si_vota' ? { votazione_id: ++s.votazioni } : {}) };
        }) };
    };

    return { s, invia };
}

let n = 0;
function coda(deposito: DepositoInMemoria, invia: (l: LottoDaMandare) => Promise<RispostaDelServer>, adesso: () => number = () => Date.parse('2026-12-15T18:40:00Z')) {
    return new CodaDeiGesti({ deposito, ambito: 'sala:9:5', dispositivo: 'portatile di Andrea', invia, adesso,
        numero: () => `00000000-0000-4000-a000-${String(++n).padStart(12, '0')}`, schede: async (_n, lavoro) => {
            await lavoro();
            return true;
        } });
}

describe('la coda non perde niente', () => {
    test('l\'ordine dei gesti non dipende dall\'orologio del dispositivo: tornato indietro di 30 secondi, il gesto dopo resta dopo', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = false;
        let ora = Date.parse('2026-12-15T18:40:00Z');
        const c = coda(deposito, invia, () => ora);
        await c.segna('si_vota', { seduta_id: 2, punto_id: 44 });
        ora -= 30_000;
        await c.segna('tutti_favorevoli', { votazione_id: 404 });
        expect((await deposito.inCoda('sala:9:5')).map((v) => v.tipo)).toEqual(['si_vota', 'tutti_favorevoli']);
    });

    test('un gesto rifiutato resta su questo computer «da rivedere», con il perché; «Rimanda» lo rimette in coda con un numero nuovo, e chi ne dipende con lui', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server(['si_vota']);
        s.rete = false;
        const c = coda(deposito, invia);
        const siVota = await c.segna('si_vota', { seduta_id: 2, punto_id: 44 });
        await c.segna('tutti_favorevoli', { votazione_id: riferimento(siVota.gesto, 'votazione_id') });
        await c.segna('entra', { seduta_id: 2, persona: { anagrafica_id: 13 }, come: 'in_sala' }, 0);
        s.rete = true;
        await c.prova();

        // L'entrata è arrivata; «Si vota» rifiutato, e «Tutti i presenti favorevoli» che ne dipendeva: tutti e due da rivedere, ancora sul disco.
        expect(c.stato.inCoda).toEqual([]);
        expect(c.stato.daRivedere.map((d) => [d.voce.tipo, d.messaggio])).toEqual([['si_vota', 'Questa seduta è già deserta.'],
            ['tutti_favorevoli', 'Non partito: il gesto da cui dipende non è stato applicato.']]);
        expect(((await deposito.leggi<unknown[]>('sala:9:5:da_rivedere')) ?? []).length).toBe(2);

        // Corretto l'errore, «Rimanda»: i due gesti ripartono nell'ordine, con numeri nuovi e il riferimento al «Si vota» nuovo; il server li accetta.
        s.rifiuta.clear();
        s.lotti = [];
        await c.rimanda();
        const lotto = s.lotti.flatMap((l) => l.gesti);
        expect(lotto.map((x) => x.tipo)).toEqual(['si_vota', 'tutti_favorevoli']);
        expect(lotto[0].gesto).not.toBe(siVota.gesto);
        expect(lotto[0].segnato_il).toBe(siVota.segnato_il);
        expect(lotto[1].dati).toEqual({ votazione_id: 81 });
        expect(c.stato.daRivedere).toEqual([]);
        expect(await deposito.leggi('sala:9:5:da_rivedere')).toEqual([]);
    });

    test('«Togli» toglie un gesto da rivedere solo quando lo si sceglie', async () => {
        const deposito = new DepositoInMemoria();
        const { invia } = server(['deserta']);
        const c = coda(deposito, invia);
        await c.segna('deserta', { seduta_id: 1 });
        await c.prova();
        expect(c.stato.daRivedere).toHaveLength(1);
        await c.lascia(c.stato.daRivedere[0].voce.gesto);
        expect(c.stato.daRivedere).toEqual([]);
        expect(await deposito.leggi('sala:9:5:da_rivedere')).toEqual([]);
    });

    test('alla riapertura della pagina i gesti da rivedere ci sono ancora', async () => {
        const deposito = new DepositoInMemoria();
        const { invia } = server(['deserta']);
        const c = coda(deposito, invia);
        await c.segna('deserta', { seduta_id: 1 });
        await c.prova();
        const dopo = coda(deposito, invia);
        await dopo.avvia();
        expect(dopo.stato.daRivedere.map((d) => d.voce.tipo)).toEqual(['deserta']);
    });
});

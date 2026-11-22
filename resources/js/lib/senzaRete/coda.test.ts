// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La coda dei gesti con il deposito in memoria e un server finto: prima sul disco, l'ordine, la risposta, il doppio, la sessione scaduta (401 e 419), il
 * server fermo, la forma sbagliata, i riferimenti (il voto di una votazione aperta senza rete), il computer riacceso, l'orologio, il lavoro ritrovato, il
 * tetto del lotto, due schede.
 */

import { describe, expect, test } from 'vitest';
import { CodaDeiGesti, type OpzioniDellaCoda, riferimento } from './coda';
import { DepositoInMemoria, type VoceInCoda } from './deposito';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';
import { Orologio } from './orologio';
import fileInComune from '../../../../tests/Fixtures/sala/lotti_senza_rete.json';

/** Il server finto: risponde gesto per gesto, conta una volta un numero già arrivato, dà il numero delle votazioni; la rete si spegne, la sessione scade. */
function server() {
    const s = {
        rete: 'accesa' as 'accesa' | 'spenta',
        stato: 200,
        errori: {} as Record<string, string[]>,
        ora: '2026-12-15T19:00:00Z',
        arrivati: new Map<string, { esito: string; votazione_id?: number }>(),
        lotti: [] as LottoDaMandare[],
        votazioni: 76,
        conflitti: new Set<string>(),
        rifiuta: new Set<string>(),
        /** Il 422 risponde una volta sola, poi il server torna a 200. */
        unaVolta: false,
    };
    const invia = async (lotto: LottoDaMandare): Promise<RispostaDelServer> => {
        s.lotti.push(JSON.parse(JSON.stringify(lotto)));
        if (s.rete === 'spenta') throw new ErroreDellInvio('rete');
        if (s.stato !== 200 && s.unaVolta) {
            const stato = s.stato;
            s.stato = 200;
            throw new ErroreDellInvio('forma', stato, s.errori);
        }
        if (s.stato !== 200) throw new ErroreDellInvio(s.stato === 401 || s.stato === 419 ? 'sessione' : s.stato === 422 ? 'forma' : s.stato === 403 ? 'permesso' : 'server', s.stato, s.errori);
        const risposte = lotto.gesti.map((g) => {
            const gia = s.arrivati.get(g.gesto);
            if (gia) return { gesto: g.gesto, tipo: g.tipo, esito: 'doppio', messaggio: 'Questo gesto era già arrivato: conta una volta.', esito_di_allora: gia.esito };
            const esito = s.conflitti.has(g.gesto) ? 'in_conflitto' : s.rifiuta.has(g.tipo) ? 'rifiutato' : 'applicato';
            const votazione = g.tipo === 'si_vota' && esito === 'applicato' ? ++s.votazioni : undefined;
            s.arrivati.set(g.gesto, { esito, votazione_id: votazione });

            return { gesto: g.gesto, tipo: g.tipo, esito, messaggio: null, versione: 1, ...(votazione ? { votazione_id: votazione } : {}) };
        });

        return { ora_del_server: s.ora, risposte };
    };

    return { s, invia };
}

let numero = 0;
function coda(deposito: DepositoInMemoria, invia: OpzioniDellaCoda['invia'], piu: Partial<OpzioniDellaCoda> = {}) {
    return new CodaDeiGesti({ deposito, ambito: 'sala:12', dispositivo: 'portatile di Andrea', invia, numero: () => `00000000-0000-4000-8000-${String(++numero).padStart(12, '0')}`,
        schede: async (_n, lavoro) => {
            await lavoro();
            return true;
        }, ...piu });
}

describe('la coda dei gesti', () => {
    test('prima sul disco, poi a video: il gesto è nel deposito quando la coda lo mostra', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const c = coda(deposito, invia);
        const visti: number[] = [];
        c.sottoscrivi(async (f) => visti.push(f.inCoda.length));
        const voce = await c.segna('entra', { seduta_id: 3, persona: { anagrafica_id: 7 }, come: 'in_sala' }, 0);
        expect((await deposito.inCoda('sala:12')).map((v) => v.gesto)).toEqual([voce.gesto]);
        expect(c.stato.inCoda.map((v) => v.gesto)).toEqual([voce.gesto]);
        expect(visti).toContain(1);
    });

    test('senza rete i gesti restano tutti, in ordine; con la rete partono in un lotto, in ordine, e si tolgono solo dopo la risposta', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const c = coda(deposito, invia);
        const a = await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        const b = await c.segna('entra', { persona: { anagrafica_id: 2 } }, 0);
        const d = await c.segna('esce', { persona: { anagrafica_id: 1 } }, 1);
        await c.prova();
        expect(c.stato.stato).toBe('senza_rete');
        expect((await deposito.inCoda('sala:12')).length).toBe(3);

        s.rete = 'accesa';
        await c.prova();
        expect(s.lotti.at(-1)!.gesti.map((g) => g.gesto)).toEqual([a.gesto, b.gesto, d.gesto]);
        expect(s.lotti.at(-1)!.dispositivo).toBe('portatile di Andrea');
        expect(await deposito.inCoda('sala:12')).toEqual([]);
        expect(c.stato.stato).toBe('in_linea');
        expect(c.stato.esiti.map((e) => e.esito)).toEqual(['applicato', 'applicato', 'applicato']);
    });

    test('lo stesso gesto due volte (la risposta persa) conta una volta: il server risponde «doppio», e il gesto si toglie', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        const c = coda(deposito, invia);
        const v = await c.segna('voto', { votazione_id: 5, testa: 'p-7', voto: 'favorevole' }, 0);
        await c.prova();
        // La risposta si perde: il gesto torna in coda com'era, e riparte.
        await deposito.aggiungi(v);
        await c.prova();
        expect(s.lotti.flatMap((l) => l.gesti).filter((g) => g.gesto === v.gesto).length).toBe(2);
        expect(s.arrivati.size).toBe(1);
        expect(c.stato.esiti.at(-1)).toMatchObject({ gesto: v.gesto, esito: 'doppio' });
        expect(await deposito.inCoda('sala:12')).toEqual([]);
    });

    test('la sessione scaduta (401 e 419) non toglie niente: la striscia lo dice, e dopo il rientro i gesti partono da soli', async () => {
        for (const stato of [401, 419]) {
            const deposito = new DepositoInMemoria();
            const { s, invia } = server();
            s.stato = stato;
            const c = coda(deposito, invia);
            await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
            await c.segna('entra', { persona: { anagrafica_id: 2 } }, 0);
            await c.prova();
            expect(c.stato.stato).toBe('sessione_scaduta');
            expect((await deposito.inCoda('sala:12')).length).toBe(2);
            s.stato = 200;
            await c.prova();
            expect(await deposito.inCoda('sala:12')).toEqual([]);
            expect(c.stato.stato).toBe('in_linea');
        }
    });

    test('il server fermo (5xx) non toglie niente, e lo dice; il 403 nemmeno', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.stato = 503;
        const c = coda(deposito, invia);
        await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        await c.prova();
        expect(c.stato).toMatchObject({ stato: 'senza_rete', serverFermo: true });
        s.stato = 403;
        await c.prova();
        expect(c.stato.stato).toBe('non_puoi');
        expect((await deposito.inCoda('sala:12')).length).toBe(1);
    });

    test('un gesto senza la forma giusta (422) si rifiuta qui con il messaggio del server, e gli altri partono', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        const c = coda(deposito, invia);
        s.rete = 'spenta';
        const a = await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        const b = await c.segna('dichiarazione', { testo: 'x'.repeat(30000) });
        const d = await c.segna('entra', { persona: { anagrafica_id: 2 } }, 0);
        await c.prova();
        s.rete = 'accesa';
        s.stato = 422;
        s.unaVolta = true;
        s.errori = { 'gesti.1.dati': ['I dati del gesto sono troppi.'] };
        await c.prova();
        expect(c.stato.esiti.find((e) => e.gesto === b.gesto)).toMatchObject({ esito: 'rifiutato', messaggio: 'I dati del gesto sono troppi.', locale: true });
        expect([...s.arrivati.keys()]).toEqual([a.gesto, d.gesto]);
        expect(await deposito.inCoda('sala:12')).toEqual([]);
    });

    test('il voto di una votazione aperta senza rete: il lotto si ferma prima del voto, e il voto parte con il numero vero della votazione', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const c = coda(deposito, invia);
        const siVota = await c.segna('si_vota', { seduta_id: 3, punto_id: 9 });
        await c.segna('tutti_favorevoli', { votazione_id: riferimento(siVota.gesto, 'votazione_id') });
        await c.segna('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-7', voto: 'contrario' }, 1);
        s.rete = 'accesa';
        s.lotti = [];
        await c.prova();
        expect(s.lotti.map((l) => l.gesti.map((g) => g.tipo))).toEqual([['si_vota'], ['tutti_favorevoli', 'voto']]);
        expect(s.lotti[1].gesti.map((g) => g.dati.votazione_id)).toEqual([77, 77]);
        expect(await deposito.inCoda('sala:12')).toEqual([]);
    });

    test('il voto di una votazione che il server ha rifiutato non parte: si rifiuta qui, e lo dice', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        s.rifiuta.add('si_vota');
        const c = coda(deposito, invia);
        const siVota = await c.segna('si_vota', { seduta_id: 3, punto_id: 9 });
        const voto = await c.segna('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-7', voto: 'contrario' }, 0);
        s.rete = 'accesa';
        await c.prova();
        expect(c.stato.esiti.find((e) => e.gesto === voto.gesto)).toMatchObject({ esito: 'rifiutato', locale: true,
            messaggio: 'Non partito: il gesto da cui dipende non è stato applicato.' });
        expect(s.lotti.flatMap((l) => l.gesti).some((g) => g.gesto === voto.gesto)).toBe(false);
    });

    test('il computer spento dopo la risposta alla votazione e prima del voto: riacceso, il voto trova il numero nel deposito', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const prima = coda(deposito, invia, { sessione: 'prima' });
        const siVota = await prima.segna('si_vota', { seduta_id: 3, punto_id: 9 });
        await prima.segna('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-7', voto: 'contrario' }, 0);
        await prima.prova();
        // Arriva solo la votazione (il lotto si ferma lì); poi il computer si spegne.
        s.rete = 'accesa';
        const soloLaVotazione: OpzioniDellaCoda['invia'] = async (l) => {
            const r = await invia(l);
            if (l.gesti.some((g) => g.tipo === 'voto')) throw new ErroreDellInvio('rete');
            return r;
        };
        await coda(deposito, soloLaVotazione, { sessione: 'prima' }).prova();
        expect((await deposito.inCoda('sala:12')).map((v) => v.tipo)).toEqual(['voto']);

        const dopo = coda(deposito, invia, { sessione: 'dopo' });
        await dopo.avvia();
        expect(s.lotti.at(-1)!.gesti[0]).toMatchObject({ tipo: 'voto', dati: { votazione_id: 77 } });
        expect(await deposito.inCoda('sala:12')).toEqual([]);
    });

    test('un riferimento che il server non ridice (il gesto arrivato «doppio») si cerca nell\'ultimo stato della sala', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        const cercati: string[] = [];
        const c = coda(deposito, invia, { cercaIlRiferimento: (voce, campo) => {
            cercati.push(`${voce?.tipo}:${campo}`);
            return 77;
        } });
        // La votazione era arrivata, ma il deposito non lo sa (spento prima di scriverlo): risponde «doppio», senza numero.
        s.rete = 'spenta';
        const siVota = await c.segna('si_vota', { seduta_id: 3, punto_id: 9 });
        await c.segna('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-7', voto: 'contrario' }, 0);
        s.arrivati.set(siVota.gesto, { esito: 'applicato', votazione_id: 77 });
        s.rete = 'accesa';
        await c.prova();
        expect(cercati).toEqual(['si_vota:votazione_id']);
        expect(s.lotti.at(-1)!.gesti[0]).toMatchObject({ tipo: 'voto', dati: { votazione_id: 77 } });
    });

    test('l\'orologio: il gesto porta l\'ora corretta con lo scarto del server e quella del dispositivo; lo scarto resta sul disco', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        let adesso = Date.parse('2026-12-15T18:50:00Z');
        const orologio = new Orologio(() => adesso);
        const c = coda(deposito, invia, { orologio, adesso: () => adesso });
        s.ora = '2026-12-15T19:00:00Z';
        await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        await c.prova();
        expect(orologio.scarto).toBe(10 * 60 * 1000);
        adesso += 60_000;
        s.rete = 'spenta';
        const v = await c.segna('entra', { persona: { anagrafica_id: 2 } }, 0);
        expect(v).toMatchObject({ orologio: '2026-12-15T18:51:00.000Z', segnato_il: '2026-12-15T19:01:00.000Z' });
        expect(await deposito.leggi('sala:12:scarto')).toBe(600000);

        const orologio2 = new Orologio(() => adesso);
        await coda(deposito, invia, { orologio: orologio2 }).avvia();
        expect(orologio2.scarto).toBe(600000);
    });

    test('il lavoro ritrovato: i gesti di una pagina di prima, con le loro ore; partono da soli, e la coda dice quanti sono arrivati e quanti hanno due versioni', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const prima = coda(deposito, invia, { sessione: 'prima', adesso: () => Date.parse('2026-12-15T18:39:00Z'),
            orologio: new Orologio(() => Date.parse('2026-12-15T18:39:00Z')) });
        await prima.segna('voto', { votazione_id: 5, testa: 'p-1', voto: 'favorevole' }, 0);
        const conflitto = await prima.segna('voto', { votazione_id: 5, testa: 'p-2', voto: 'contrario' }, 0);
        await prima.segna('dichiarazione', { testo: 'A verbale.' });
        await prima.prova();

        const dopo = coda(deposito, invia, { sessione: 'dopo' });
        s.conflitti.add(conflitto.gesto);
        s.rete = 'accesa';
        await dopo.avvia();
        expect(dopo.stato.ritrovati).toMatchObject({ quanti: 3, dal: '2026-12-15T18:39:00.000Z', al: '2026-12-15T18:39:00.000Z', finito: true });
        expect(dopo.stato.ritrovati!.esiti.map((e) => e.esito)).toEqual(['applicato', 'in_conflitto', 'applicato']);
    });

    test('il tetto del lotto: 501 gesti partono in due lotti, in ordine', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const c = coda(deposito, invia);
        const voci: VoceInCoda[] = [];
        for (let i = 0; i < 501; i++) voci.push(await c.segna('entra', { persona: { anagrafica_id: i } }, 0));
        s.rete = 'accesa';
        s.lotti = [];
        await c.prova();
        expect(s.lotti.map((l) => l.gesti.length)).toEqual([500, 1]);
        expect(s.lotti.flatMap((l) => l.gesti.map((g) => g.gesto))).toEqual(voci.map((v) => v.gesto));
    });

    test('due schede della stessa sala: manda una sola, e ogni gesto arriva una volta', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        let preso = false;
        const lucchetto: OpzioniDellaCoda['schede'] = async (_n, lavoro) => {
            if (preso) return false;
            preso = true;
            try {
                await lavoro();
            } finally {
                preso = false;
            }
            return true;
        };
        s.rete = 'spenta';
        const uno = coda(deposito, invia, { schede: lucchetto });
        const due = coda(deposito, invia, { schede: lucchetto });
        await uno.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        await due.segna('entra', { persona: { anagrafica_id: 2 } }, 0);
        await Promise.all([uno.prova(), due.prova()]);
        s.rete = 'accesa';
        s.lotti = [];
        await Promise.all([uno.prova(), due.prova()]);
        await uno.prova();
        await due.prova();
        expect(s.lotti.map((l) => l.gesti.length)).toEqual([2]);
        expect(s.arrivati.size).toBe(2);
        expect(await deposito.inCoda('sala:12')).toEqual([]);
    });

    test('svuota: a sala chiusa il lavoro tenuto nel browser si cancella', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const c = coda(deposito, invia);
        await c.segna('entra', { persona: { anagrafica_id: 1 } }, 0);
        await deposito.scrivi('sala:12:stato', { x: 1 });
        await c.svuota();
        expect(await deposito.inCoda('sala:12')).toEqual([]);
        expect(await deposito.leggi('sala:12:stato')).toBeUndefined();
        expect(c.stato.inCoda).toEqual([]);
    });

    test('la forma dei lotti è quella del file in comune con il server: l\'orologio indietro, il lotto che si ferma prima del riferimento, il numero vero', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = 'spenta';
        const gesti = fileInComune.lotti.flatMap((l) => l.gesti);
        let i = 0;
        let adesso = Date.parse(gesti[0].orologio);
        const orologio = new Orologio(() => adesso);
        orologio.scarto = fileInComune.scarto_ms;
        const c = new CodaDeiGesti({ deposito, ambito: 'sala:7', dispositivo: fileInComune.dispositivo, orologio, adesso: () => adesso, numero: () => gesti[i].gesto,
            invia: async (l) => {
                const r = await invia(l);
                return { ...r, risposte: r.risposte.map((x) => (x.tipo === 'si_vota' ? { ...x, votazione_id: '{votazione}' } : x)) };
            }, schede: async (_n, lavoro) => {
                await lavoro();
                return true;
            } });
        const rif = { votazione_id: riferimento(gesti[2].gesto, 'votazione_id') };
        for (const g of gesti) {
            adesso = Date.parse(g.orologio);
            const dati = 'votazione_id' in g.dati ? { ...g.dati, ...rif } : g.dati;
            await c.segna(g.tipo, dati as Record<string, unknown>, g.base);
            i += 1;
        }
        await c.prova();
        s.rete = 'accesa';
        s.lotti = [];
        await c.prova();
        expect(s.lotti).toEqual(fileInComune.lotti);
    });
});

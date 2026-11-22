// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Ciò che la tenuta senza rete deve fare per la pagina della sala:
 *
 * - **su un indirizzo senza https** (il server dell'ufficio, `http://192.168.1.20`) il browser non ha `crypto.randomUUID`: la coda numera i gesti lo stesso;
 * - **dal foglio della sala** il gesto porta l'ora scritta sul foglio, non quella di adesso;
 * - **le risposte** tengono i numeri che il server ha dato (la nuova assemblea di «Rinvia», la votazione), per i messaggi della pagina;
 * - **la sala vista dal dispositivo** vota anche senza rete: i gesti del voto di una votazione aperta senza rete dicono la seduta, il punto e il numero; la
 *   nota del presidente si vede subito; «Segna deserta» dopo l'appello chiude le presenze e la seduta.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { CodaDeiGesti } from './coda';
import { DepositoInMemoria } from './deposito';
import type { LottoDaMandare, RispostaDelServer } from './invio';
import { proiettaLaSala } from './sala';
import { salaAperta } from '@/components/assemblee/sala/salaDiProva';
import type { VoceInCoda } from './deposito';

const subito = async (_n: string, lavoro: () => Promise<void>) => {
    await lavoro();
    return true;
};

afterEach(() => vi.unstubAllGlobals());

describe('la coda per la pagina della sala', () => {
    test('senza crypto.randomUUID (un indirizzo senza https) i gesti hanno lo stesso il loro numero, nella forma che il server accetta', async () => {
        vi.stubGlobal('crypto', { getRandomValues: (b: Uint8Array) => b.fill(7) });
        const lotti: LottoDaMandare[] = [];
        const coda = new CodaDeiGesti({ deposito: new DepositoInMemoria(), ambito: 'sala:9', dispositivo: 'computer di Andrea', schede: subito,
            invia: async (l): Promise<RispostaDelServer> => {
                lotti.push(l);
                return { ora_del_server: '2026-12-15T18:45:00Z', risposte: l.gesti.map((g) => ({ gesto: g.gesto, tipo: g.tipo, esito: 'applicato', messaggio: null })) };
            } });

        const voce = await coda.segna('entra', { seduta_id: 2, persona: { anagrafica_id: 7 }, come: 'in_sala' }, 0);
        await coda.prova();

        expect(voce.gesto).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        expect(coda.sessione).not.toBe('');
        expect(lotti[0].gesti[0].gesto).toBe(voce.gesto);
    });

    test('dal foglio della sala il gesto porta l\'ora del foglio; l\'ora del dispositivo resta quella vera', async () => {
        const coda = new CodaDeiGesti({ deposito: new DepositoInMemoria(), ambito: 'sala:9', dispositivo: 'computer di Andrea', schede: subito, adesso: () => Date.parse('2026-12-16T09:12:00Z'),
            invia: async () => { throw new Error('senza rete'); } });

        const voce = await coda.segna('entra', { seduta_id: 2, persona: { anagrafica_id: 7 }, come: 'in_sala' }, 0, '2026-12-15T18:05:00.000Z');

        expect(voce.segnato_il).toBe('2026-12-15T18:05:00.000Z');
        expect(voce.orologio).toBe('2026-12-16T09:12:00.000Z');
    });

    test('le risposte tengono i numeri che il server ha dato: la nuova assemblea di «Rinvia l\'assemblea»', async () => {
        const coda = new CodaDeiGesti({ deposito: new DepositoInMemoria(), ambito: 'sala:9', dispositivo: 'computer di Andrea', schede: subito,
            invia: async (l): Promise<RispostaDelServer> => ({ ora_del_server: '2026-12-15T18:45:00Z', risposte: l.gesti.map((g) => ({ gesto: g.gesto, tipo: g.tipo,
                esito: 'applicato', messaggio: null, assemblea_nuova_id: 31 })) }) });

        await coda.segna('rinvia', { motivo: 'Manca il numero', sedute: [] });
        await coda.prova();

        expect(coda.stato.esiti.at(-1)?.numeri).toEqual({ assemblea_nuova_id: 31 });
    });
});

describe('la sala vista dal dispositivo', () => {
    const voce = (tipo: string, dati: Record<string, unknown>, segnato_il = '2026-12-15T18:40:00.000Z', gesto = `g-${tipo}-${Math.random()}`): VoceInCoda => ({
        gesto, ambito: 'sala:9', ordine: 0, sessione: 's', tipo, dati, base: null, orologio: segnato_il, segnato_il });

    test('il voto di una votazione aperta senza rete: i gesti dicono la seduta, il punto e il numero, e la sala li conta', () => {
        const sala = salaAperta();
        const punto = sala.punti.find((p) => p.votazioni.length === 0 && p.maggioranza !== 'senza_voto')!;
        const siVota = voce('si_vota', { seduta_id: sala.seduta_id, punto_id: punto.id }, '2026-12-15T18:40:00.000Z', 'apre-il-voto');
        const dove = { seduta_id: sala.seduta_id, punto_id: punto.id, numero: 1, aperta_dal_gesto: 'apre-il-voto' };
        const vista = proiettaLaSala(sala, [siVota, voce('tutti_favorevoli', dove), voce('nota', { ...dove, su: 'voto', testo: 'Votato senza rete.' })]);

        const votazione = vista.punti.find((p) => p.id === punto.id)!.votazioni[0];
        expect(votazione.id).toBeLessThan(0);
        expect(votazione.voti.length).toBe(votazione.intervenuti.teste.length);
        expect(votazione.voti.every((v) => v.voto === 'favorevole')).toBe(true);
        expect(votazione.nota).toBe('Votato senza rete.');
        expect(vista.in_coda).toContain(`votazione:${votazione.id}`);
    });

    test('«Segna deserta» sulla prima aperta: le presenze escono all\'ora, la seduta si chiude, la sala torna da aprire', () => {
        const sala = salaAperta();
        const seduta = sala.sedute.find((s) => s.id === sala.seduta_id)!;
        const vista = proiettaLaSala(sala, [voce('deserta', { seduta_id: seduta.id }, '2026-12-15T18:50:00.000Z')]);

        expect(vista.seduta_id).toBeNull();
        expect(vista.sedute.find((s) => s.id === seduta.id)).toMatchObject({ esito: 'deserta', chiusa_il: '2026-12-15T18:50:00.000Z' });
        expect(vista.presenze.filter((p) => p.uscita_il === null)).toEqual([]);
        expect(vista.in_coda).toContain(`seduta:${seduta.id}`);
    });
});

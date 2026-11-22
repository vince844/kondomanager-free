// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli strumenti della coda: l'orologio (lo scarto con metà del viaggio), la tenuta del browser (tiene, non promette, non tiene: la finestra privata), una
 * scheda sola che manda (il lucchetto del browser), l'invio con i motivi degli errori (rete, sessione 401 e 419, permesso, forma con gli errori, server).
 */

import { describe, expect, test } from 'vitest';
import { ErroreDellInvio, inviaA, motivoDallo } from './invio';
import { Orologio } from './orologio';
import { SPAZIO_DI_UNA_FINESTRA_PRIVATA, valutaIlBrowser } from './persistenza';
import { seLaSchedaManda } from './schede';

describe('l\'orologio', () => {
    test('lo scarto dal server, con metà del viaggio; il gesto porta l\'ora corretta e quella del dispositivo', () => {
        let adesso = Date.parse('2026-12-15T18:50:00.000Z');
        const o = new Orologio(() => adesso);
        o.aggiorna('2026-12-15T19:00:01.000Z', adesso - 2000);
        expect(o.scarto).toBe(10 * 60 * 1000 + 2000);
        adesso += 1000;
        expect(o.segna()).toEqual({ segnato_il: '2026-12-15T19:00:03.000Z', orologio: '2026-12-15T18:50:01.000Z' });
        o.aggiorna('non è un\'ora');
        expect(o.scarto).toBe(602000);
    });
});

describe('la tenuta del browser', () => {
    test('promette: tiene; non promette: tiene senza prometterlo; uno spazio piccolo o un deposito che non si apre: non tiene', async () => {
        const spazio = (persist: boolean, quota: number) => ({ persist: async () => persist, persisted: async () => persist, estimate: async () => ({ quota }) });
        expect((await valutaIlBrowser(spazio(true, 50e9), true)).tenuta).toBe('tiene');
        expect((await valutaIlBrowser(spazio(false, 50e9), true)).tenuta).toBe('non_promette');
        expect(await valutaIlBrowser(spazio(true, SPAZIO_DI_UNA_FINESTRA_PRIVATA - 1), true)).toEqual({ tenuta: 'non_tiene',
            perche: 'Lo spazio del browser è quello di una finestra privata.' });
        expect((await valutaIlBrowser(spazio(true, 50e9), false)).tenuta).toBe('non_tiene');
        expect((await valutaIlBrowser(undefined, true)).tenuta).toBe('non_promette');
        expect((await valutaIlBrowser({ persist: async () => { throw new Error('no'); } }, true)).tenuta).toBe('non_promette');
    });
});

describe('una scheda sola manda', () => {
    test('con il lucchetto preso da un\'altra scheda il lavoro non si fa; senza lucchetti si fa', async () => {
        let fatti = 0;
        const lavoro = async () => {
            fatti += 1;
        };
        const libero = { request: async (_n: string, _o: unknown, f: (l: unknown) => Promise<unknown>) => f({}) };
        const preso = { request: async (_n: string, _o: unknown, f: (l: unknown) => Promise<unknown>) => f(null) };
        expect(await seLaSchedaManda('x', lavoro, libero)).toBe(true);
        expect(await seLaSchedaManda('x', lavoro, preso)).toBe(false);
        expect(await seLaSchedaManda('x', lavoro, undefined)).toBe(true);
        expect(fatti).toBe(2);
    });
});

describe('l\'invio', () => {
    test('i motivi: nessuna risposta è la rete, 401 e 419 la sessione, 403 il permesso, 422 la forma, il resto il server', () => {
        expect([null, 0, 401, 419, 403, 422, 500, 502, 404].map(motivoDallo)).toEqual(['rete', 'rete', 'sessione', 'sessione', 'permesso', 'forma', 'server', 'server', 'server']);
    });

    test('manda il lotto in JSON e rende la risposta; un errore diventa un ErroreDellInvio con il motivo e gli errori della forma', async () => {
        const chiamate: unknown[] = [];
        const ok = inviaA('/gesti', { post: async (u, corpo, opzioni) => {
            chiamate.push([u, corpo, opzioni]);
            return { status: 200, data: { ora_del_server: '2026-12-15T19:00:00Z', risposte: [] } };
        } });
        expect(await ok({ dispositivo: 'd', gesti: [] })).toEqual({ ora_del_server: '2026-12-15T19:00:00Z', risposte: [] });
        expect(chiamate[0]).toEqual(['/gesti', { dispositivo: 'd', gesti: [] }, { headers: { Accept: 'application/json' }, timeout: 20000 }]);

        const errore = (risposta: unknown) => inviaA('/gesti', { post: async () => {
            throw { response: risposta };
        } })({ dispositivo: 'd', gesti: [] });
        await expect(errore(undefined)).rejects.toMatchObject({ motivo: 'rete', stato: null });
        await expect(errore({ status: 419 })).rejects.toMatchObject({ motivo: 'sessione', stato: 419 });
        await expect(errore({ status: 422, data: { errors: { 'gesti.0.dati': ['troppi'] } } })).rejects.toMatchObject({ motivo: 'forma', errori: { 'gesti.0.dati': ['troppi'] } });
        // La pagina dell'accesso al posto della risposta dei gesti: non è una risposta, e i gesti restano.
        const pagina = inviaA('/gesti', { post: async () => ({ status: 200, data: '<html>' }) });
        await expect(pagina({ dispositivo: 'd', gesti: [] })).rejects.toBeInstanceOf(ErroreDellInvio);
    });
});

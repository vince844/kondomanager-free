// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il deposito del lavoro senza rete, nelle due forme (in memoria e sul disco del browser, con un IndexedDB finto): la coda nell'ordine e per ambito, i gesti
 * tolti, i valori, lo svuotamento di un ambito; sul disco, le scritture «strict», i dati che restano dopo una riapertura (il computer riacceso), e il deposito
 * che non si apre (la finestra privata).
 */

import { describe, expect, test } from 'vitest';
import { apriDepositoIndexedDb, type Deposito, DepositoInMemoria, type VoceInCoda } from './deposito';
import { IndexedDbFinto } from './indexedDbFinto';

const voce = (gesto: string, ordine: number, ambito = 'sala:12'): VoceInCoda => ({
    gesto, ambito, ordine, sessione: 's1', tipo: 'entra', dati: { seduta_id: 3, persona: { anagrafica_id: 7 } }, base: 0,
    orologio: '2026-12-15T18:45:00.000Z', segnato_il: '2026-12-15T18:45:02.000Z',
});

const forme: [string, () => Promise<Deposito>][] = [
    ['in memoria', async () => new DepositoInMemoria()],
    ['sul disco', async () => apriDepositoIndexedDb(new IndexedDbFinto() as unknown as IDBFactory)],
];

describe.each(forme)('il deposito %s', (_nome, apri) => {
    test('la coda di un ambito nell\'ordine dei gesti, senza quelli di un altro ambito', async () => {
        const d = await apri();
        await d.aggiungi(voce('c', 3));
        await d.aggiungi(voce('a', 1));
        await d.aggiungi(voce('x', 2, 'sala:13'));
        await d.aggiungi(voce('b', 2));
        expect((await d.inCoda('sala:12')).map((v) => v.gesto)).toEqual(['a', 'b', 'c']);
        expect((await d.inCoda('sala:13')).map((v) => v.gesto)).toEqual(['x']);
    });

    test('un gesto tolto non torna; lo stesso numero due volte è un gesto solo', async () => {
        const d = await apri();
        await d.aggiungi(voce('a', 1));
        await d.aggiungi(voce('a', 1));
        await d.aggiungi(voce('b', 2));
        await d.togli(['a']);
        expect((await d.inCoda('sala:12')).map((v) => v.gesto)).toEqual(['b']);
    });

    test('i valori si scrivono, si leggono come copie e si cancellano; svuotare un ambito toglie i suoi gesti e i suoi valori', async () => {
        const d = await apri();
        await d.scrivi('sala:12:stato', { ora_del_server: '2026-12-15T19:09:00Z' });
        await d.scrivi('sala:13:stato', { ora_del_server: 'x' });
        await d.scrivi('dispositivo', 'abc');
        const letto = await d.leggi<{ ora_del_server: string }>('sala:12:stato');
        letto!.ora_del_server = 'cambiato';
        expect(await d.leggi('sala:12:stato')).toEqual({ ora_del_server: '2026-12-15T19:09:00Z' });

        await d.aggiungi(voce('a', 1));
        await d.aggiungi(voce('x', 2, 'sala:13'));
        await d.svuota('sala:12');
        expect(await d.inCoda('sala:12')).toEqual([]);
        expect(await d.leggi('sala:12:stato')).toBeUndefined();
        expect(await d.leggi('sala:13:stato')).toEqual({ ora_del_server: 'x' });
        expect(await d.leggi('dispositivo')).toBe('abc');
        expect((await d.inCoda('sala:13')).length).toBe(1);

        await d.cancella('dispositivo');
        expect(await d.leggi('dispositivo')).toBeUndefined();
    });
});

describe('il deposito sul disco del browser', () => {
    test('ogni scrittura chiede «strict»: il browser dice fatto quando il gesto è sul disco', async () => {
        const fabbrica = new IndexedDbFinto();
        const d = await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        await d.aggiungi(voce('a', 1));
        await d.scrivi('sala:12:stato', {});
        await d.togli(['a']);
        expect(fabbrica.durate).toEqual(['strict', 'strict', 'strict']);
    });

    test('il computer riacceso: riaperto, il deposito ha ancora i gesti', async () => {
        const fabbrica = new IndexedDbFinto();
        const prima = await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        await prima.aggiungi(voce('a', 1));
        await prima.scrivi('sala:12:stato', { ora_del_server: '2026-12-15T19:09:00Z' });
        const dopo = await apriDepositoIndexedDb(fabbrica as unknown as IDBFactory);
        expect((await dopo.inCoda('sala:12')).map((v) => v.gesto)).toEqual(['a']);
        expect(await dopo.leggi('sala:12:stato')).toEqual({ ora_del_server: '2026-12-15T19:09:00Z' });
    });

    test('il deposito che non si apre (certe finestre private), o un browser senza IndexedDB, rifiuta', async () => {
        const fabbrica = new IndexedDbFinto();
        fabbrica.rotta = true;
        await expect(apriDepositoIndexedDb(fabbrica as unknown as IDBFactory)).rejects.toThrow('Il deposito non si apre');
        await expect(apriDepositoIndexedDb(undefined)).rejects.toThrow('Questo browser non ha un deposito per il lavoro senza rete.');
    });
});

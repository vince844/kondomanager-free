// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * All'uscita dal programma il lavoro della sala e del verbale tenuto nel browser si cancella: le copie delle pagine dell'operaio, l'operaio, lo stato della
 * sala e del verbale nel deposito (nomi, unità, millesimi, voti, il testo). La coda dei gesti non arrivati si cancella solo dopo averlo chiesto; alla pagina di accesso (la sessione
 * scaduta, «Rientra») la coda resta, perché la manda chi la ha segnata quando rientra, e lo stato della sala no.
 *
 * La catena, da un capo all'altro: l'uscita → le copie e il deposito vuoti → la pagina della sala senza rete non si apre (l'operaio, caricato com'è, dà la
 * pagina che dice che cosa fare).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test, vi } from 'vitest';
import { apriDepositoIndexedDb } from './deposito';
import { IndexedDbFinto } from './indexedDbFinto';
import { cancellaIlLavoroSenzaRete, contaIlLavoroNonArrivato } from './uscita';

const BASE = 'https://studio.example';
const SALA = BASE + '/admin/gestionale/5/assemblee/12/sala';

/** Il browser finto: le cache per nome, gli operai registrati, il deposito. */
function browser() {
    const cache = new Map<string, Map<string, Response>>();
    const caches = {
        open: async (nome: string) => {
            if (!cache.has(nome)) cache.set(nome, new Map());
            const m = cache.get(nome)!;
            return {
                match: async (k: string | { url: string }) => m.get(typeof k === 'string' ? k : k.url)?.clone(),
                put: async (k: string | { url: string }, r: Response) => void m.set(typeof k === 'string' ? k : k.url, r),
                delete: async (k: string | { url: string }) => m.delete(typeof k === 'string' ? k : k.url),
                addAll: async () => undefined,
            };
        },
        keys: async () => [...cache.keys()],
        delete: async (nome: string) => cache.delete(nome),
    };
    const tolti: string[] = [];
    const registrazioni = [
        { scope: BASE + '/admin/gestionale/5/assemblee/12/', active: { scriptURL: SALA + '/senza-rete' }, unregister: vi.fn(async () => { tolti.push('12'); return true; }) },
        { scope: BASE + '/', active: { scriptURL: BASE + '/altro.js' }, unregister: vi.fn(async () => { tolti.push('altro'); return true; }) },
    ];
    const indexedDB = new IndexedDbFinto();

    return { cache, caches, tolti, serviceWorker: { getRegistrations: async () => registrazioni }, indexedDB };
}

async function conIlLavoro(b: ReturnType<typeof browser>, gesti = 2) {
    await (await b.caches.open('kondomanager-sala-12-v2')).put(SALA, new Response('la sala di Via Roma 12, con i nomi'));
    await b.caches.open('altra-cache');
    const deposito = await apriDepositoIndexedDb(b.indexedDB as unknown as IDBFactory);
    await deposito.scrivi('sala:12:5:stato', { assemblea: { id: 12 } });
    await deposito.scrivi('sala:12:5:scarto', 1000);
    await deposito.scrivi('dispositivo', 'computer di Andrea Costa#a1b2c3d4');
    for (let i = 0; i < gesti; i++) {
        await deposito.aggiungi({ gesto: `00000000-0000-4000-8000-00000000000${i}`, ambito: 'sala:12:5', ordine: i, sessione: 's', tipo: 'entra', dati: {}, base: 0,
            orologio: '2026-12-15T18:45:00Z', segnato_il: '2026-12-15T18:45:00Z' });
    }
    await deposito.scrivi('sala:12:5:da_rivedere', [{ voce: { gesto: 'x', tipo: 'apri' }, messaggio: 'no', arrivato_il: '2026-12-15T20:00:00Z', locale: false }]);

    return deposito;
}

describe('all\'uscita dal programma', () => {
    test('conta i gesti che non sono arrivati, anche quelli da rivedere: l\'uscita lo chiede prima di cancellarli', async () => {
        const b = browser();
        await conIlLavoro(b);
        expect(await contaIlLavoroNonArrivato(b)).toBe(3);
        expect(await contaIlLavoroNonArrivato(browser())).toBe(0);
    });

    test('l\'uscita cancella le copie della sala, gli operai della sala e tutto il deposito; le altre cache e gli altri operai no', async () => {
        const b = browser();
        await conIlLavoro(b);
        await cancellaIlLavoroSenzaRete({ ancheLaCoda: true }, b);
        expect([...b.cache.keys()]).toEqual(['altra-cache']);
        expect(b.tolti).toEqual(['12']);
        const deposito = await apriDepositoIndexedDb(b.indexedDB as unknown as IDBFactory);
        expect(await deposito.inCoda('sala:12:5')).toEqual([]);
        expect(await deposito.leggi('sala:12:5:stato')).toBeUndefined();
        expect(await deposito.leggi('sala:12:5:da_rivedere')).toBeUndefined();
    });

    test('alla pagina di accesso le copie e lo stato della sala si cancellano; i gesti in coda restano per chi li ha segnati', async () => {
        const b = browser();
        await conIlLavoro(b);
        await cancellaIlLavoroSenzaRete({ ancheLaCoda: false }, b);
        expect([...b.cache.keys()]).toEqual(['altra-cache']);
        const deposito = await apriDepositoIndexedDb(b.indexedDB as unknown as IDBFactory);
        expect(await deposito.leggi('sala:12:5:stato')).toBeUndefined();
        expect((await deposito.inCoda('sala:12:5')).length).toBe(2);
        expect(await deposito.leggi('sala:12:5:da_rivedere')).toHaveLength(1);
    });

    test('il verbale come la sala: la copia della pagina e lo stato del verbale si cancellano sempre; il testo non arrivato e le sue modifiche solo all\'uscita vera, contati prima',
        async () => {
            const b = browser();
            const deposito = await conIlLavoro(b, 0);
            const VERBALE = BASE + '/admin/gestionale/5/assemblee/12/il-verbale';
            await (await b.caches.open('kondomanager-sala-12-v2')).put(VERBALE, new Response('il verbale di Via Roma 12, con la discussione'));
            await deposito.scrivi('verbale:12:5:stato', { assemblea: { id: 12 }, verbale: { parti: {} } });
            await deposito.scrivi('verbale:12:5:modifiche', { modifiche: [{ parte: 'punto:31', il: '2026-12-15T19:16:00Z' }], ferma: true });
            await deposito.aggiungi({ gesto: '00000000-0000-4000-8000-0000000000aa', ambito: 'verbale:12:5', ordine: 1, sessione: 's', tipo: 'testo',
                dati: { verbale_id: 7, parte: 'punto:31', documento: { type: 'doc' } }, base: 0, orologio: '2026-12-15T19:16:00Z', segnato_il: '2026-12-15T19:16:00Z',
                fondi: 'testo:punto:31' });
            // Il testo non arrivato si conta con i gesti della sala: l'uscita lo chiede prima di cancellarlo.
            expect(await contaIlLavoroNonArrivato(b)).toBe(2);

            await cancellaIlLavoroSenzaRete({ ancheLaCoda: false }, b);
            expect([...b.cache.keys()]).toEqual(['altra-cache']);
            expect(await deposito.leggi('verbale:12:5:stato')).toBeUndefined();
            expect((await deposito.inCoda('verbale:12:5')).map((v) => v.tipo)).toEqual(['testo']);
            expect(await deposito.leggi('verbale:12:5:modifiche')).toMatchObject({ ferma: true });

            await cancellaIlLavoroSenzaRete({ ancheLaCoda: true }, b);
            expect(await deposito.inCoda('verbale:12:5')).toEqual([]);
            expect(await deposito.leggi('verbale:12:5:modifiche')).toBeUndefined();
        });

    test('catena: l\'uscita → le copie e il deposito vuoti → la pagina della sala, senza rete, non si apre', async () => {
        const b = browser();
        await conIlLavoro(b);
        const configurazione = { versione: 'v2', ambito: '/admin/gestionale/5/assemblee/12/', pagine: ['sala'], file: [], cache: 'kondomanager-sala-12-v2',
            prefisso: 'kondomanager-sala-12-', attesa_ms: 20, senza_copia: '<p>Senza rete questa pagina non si apre su questo computer</p>' };
        const ambiente = { location: { href: BASE + '/' }, caches: b.caches, fetch: async () => { throw new TypeError('Failed to fetch'); },
            skipWaiting: async () => undefined, clients: { claim: async () => undefined }, addEventListener: () => undefined };
        const sorgente = readFileSync(join(__dirname, 'operaio.js'), 'utf8');
        const operaio = (new Function(sorgente + '\nreturn operaioDellaSala;')() as (c: unknown, a: unknown) => { pagina: (e: unknown) => Promise<Response> })(configurazione,
            ambiente);
        const prima = await operaio.pagina({ request: { url: SALA, method: 'GET', mode: 'navigate' } });
        expect(await prima.text()).toContain('la sala di Via Roma 12');

        await cancellaIlLavoroSenzaRete({ ancheLaCoda: true }, b);
        const dopo = await operaio.pagina({ request: { url: SALA, method: 'GET', mode: 'navigate' } });
        expect(dopo.status).toBe(503);
        expect(await dopo.text()).toContain('Senza rete questa pagina non si apre su questo computer');
        expect(await (await apriDepositoIndexedDb(b.indexedDB as unknown as IDBFactory)).leggi('sala:12:5:stato')).toBeUndefined();
    });
});

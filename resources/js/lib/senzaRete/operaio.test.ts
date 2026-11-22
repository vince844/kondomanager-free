// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'operaio del browser della sala (`operaio.js`), caricato com'è, in un browser finto: le cache in memoria, una rete che si accende e si spegne.
 *
 * - all'installazione tiene i file della versione nella cache con il nome della versione, e si attiva subito;
 * - all'attivazione cancella le cache delle versioni di prima di questa assemblea, non quelle di un'altra assemblea né le altre del programma;
 * - la pagina della sala: con la rete risponde il server e la copia si aggiorna; senza rete, con il server spento o con la rete che non risponde, la copia;
 *   senza copia, la pagina che dice che cosa fare; una deviazione (la sessione scaduta) non diventa la copia;
 * - passano dall'operaio solo la sala, il verbale e i file della versione: le altre pagine e i gesti no;
 * - i messaggi della pagina: «tieni» e «dimentica»;
 * - chi non ha più l'accesso: la risposta che rinvia all'accesso toglie la copia; all'attivazione di una versione nuova le pagine aperte si tengono di nuovo.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { beforeEach, describe, expect, test, vi } from 'vitest';

const SORGENTE = readFileSync(join(__dirname, 'operaio.js'), 'utf8');
const BASE = 'https://studio.example/';
const AMBITO = '/admin/gestionale/5/assemblee/12/';
const SALA = BASE.slice(0, -1) + AMBITO + 'sala';

const CONFIGURAZIONE = {
    versione: 'v2',
    ambito: AMBITO,
    pagine: ['sala', 'verbale'],
    file: ['/build/assets/app-1.js', '/build/assets/Sala-1.js', '/build/assets/app-1.css'],
    cache: 'kondomanager-sala-12-v2',
    prefisso: 'kondomanager-sala-12-',
    attesa_ms: 20,
    senza_copia: '<p>Senza rete questa pagina non si apre su questo computer</p>',
};

type Richiesta = { url: string; method?: string; mode?: string };
type Chiave = string | Richiesta;

function assoluto(k: Chiave): string {
    return new URL(typeof k === 'string' ? k : k.url, BASE).href;
}

/** Una risposta del nostro server: tipo «basic», com'è nel browser. */
function risposta(corpo: string, opzioni: { status?: number; tipo?: string; html?: boolean; deviata?: boolean } = {}): Response {
    const r = new Response(opzioni.status === 302 ? null : corpo, { status: opzioni.status ?? 200, headers: { 'Content-Type': opzioni.html === false ? 'text/javascript' : 'text/html; charset=utf-8' } });
    Object.defineProperty(r, 'type', { value: opzioni.tipo ?? 'basic' });
    Object.defineProperty(r, 'redirected', { value: opzioni.deviata ?? false });

    return r;
}

class CacheFinta {
    mappa = new Map<string, Response>();
    constructor(private rete: (k: Chiave) => Promise<Response>) {}
    async match(k: Chiave, opzioni?: { ignoreSearch?: boolean }): Promise<Response | undefined> {
        const cercata = assoluto(k);
        for (const [chiave, valore] of this.mappa) {
            const uguale = opzioni?.ignoreSearch ? chiave.split('?')[0] === cercata.split('?')[0] : chiave === cercata;
            if (uguale) return valore.clone();
        }

        return undefined;
    }
    async put(k: Chiave, r: Response): Promise<void> {
        this.mappa.set(assoluto(k), r);
    }
    async delete(k: Chiave, opzioni?: { ignoreSearch?: boolean }): Promise<boolean> {
        const cercata = assoluto(k);
        let tolta = false;
        for (const chiave of [...this.mappa.keys()]) {
            if (opzioni?.ignoreSearch ? chiave.split('?')[0] === cercata.split('?')[0] : chiave === cercata) tolta = this.mappa.delete(chiave) || tolta;
        }

        return tolta;
    }
    async addAll(lista: string[]): Promise<void> {
        for (const indirizzo of lista) {
            const r = await this.rete(indirizzo);
            if (!r.ok) throw new TypeError('addAll: ' + indirizzo);
            this.mappa.set(assoluto(indirizzo), r);
        }
    }
}

/** Il browser finto: le cache per nome, la rete (accesa, spenta, lenta, o con il server che risponde 502), gli ascoltatori. */
function browser() {
    const stato = { rete: 'accesa' as 'accesa' | 'spenta' | 'lenta' | 'proxy', pagina: 'la sala di oggi', deviata: false };
    const chiesti: string[] = [];
    const fetch = vi.fn(async (k: Chiave): Promise<Response> => {
        chiesti.push(assoluto(k));
        if (stato.rete === 'spenta') throw new TypeError('Failed to fetch');
        if (stato.rete === 'lenta') return new Promise<Response>(() => undefined);
        if (stato.rete === 'proxy') return risposta('Bad Gateway', { status: 502 });
        const url = assoluto(k);
        if (url.includes('/build/')) return risposta('file ' + url, { html: false });
        if (stato.deviata) return risposta('', { tipo: 'opaqueredirect', status: 302 });

        return risposta(stato.pagina);
    });
    const cache = new Map<string, CacheFinta>();
    const caches = {
        open: async (nome: string) => {
            if (!cache.has(nome)) cache.set(nome, new CacheFinta(fetch));
            return cache.get(nome)!;
        },
        keys: async () => [...cache.keys()],
        delete: async (nome: string) => cache.delete(nome),
    };
    const ascoltatori: Record<string, (e: any) => void> = {};
    const ambiente = {
        location: { href: BASE },
        caches,
        fetch,
        skipWaiting: vi.fn(async () => undefined),
        clients: { claim: vi.fn(async () => undefined) },
        addEventListener: (tipo: string, f: (e: any) => void) => {
            ascoltatori[tipo] = f;
        },
    };

    return { stato, chiesti, fetch, cache, caches, ambiente, ascoltatori };
}

type Operaio = {
    installa: () => Promise<void>;
    attiva: () => Promise<void>;
    pagina: (e: { request: Richiesta; waitUntil?: (p: Promise<unknown>) => void }) => Promise<Response>;
    messaggio: (e: { data: unknown }) => Promise<void>;
    ascolta: () => void;
};

/** L'operaio com'è nel file, con la configurazione data. */
function operaio(b: ReturnType<typeof browser>, configurazione = CONFIGURAZIONE): Operaio {
    const crea = new Function(SORGENTE + '\nreturn operaioDellaSala;')() as (c: typeof CONFIGURAZIONE, a: unknown) => Operaio;

    return crea(configurazione, b.ambiente);
}

const navigazione = (url: string): Richiesta => ({ url, method: 'GET', mode: 'navigate' });

describe('l\'operaio della sala', () => {
    let b: ReturnType<typeof browser>;
    let o: Operaio;
    beforeEach(() => {
        b = browser();
        o = operaio(b);
    });

    test('all\'installazione tiene i file della versione nella cache della versione, e si attiva subito', async () => {
        await o.installa();
        const cache = b.cache.get('kondomanager-sala-12-v2')!;
        expect([...cache.mappa.keys()]).toEqual(CONFIGURAZIONE.file.map((f) => BASE.slice(0, -1) + f));
        expect(b.ambiente.skipWaiting).toHaveBeenCalled();
    });

    test('all\'attivazione cancella le versioni di prima di questa assemblea, non quelle di un\'altra assemblea né le altre cache', async () => {
        for (const nome of ['kondomanager-sala-12-v1', 'kondomanager-sala-12-v2', 'kondomanager-sala-13-v1', 'kondomanager-sala-120-v1', 'altra-cache']) {
            await b.caches.open(nome);
        }
        await o.attiva();
        expect((await b.caches.keys()).sort()).toEqual(['altra-cache', 'kondomanager-sala-12-v2', 'kondomanager-sala-120-v1', 'kondomanager-sala-13-v1']);
        expect(b.ambiente.clients.claim).toHaveBeenCalled();
    });

    test('la sala con la rete: risponde il server, e la copia si aggiorna', async () => {
        const r = await o.pagina({ request: navigazione(SALA) });
        expect(await r.text()).toBe('la sala di oggi');
        await Promise.resolve();
        const copia = await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA);
        expect(await copia!.text()).toBe('la sala di oggi');
    });

    test('la sala senza rete: la copia tenuta; anche con il server spento dietro il proxy, e quando la rete non risponde', async () => {
        await o.pagina({ request: navigazione(SALA) });
        await Promise.resolve();
        b.stato.pagina = 'la sala di dopo';

        b.stato.rete = 'spenta';
        expect(await (await o.pagina({ request: navigazione(SALA) })).text()).toBe('la sala di oggi');
        b.stato.rete = 'proxy';
        expect(await (await o.pagina({ request: navigazione(SALA) })).text()).toBe('la sala di oggi');
        b.stato.rete = 'lenta';
        expect(await (await o.pagina({ request: navigazione(SALA) })).text()).toBe('la sala di oggi');
    });

    // 21/11/2026 sera, a video: la sala e il verbale dell'assemblea n. 8 davano «Senza rete» con il server acceso. Se tenere la copia non riesce (la cache
    // del browser rifiuta, o la versione di prima è appena stata cancellata), la risposta buona del server non deve diventare «Senza rete».
    test('con la rete, se tenere la copia non riesce: risponde il server lo stesso, non «Senza rete»', async () => {
        const cache = await b.caches.open(CONFIGURAZIONE.cache);
        cache.put = async () => { throw new DOMException('Quota superata', 'QuotaExceededError'); };
        const r = await o.pagina({ request: navigazione(SALA) });
        expect(r.status).toBe(200);
        expect(await r.text()).toBe('la sala di oggi');
    });

    test('con la rete e una copia vecchia, se tenere la copia nuova non riesce: la pagina del server, non la copia vecchia', async () => {
        await o.pagina({ request: navigazione(SALA) });
        await Promise.resolve();
        b.stato.pagina = 'la sala di dopo';
        const cache = await b.caches.open(CONFIGURAZIONE.cache);
        cache.put = async () => { throw new DOMException('Quota superata', 'QuotaExceededError'); };
        expect(await (await o.pagina({ request: navigazione(SALA) })).text()).toBe('la sala di dopo');
    });

    test('senza rete e senza copia: la pagina che dice che cosa fare', async () => {
        b.stato.rete = 'spenta';
        const r = await o.pagina({ request: navigazione(SALA) });
        expect(r.status).toBe(503);
        expect(await r.text()).toContain('Senza rete questa pagina non si apre su questo computer');
    });

    test('la deviazione della sessione scaduta passa al browser e non diventa la copia', async () => {
        b.stato.deviata = true;
        const r = await o.pagina({ request: navigazione(SALA) });
        expect(r.type).toBe('opaqueredirect');
        await Promise.resolve();
        expect(await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA)).toBeUndefined();
    });

    test('passano dall\'operaio solo la sala, il verbale e i file della versione: le altre pagine e i gesti no', async () => {
        o.ascolta();
        expect(Object.keys(b.ascoltatori).sort()).toEqual(['activate', 'fetch', 'install', 'message']);
        const presi: string[] = [];
        const evento = (request: Richiesta) => ({ request, respondWith: () => presi.push(request.url), waitUntil: () => undefined });
        for (const r of [
            navigazione(SALA),
            navigazione(BASE + 'admin/gestionale/5/assemblee/12/verbale'),
            { url: BASE + 'build/assets/Sala-1.js', method: 'GET', mode: 'cors' },
            navigazione(BASE + 'admin/gestionale/5/assemblee/12'),
            navigazione(BASE + 'admin/gestionale/5/assemblee/12/convocazione'),
            navigazione(BASE + 'admin/gestionale/5/assemblee/13/sala'),
            { url: SALA + '/gesti', method: 'POST', mode: 'cors' },
            { url: SALA + '/stato', method: 'GET', mode: 'cors' },
            { url: BASE + 'build/assets/Assemblea-1.js', method: 'GET', mode: 'cors' },
        ]) {
            b.ascoltatori.fetch(evento(r));
        }
        expect(presi).toEqual([SALA, BASE + 'admin/gestionale/5/assemblee/12/verbale', BASE + 'build/assets/Sala-1.js']);
    });

    test('i file della versione: prima la copia, anche senza rete', async () => {
        await o.installa();
        o.ascolta();
        b.stato.rete = 'spenta';
        let risposto: Promise<Response> | undefined;
        b.ascoltatori.fetch({ request: { url: BASE + 'build/assets/app-1.js', method: 'GET', mode: 'cors' }, respondWith: (p: Promise<Response>) => (risposto = p) });
        expect(await (await risposto!).text()).toBe('file ' + BASE + 'build/assets/app-1.js');
    });

    test('«tieni» tiene la pagina arrivata con una visita; «dimentica» cancella le copie di questa assemblea', async () => {
        await o.messaggio({ data: { tipo: 'tieni', indirizzo: SALA } });
        expect(await (await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA))!.text()).toBe('la sala di oggi');
        await o.messaggio({ data: { tipo: 'tieni', indirizzo: BASE + 'admin/gestionale/5/condomini' } });
        expect(b.chiesti).not.toContain(BASE + 'admin/gestionale/5/condomini');

        await b.caches.open('kondomanager-sala-13-v2');
        await o.messaggio({ data: { tipo: 'dimentica' } });
        expect(await b.caches.keys()).toEqual(['kondomanager-sala-13-v2']);
    });

    test('a sala chiusa «dimentica» con l\'indirizzo della sala toglie solo quella copia: il verbale si scrive ancora, e la sua pagina si riapre senza rete', async () => {
        const verbale = BASE.slice(0, -1) + AMBITO + 'verbale';
        await o.messaggio({ data: { tipo: 'tieni', indirizzo: SALA } });
        await o.messaggio({ data: { tipo: 'tieni', indirizzo: verbale } });
        await o.messaggio({ data: { tipo: 'dimentica', indirizzo: SALA } });
        const cache = await b.caches.open(CONFIGURAZIONE.cache);
        expect(await cache.match(SALA)).toBeUndefined();
        expect(await cache.match(verbale)).toBeDefined();
    });

    test('servito con la configurazione in testa, si mette in ascolto da solo', () => {
        const b2 = browser();
        new Function('self', 'const CONFIGURAZIONE = ' + JSON.stringify(CONFIGURAZIONE) + ';\n' + SORGENTE)(b2.ambiente);
        expect(Object.keys(b2.ascoltatori).sort()).toEqual(['activate', 'fetch', 'install', 'message']);
    });
});

describe('l\'operaio della sala, chi non ha più l\'accesso e l\'aggiornamento del programma', () => {
    let b: ReturnType<typeof browser>;
    let o: Operaio;
    beforeEach(() => {
        b = browser();
        o = operaio(b);
    });

    test('la rete che rinvia all\'accesso toglie la copia della pagina: dopo, senza rete, la pagina che dice che cosa fare, non la sala', async () => {
        await o.messaggio({ data: { tipo: 'tieni', indirizzo: SALA } });
        expect(await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA)).toBeDefined();
        b.stato.deviata = true;
        const deviata = await o.pagina({ request: navigazione(SALA) });
        expect(deviata.type).toBe('opaqueredirect');
        await vi.waitFor(async () => expect(await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA)).toBeUndefined());
        b.stato.rete = 'spenta';
        const senza = await o.pagina({ request: navigazione(SALA) });
        expect(senza.status).toBe(503);
        expect(await senza.text()).toContain('Senza rete questa pagina non si apre su questo computer');
    });

    test('all\'attivazione di una versione nuova tiene di nuovo le pagine aperte: la copia non si perde con l\'aggiornamento', async () => {
        (b.ambiente.clients as Record<string, unknown>).matchAll = vi.fn(async () => [{ url: SALA }, { url: BASE + 'admin/gestionale/5/condomini' }]);
        await o.attiva();
        const copia = await (await b.caches.open(CONFIGURAZIONE.cache)).match(SALA);
        expect(copia).toBeDefined();
        expect(await copia!.text()).toBe('la sala di oggi');
        expect(await (await b.caches.open(CONFIGURAZIONE.cache)).match(BASE + 'admin/gestionale/5/condomini')).toBeUndefined();
    });
});

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La struttura del testo del verbale nel browser: le stesse regole, le stesse frasi e la stessa forma canonica di `StrutturaDelVerbale` sul server, sul
 * file di casi in comune `tests/Fixtures/verbale/struttura.json`. **Il testo si salva come struttura, mai come HTML** (decisione 515): i nodi della barra
 * (il paragrafo, il titolo a un livello, gli elenchi, l'a capo nel paragrafo) e la parte «Dati della sala», i segni grassetto, corsivo e sottolineato;
 * nient'altro. L'editor manda solo ciò che passa da qui; il server controlla di nuovo e non prende per buono ciò che arriva.
 *
 * L'impronta è lo SHA-256 della forma canonica, calcolato qui senza `crypto.subtle`: una pagina servita senza https (il server dell'ufficio) non lo ha.
 */

export const BYTE_MASSIMI = 1048576;
export const PROFONDITA_MASSIMA = 12;
export const NODI_MASSIMI = 20000;
export const SEGNI = ['bold', 'italic', 'underline'] as const;
export const LIVELLO_DEL_TITOLO = 2;
export const FORMA = 'Il testo del verbale non è nella forma dell\'editor.';

export type Segno = (typeof SEGNI)[number];
export type TipoDelNodo = 'doc' | 'paragraph' | 'heading' | 'bulletList' | 'orderedList' | 'listItem' | 'datiDellaSala' | 'text' | 'hardBreak';

/** Un nodo nella forma canonica. */
export interface NodoDelVerbale {
    type: TipoDelNodo;
    attrs?: Record<string, string | number>;
    content?: NodoDelVerbale[];
    text?: string;
    marks?: Array<{ type: Segno }>;
}

export type EsitoDellaStruttura = { ok: true; documento: NodoDelVerbale } | { ok: false; errore: string };

const DENTRO: Record<TipoDelNodo, TipoDelNodo[]> = {
    doc: ['paragraph', 'heading', 'bulletList', 'orderedList', 'datiDellaSala'],
    datiDellaSala: ['paragraph', 'heading', 'bulletList', 'orderedList'],
    paragraph: ['text', 'hardBreak'],
    heading: ['text', 'hardBreak'],
    bulletList: ['listItem'],
    orderedList: ['listItem'],
    listItem: ['paragraph', 'bulletList', 'orderedList'],
    text: [],
    hardBreak: [],
};

const CAMPI: Record<TipoDelNodo, string[]> = {
    doc: ['type', 'content'],
    paragraph: ['type', 'content'],
    heading: ['type', 'attrs', 'content'],
    bulletList: ['type', 'content'],
    orderedList: ['type', 'attrs', 'content'],
    listItem: ['type', 'content'],
    datiDellaSala: ['type', 'attrs', 'content'],
    text: ['type', 'text', 'marks'],
    hardBreak: ['type'],
};

const CHIAVE = /^[a-z_]+(:[a-z0-9_-]+)*$/;

class StrutturaNonValida extends Error {}

const oggetto = (x: unknown): x is Record<string, unknown> => typeof x === 'object' && x !== null && !Array.isArray(x);
const intero = (x: unknown): x is number => typeof x === 'number' && Number.isInteger(x);
const lunghezza = (s: string): number => [...s].length;
const byte = (s: string): number => new TextEncoder().encode(s).length;
const no = (frase: string): never => {
    throw new StrutturaNonValida(frase);
};

function attributi(tipo: TipoDelNodo, n: Record<string, unknown>): Record<string, string | number> | null {
    const attrs = n.attrs;
    if (tipo === 'heading') {
        if (oggetto(attrs)) {
            for (const k of Object.keys(attrs)) if (k !== 'level') no(`Nel testo del verbale «heading» ha un attributo che il programma non conosce: «${k}».`);
        }
        if (!oggetto(attrs) || !intero(attrs.level) || attrs.level !== LIVELLO_DEL_TITOLO) no('Il titolo ha un livello solo: il 2.');
        return { level: LIVELLO_DEL_TITOLO };
    }
    if (tipo === 'orderedList') {
        if (attrs === undefined || attrs === null) return null;
        if (!oggetto(attrs)) no(FORMA);
        const a = attrs as Record<string, unknown>;
        for (const k of Object.keys(a)) if (k !== 'start' && k !== 'type') no(`Nel testo del verbale «orderedList» ha un attributo che il programma non conosce: «${k}».`);
        const da = a.start === undefined ? 1 : a.start;
        if (!intero(da) || da < 1 || da > 100000 || (a.type !== undefined && a.type !== null)) no('Un elenco numerato ha solo il numero da cui parte.');
        return da === 1 ? null : { start: da as number };
    }
    if (tipo === 'datiDellaSala') {
        if (!oggetto(attrs)) no('Una parte «Dati della sala» deve avere la sua chiave.');
        const a = attrs as Record<string, unknown>;
        for (const k of Object.keys(a)) if (k !== 'chiave' && k !== 'originale') no(`Nel testo del verbale «datiDellaSala» ha un attributo che il programma non conosce: «${k}».`);
        if (typeof a.chiave !== 'string' || a.chiave === '') no('Una parte «Dati della sala» deve avere la sua chiave.');
        const chiave = a.chiave as string;
        if (lunghezza(chiave) > 80 || !CHIAVE.test(chiave)) no('La chiave di una parte «Dati della sala» non è nella forma giusta.');
        const originale = a.originale === undefined ? '' : a.originale;
        if (typeof originale !== 'string' || lunghezza(originale) > 20000) no(FORMA);
        return { chiave, originale: originale as string };
    }
    return null;
}

function pezzoDiTesto(n: Record<string, unknown>): NodoDelVerbale {
    if (typeof n.text !== 'string') no(FORMA);
    const testo = n.text as string;
    if (testo === '') no('Nel testo del verbale c\'è un pezzo di testo vuoto.');
    if (testo.includes('\u0000')) no('Il testo del verbale ha un carattere che non si può salvare.');
    const segni = n.marks === undefined ? [] : n.marks;
    if (!Array.isArray(segni)) no(FORMA);
    const visti = new Set<string>();
    for (const s of segni as unknown[]) {
        if (!oggetto(s) || typeof s.type !== 'string') no(FORMA);
        const segno = s as Record<string, unknown>;
        if (!(SEGNI as readonly string[]).includes(segno.type as string)) no(`Il testo del verbale ha un formato che il programma non conosce: «${segno.type}».`);
        for (const k of Object.keys(segno)) {
            if (k === 'type' || (k === 'attrs' && oggetto(segno.attrs) && Object.keys(segno.attrs).length === 0)) continue;
            no(`Il formato «${segno.type}» ha un campo che il programma non conosce: «${k}».`);
        }
        visti.add(segno.type as string);
    }
    const ordinati = SEGNI.filter((s) => visti.has(s));
    return ordinati.length ? { type: 'text', text: testo, marks: ordinati.map((s) => ({ type: s })) } : { type: 'text', text: testo };
}

function nodo(n: unknown, madre: TipoDelNodo | null, profondita: number, conta: { n: number }): NodoDelVerbale {
    if (!oggetto(n) || typeof n.type !== 'string') no(FORMA);
    const x = n as Record<string, unknown>;
    if (profondita > PROFONDITA_MASSIMA) no('Il testo del verbale ha troppi livelli uno dentro l\'altro.');
    const tipo = x.type as TipoDelNodo;
    if (!Object.prototype.hasOwnProperty.call(DENTRO, tipo)) no(`Il testo del verbale ha una parte che il programma non conosce: «${x.type}».`);
    if (madre === null && tipo !== 'doc') no('Il testo del verbale deve cominciare con il documento.');
    if (madre !== null && !DENTRO[madre].includes(tipo)) no(`Nel testo del verbale «${tipo}» non può stare dentro «${madre}».`);
    for (const campo of Object.keys(x)) {
        if (CAMPI[tipo].includes(campo)) continue;
        if (campo === 'attrs' && oggetto(x.attrs)) {
            const chiavi = Object.keys(x.attrs);
            if (chiavi.length === 0) continue;
            no(`Nel testo del verbale «${tipo}» ha un attributo che il programma non conosce: «${chiavi[0]}».`);
        }
        no(`Nel testo del verbale «${tipo}» ha un campo che il programma non conosce: «${campo}».`);
    }
    if (++conta.n > NODI_MASSIMI) no('Il testo del verbale ha troppe parti.');
    const attrs = attributi(tipo, x);
    if (tipo === 'text') return pezzoDiTesto(x);
    if (tipo === 'hardBreak') return { type: 'hardBreak' };
    const contenuto = x.content === undefined ? [] : x.content;
    if (!Array.isArray(contenuto)) no(FORMA);
    const figli = (contenuto as unknown[]).map((c) => nodo(c, tipo, profondita + 1, conta));
    if ((tipo === 'bulletList' || tipo === 'orderedList') && figli.length === 0) no('Nel testo del verbale c\'è un elenco vuoto.');
    if (tipo === 'listItem' && (figli.length === 0 || figli[0].type !== 'paragraph')) no('Ogni voce di un elenco comincia con un paragrafo.');
    if (tipo === 'datiDellaSala' && figli.length === 0) no('Una parte «Dati della sala» non può essere vuota.');
    const uscita: NodoDelVerbale = { type: tipo };
    if (attrs !== null) uscita.attrs = attrs;
    if (figli.length) uscita.content = figli;
    return uscita;
}

/** La struttura nella forma canonica, o la frase del primo errore (in profondità, nell'ordine dei nodi), come il server. */
export function valida(documento: unknown): EsitoDellaStruttura {
    try {
        if (!oggetto(documento)) no(FORMA);
        let grezzo: string;
        try {
            grezzo = JSON.stringify(documento);
        } catch {
            return { ok: false, errore: FORMA };
        }
        if (byte(grezzo) > 2 * BYTE_MASSIMI) no('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
        const d = nodo(documento, null, 1, { n: 0 });
        if (byte(canonico(d)) > BYTE_MASSIMI) no('Il testo del verbale è troppo lungo: al più 1 MB per parte.');
        return { ok: true, documento: d };
    } catch (e) {
        if (e instanceof StrutturaNonValida) return { ok: false, errore: e.message };
        throw e;
    }
}

/** La frase dell'errore, o null se la struttura va bene. */
export function errore(documento: unknown): string | null {
    const esito = valida(documento);
    return esito.ok ? null : esito.errore;
}

/** Il JSON della forma canonica: le chiavi nell'ordine dei nodi (`type`, `attrs`, `content`, `text`, `marks`), come `json_encode` sul server. */
export function canonico(documento: NodoDelVerbale): string {
    return JSON.stringify(documento);
}

/** Lo SHA-256 della forma canonica, in esadecimale. */
export function impronta(documento: NodoDelVerbale): string {
    return sha256(canonico(documento));
}

/** Il testo semplice: i paragrafi e i titoli (l'a capo nel paragrafo è un a capo), i blocchi e le voci uno per riga, senza segni né numeri. */
export function testo(n: NodoDelVerbale): string {
    if (n.type === 'text') return n.text ?? '';
    if (n.type === 'hardBreak') return '\n';
    const figli = (n.content ?? []).map(testo);
    return figli.join(n.type === 'paragraph' || n.type === 'heading' ? '' : '\n');
}

/** I caratteri del testo semplice, come `mb_strlen` sul server. */
export function caratteri(documento: NodoDelVerbale): number {
    return lunghezza(testo(documento));
}

/** Le parti «Dati della sala» del documento, chiave → testo semplice. */
export function blocchi(documento: NodoDelVerbale): Record<string, string> {
    const uscita: Record<string, string> = {};
    for (const n of documento.content ?? []) {
        if (n.type === 'datiDellaSala' && n.attrs) uscita[String(n.attrs.chiave)] = testo(n);
    }
    return uscita;
}

// I mattoni, già nella forma canonica, come quelli del server.

export function documento(blocchiDelDoc: NodoDelVerbale[]): NodoDelVerbale {
    return blocchiDelDoc.length ? { type: 'doc', content: blocchiDelDoc } : { type: 'doc' };
}

export function paragrafo(t: string): NodoDelVerbale {
    const contenuto: NodoDelVerbale[] = [];
    t.replace(/\r\n/g, '\n').split('\n').forEach((riga, i) => {
        if (i > 0) contenuto.push({ type: 'hardBreak' });
        const pulita = riga.replace(/\u0000/g, '');
        if (pulita !== '') contenuto.push({ type: 'text', text: pulita });
    });
    return contenuto.length ? { type: 'paragraph', content: contenuto } : { type: 'paragraph' };
}

export function titolo(t: string): NodoDelVerbale {
    const p = paragrafo(t);
    return p.content ? { type: 'heading', attrs: { level: LIVELLO_DEL_TITOLO }, content: p.content } : { type: 'heading', attrs: { level: LIVELLO_DEL_TITOLO } };
}

export function datiDellaSala(chiave: string, frasi: string[]): NodoDelVerbale {
    const content = (frasi.length ? frasi : ['']).map(paragrafo);
    const n: NodoDelVerbale = { type: 'datiDellaSala', attrs: { chiave, originale: '' }, content };
    n.attrs = { chiave, originale: [...testo(n)].slice(0, 20000).join('') };
    return n;
}

// Lo SHA-256 (FIPS 180-4) sui byte UTF-8 del testo, senza librerie e senza `crypto.subtle`.

const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

export function sha256(t: string): string {
    const dati = new TextEncoder().encode(t);
    const lunghezzaInBit = dati.length * 8;
    const blocchiNecessari = Math.ceil((dati.length + 9) / 64);
    const m = new Uint8Array(blocchiNecessari * 64);
    m.set(dati);
    m[dati.length] = 0x80;
    const vista = new DataView(m.buffer);
    vista.setUint32(m.length - 8, Math.floor(lunghezzaInBit / 0x100000000), false);
    vista.setUint32(m.length - 4, lunghezzaInBit >>> 0, false);
    const h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    const w = new Uint32Array(64);
    const ruota = (x: number, n: number) => (x >>> n) | (x << (32 - n));
    for (let b = 0; b < m.length; b += 64) {
        for (let i = 0; i < 16; i++) w[i] = vista.getUint32(b + i * 4, false);
        for (let i = 16; i < 64; i++) {
            const s0 = ruota(w[i - 15], 7) ^ ruota(w[i - 15], 18) ^ (w[i - 15] >>> 3);
            const s1 = ruota(w[i - 2], 17) ^ ruota(w[i - 2], 19) ^ (w[i - 2] >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
        }
        let [a, bb, c, d, e, f, g, hh] = [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7]];
        for (let i = 0; i < 64; i++) {
            const S1 = ruota(e, 6) ^ ruota(e, 11) ^ ruota(e, 25);
            const ch = (e & f) ^ (~e & g);
            const t1 = (hh + S1 + ch + K[i] + w[i]) >>> 0;
            const S0 = ruota(a, 2) ^ ruota(a, 13) ^ ruota(a, 22);
            const maj = (a & bb) ^ (a & c) ^ (bb & c);
            const t2 = (S0 + maj) >>> 0;
            hh = g;
            g = f;
            f = e;
            e = (d + t1) >>> 0;
            d = c;
            c = bb;
            bb = a;
            a = (t1 + t2) >>> 0;
        }
        h[0] = (h[0] + a) >>> 0;
        h[1] = (h[1] + bb) >>> 0;
        h[2] = (h[2] + c) >>> 0;
        h[3] = (h[3] + d) >>> 0;
        h[4] = (h[4] + e) >>> 0;
        h[5] = (h[5] + f) >>> 0;
        h[6] = (h[6] + g) >>> 0;
        h[7] = (h[7] + hh) >>> 0;
    }
    return [...h].map((x) => x.toString(16).padStart(8, '0')).join('');
}

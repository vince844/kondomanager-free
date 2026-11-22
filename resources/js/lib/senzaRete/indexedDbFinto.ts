// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un IndexedDB finto per le prove (non entra nel programma: lo importano solo i test). Ha solo ciò che `deposito.ts` usa: aprire con l'aggiornamento, due
 * magazzini, `put`, `get`, `delete`, `getAll`, `getAllKeys`, le transazioni che finiscono con `oncomplete`. I dati sopravvivono a una riapertura con lo
 * stesso nome (il computer «riacceso»), e la fabbrica dice con quale durata è stata chiesta ogni scrittura. Con `rotta` l'apertura fallisce, come in certe
 * finestre private.
 */
type Magazzino = { chiave: string | null; righe: Map<string, unknown> };

export class IndexedDbFinto {
    basi = new Map<string, Map<string, Magazzino>>();
    durate: string[] = [];
    rotta = false;

    open(nome: string): IDBOpenDBRequest {
        const richiesta: any = { result: undefined, error: null, onsuccess: null, onerror: null, onupgradeneeded: null };
        setTimeout(() => {
            if (this.rotta) {
                richiesta.error = new DOMException('Il deposito non si apre', 'InvalidStateError');
                richiesta.onerror?.();
                return;
            }
            const nuova = !this.basi.has(nome);
            if (nuova) this.basi.set(nome, new Map());
            richiesta.result = this.db(this.basi.get(nome)!);
            if (nuova) richiesta.onupgradeneeded?.();
            richiesta.onsuccess?.();
        });

        return richiesta as IDBOpenDBRequest;
    }

    private db(magazzini: Map<string, Magazzino>): IDBDatabase {
        return {
            objectStoreNames: { contains: (n: string) => magazzini.has(n) },
            createObjectStore: (n: string, opzioni?: { keyPath?: string }) => {
                magazzini.set(n, { chiave: opzioni?.keyPath ?? null, righe: new Map() });
            },
            transaction: (nome: string, modo: string, opzioni?: { durability?: string }) => {
                if (modo === 'readwrite') this.durate.push(opzioni?.durability ?? 'default');
                const t: any = { oncomplete: null, onerror: null, onabort: null, error: null };
                const m = magazzini.get(nome)!;
                const fatto = <T>(valore: T) => {
                    const r: any = { result: valore, onsuccess: null, onerror: null };
                    setTimeout(() => r.onsuccess?.());
                    return r;
                };
                t.objectStore = () => ({
                    put: (valore: any, chiave?: string) => fatto(m.righe.set(String(m.chiave ? valore[m.chiave] : chiave), structuredClone(valore))),
                    get: (chiave: string) => fatto(structuredClone(m.righe.get(String(chiave)))),
                    delete: (chiave: string) => fatto(m.righe.delete(String(chiave))),
                    getAll: () => fatto([...m.righe.values()].map((v) => structuredClone(v))),
                    getAllKeys: () => fatto([...m.righe.keys()]),
                });
                setTimeout(() => t.oncomplete?.(), 1);
                return t;
            },
        } as unknown as IDBDatabase;
    }
}

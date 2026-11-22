// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il deposito del lavoro senza rete: i gesti in coda e qualche valore (l'ultimo stato del server, lo scarto dell'orologio, il numero del dispositivo, ciò
 * che il server ha risposto ai gesti da cui altri dipendono). **Generico**: lo usano la sala e il verbale.
 *
 * - {@link DepositoIndexedDb}: il disco del browser (IndexedDB), con le scritture **«strict»**: il browser dice «fatto» solo quando il dato è sul disco, non
 *   quando è in memoria (di serie Chrome scrive «relaxed», e un computer che si spegne di colpo perderebbe gli ultimi gesti).
 * - {@link DepositoInMemoria}: la stessa interfaccia in memoria, per le prove.
 *
 * Nessuna libreria: l'IndexedDB del browser, dietro questa interfaccia.
 */

/** Un gesto in coda: quello che parte verso il server, più il suo posto nella coda. */
export interface VoceInCoda {
    /** Il numero del gesto, `crypto.randomUUID()`: il server conta una volta un gesto che arriva due volte. */
    gesto: string;
    /** Di che cosa è il gesto: `sala:12` (la sala dell'assemblea 12), `verbale:12`. */
    ambito: string;
    /** Il posto nella coda: i gesti partono in quest'ordine. */
    ordine: number;
    /** La pagina aperta che l'ha segnato: un gesto di un'altra sessione è «ritrovato». */
    sessione: string;
    tipo: string;
    dati: Record<string, unknown>;
    base: number | null;
    /** L'ora del dispositivo, com'era. */
    orologio: string;
    /** L'ora del gesto, corretta con lo scarto dell'orologio. */
    segnato_il: string;
    /**
     * La chiave di fusione (`testo:punto:7`): un gesto nuovo con la stessa chiave prende il posto di quello che aspetta ancora di partire (il testo di una
     * parte del verbale: conta l'ultimo). Senza chiave il gesto resta com'è (la sala: ogni voto è un gesto).
     */
    fondi?: string;
}

export interface Deposito {
    /** Scrive un gesto in coda: quando la promessa è risolta, il gesto è sul disco. */
    aggiungi(voce: VoceInCoda): Promise<void>;
    /** I gesti in coda di un ambito, nell'ordine. */
    inCoda(ambito: string): Promise<VoceInCoda[]>;
    /** Toglie i gesti dalla coda (dopo la risposta del server). */
    togli(gesti: string[]): Promise<void>;
    leggi<T>(chiave: string): Promise<T | undefined>;
    scrivi(chiave: string, valore: unknown): Promise<void>;
    cancella(chiave: string): Promise<void>;
    /** Svuota un ambito: i suoi gesti e i valori con la chiave che comincia con `<ambito>:`. */
    svuota(ambito: string): Promise<void>;
}

const copia = <T>(valore: T): T => (valore === undefined ? valore : (JSON.parse(JSON.stringify(valore)) as T));

/** Il deposito in memoria: per le prove, e quando il browser non ha un disco (allora la sala lo dice prima di aprirsi). */
export class DepositoInMemoria implements Deposito {
    private gesti = new Map<string, VoceInCoda>();
    private valori = new Map<string, unknown>();

    async aggiungi(voce: VoceInCoda): Promise<void> {
        this.gesti.set(voce.gesto, copia(voce));
    }

    async inCoda(ambito: string): Promise<VoceInCoda[]> {
        return [...this.gesti.values()].filter((v) => v.ambito === ambito).sort((a, b) => a.ordine - b.ordine).map(copia);
    }

    async togli(gesti: string[]): Promise<void> {
        for (const g of gesti) this.gesti.delete(g);
    }

    async leggi<T>(chiave: string): Promise<T | undefined> {
        return copia(this.valori.get(chiave) as T | undefined);
    }

    async scrivi(chiave: string, valore: unknown): Promise<void> {
        this.valori.set(chiave, copia(valore));
    }

    async cancella(chiave: string): Promise<void> {
        this.valori.delete(chiave);
    }

    async svuota(ambito: string): Promise<void> {
        for (const [g, v] of this.gesti) if (v.ambito === ambito) this.gesti.delete(g);
        for (const k of [...this.valori.keys()]) if (k.startsWith(ambito + ':')) this.valori.delete(k);
    }
}

/** Il nome della base del browser e i suoi due magazzini. */
export const NOME_DEL_DEPOSITO = 'kondomanager-senza-rete';
const GESTI = 'gesti';
const VALORI = 'valori';

/** Una richiesta di IndexedDB come promessa. */
function esito<T>(richiesta: IDBRequest<T>): Promise<T> {
    return new Promise((risolvi, rifiuta) => {
        richiesta.onsuccess = () => risolvi(richiesta.result);
        richiesta.onerror = () => rifiuta(richiesta.error);
    });
}

/** Il deposito sul disco del browser. Si apre con {@link apriDepositoIndexedDb}. */
export class DepositoIndexedDb implements Deposito {
    constructor(private readonly db: IDBDatabase) {}

    /** Una transazione che si chiude quando il dato è sul disco («strict»). */
    private async scrittura(magazzino: string, lavoro: (m: IDBObjectStore) => void): Promise<void> {
        const t = this.db.transaction(magazzino, 'readwrite', { durability: 'strict' } as IDBTransactionOptions);
        const fatto = new Promise<void>((risolvi, rifiuta) => {
            t.oncomplete = () => risolvi();
            t.onerror = () => rifiuta(t.error);
            t.onabort = () => rifiuta(t.error ?? new Error('Scrittura interrotta'));
        });
        lavoro(t.objectStore(magazzino));
        await fatto;
    }

    private lettura(magazzino: string): IDBObjectStore {
        return this.db.transaction(magazzino, 'readonly').objectStore(magazzino);
    }

    async aggiungi(voce: VoceInCoda): Promise<void> {
        await this.scrittura(GESTI, (m) => m.put(copia(voce)));
    }

    async inCoda(ambito: string): Promise<VoceInCoda[]> {
        const tutti = (await esito(this.lettura(GESTI).getAll())) as VoceInCoda[];

        return tutti.filter((v) => v.ambito === ambito).sort((a, b) => a.ordine - b.ordine);
    }

    async togli(gesti: string[]): Promise<void> {
        if (gesti.length === 0) return;
        await this.scrittura(GESTI, (m) => gesti.forEach((g) => m.delete(g)));
    }

    async leggi<T>(chiave: string): Promise<T | undefined> {
        return (await esito(this.lettura(VALORI).get(chiave))) as T | undefined;
    }

    async scrivi(chiave: string, valore: unknown): Promise<void> {
        await this.scrittura(VALORI, (m) => m.put(copia(valore), chiave));
    }

    async cancella(chiave: string): Promise<void> {
        await this.scrittura(VALORI, (m) => m.delete(chiave));
    }

    async svuota(ambito: string): Promise<void> {
        const gesti = (await this.inCoda(ambito)).map((v) => v.gesto);
        await this.togli(gesti);
        const chiavi = ((await esito(this.lettura(VALORI).getAllKeys())) as IDBValidKey[]).filter((k) => String(k).startsWith(ambito + ':'));
        if (chiavi.length > 0) await this.scrittura(VALORI, (m) => chiavi.forEach((k) => m.delete(k)));
    }

    /** Tutti i gesti in coda, di ogni ambito: per l'uscita dal programma (`uscita.ts`). */
    async tuttiIGesti(): Promise<VoceInCoda[]> {
        return (await esito(this.lettura(GESTI).getAll())) as VoceInCoda[];
    }

    /** Le chiavi di tutti i valori tenuti: per l'uscita dal programma. */
    async chiavi(): Promise<string[]> {
        return ((await esito(this.lettura(VALORI).getAllKeys())) as IDBValidKey[]).map(String);
    }
}

/**
 * Apre il deposito sul disco. Rifiuta se il browser non ha IndexedDB o non lo apre (certe finestre private): allora la sala lo dice prima di aprirsi e
 * propone il foglio della sala.
 */
export async function apriDepositoIndexedDb(fabbrica: IDBFactory | undefined = globalThis.indexedDB, nome = NOME_DEL_DEPOSITO): Promise<DepositoIndexedDb> {
    if (!fabbrica) throw new Error('Questo browser non ha un deposito per il lavoro senza rete.');
    const richiesta = fabbrica.open(nome, 1);
    richiesta.onupgradeneeded = () => {
        const db = richiesta.result;
        if (!db.objectStoreNames.contains(GESTI)) db.createObjectStore(GESTI, { keyPath: 'gesto' });
        if (!db.objectStoreNames.contains(VALORI)) db.createObjectStore(VALORI);
    };

    return new DepositoIndexedDb(await esito(richiesta));
}

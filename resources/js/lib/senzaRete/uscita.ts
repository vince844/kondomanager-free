// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { apriDepositoIndexedDb, NOME_DEL_DEPOSITO, type DepositoIndexedDb } from './deposito';

/**
 * All'uscita dal programma, il lavoro della sala e del verbale tenuto nel browser si cancella: chi usa lo stesso browser dopo non deve trovare la sala (i
 * nomi, le unità, i millesimi, i voti, le deleghe) né il verbale (il testo), né riaprirli senza rete.
 *
 * - **Le copie delle pagine** dell'operaio (le cache `kondomanager-sala-*`) e **gli operai della sala** (registrati nella cartella di un'assemblea).
 * - **Il deposito**: lo stato della sala e del verbale tenuto (`sala:<assemblea>:<utente>:stato`, `verbale:<assemblea>:<utente>:stato`) sempre; **i gesti in
 *   coda** (anche il testo del verbale), **quelli da rivedere e le modifiche del testo in attesa** solo all'uscita vera, e dopo averlo chiesto
 *   ({@link contaIlLavoroNonArrivato}): partirebbero quando torna la rete, e cancellarli è una scelta. Alla pagina di accesso (la sessione scaduta, «Rientra»)
 *   restano: la coda è di chi li ha segnati, e partono quando rientra lui.
 *
 * Lo usano il menu dell'utente («Esci») e la pagina di accesso. Dove il browser non ha cache, operai o deposito, non fa niente.
 */
export interface BrowserDellUscita {
    caches?: Pick<CacheStorage, 'keys' | 'delete'>;
    serviceWorker?: { getRegistrations: () => Promise<readonly { scope: string; active?: { scriptURL: string } | null; unregister: () => Promise<boolean> }[]> };
    indexedDB?: IDBFactory | unknown;
}

const PREFISSO_DELLE_COPIE = 'kondomanager-sala-';

function browserVero(): BrowserDellUscita {
    return { caches: globalThis.caches, serviceWorker: globalThis.navigator?.serviceWorker as BrowserDellUscita['serviceWorker'], indexedDB: globalThis.indexedDB };
}

/** Il deposito, se c'è: un browser che non l'ha mai creato non lo crea adesso (dove si può sapere). */
async function ilDeposito(b: BrowserDellUscita): Promise<DepositoIndexedDb | null> {
    const fabbrica = b.indexedDB as (IDBFactory & { databases?: () => Promise<{ name?: string }[]> }) | undefined;
    if (!fabbrica) return null;
    try {
        if (typeof fabbrica.databases === 'function' && !(await fabbrica.databases()).some((d) => d.name === NOME_DEL_DEPOSITO)) return null;

        return await apriDepositoIndexedDb(fabbrica);
    } catch {
        return null;
    }
}

/** Quanti gesti della sala non sono arrivati al server su questo browser: in coda, e da rivedere. */
export async function contaIlLavoroNonArrivato(b: BrowserDellUscita = browserVero()): Promise<number> {
    const deposito = await ilDeposito(b);
    if (!deposito) return 0;
    let quanti = (await deposito.tuttiIGesti()).length;
    for (const chiave of (await deposito.chiavi()).filter((k) => k.endsWith(':da_rivedere'))) {
        quanti += ((await deposito.leggi<unknown[]>(chiave)) ?? []).length;
    }

    return quanti;
}

/**
 * Cancella il lavoro della sala tenuto nel browser: le copie e gli operai sempre, lo stato della sala sempre, i gesti non arrivati solo con `ancheLaCoda`
 * (l'uscita, dopo averlo chiesto). Non si ferma su un errore: cancella quello che può.
 */
export async function cancellaIlLavoroSenzaRete(opzioni: { ancheLaCoda: boolean }, b: BrowserDellUscita = browserVero()): Promise<void> {
    try {
        const nomi = (await b.caches?.keys()) ?? [];
        await Promise.all(nomi.filter((n) => n.startsWith(PREFISSO_DELLE_COPIE)).map((n) => b.caches!.delete(n)));
    } catch {
        // Senza cache non c'è niente da cancellare.
    }
    try {
        const registrazioni = (await b.serviceWorker?.getRegistrations()) ?? [];
        await Promise.all(registrazioni.filter((r) => /\/assemblee\/\d+\/$/.test(new URL(r.scope).pathname) || (r.active?.scriptURL ?? '').endsWith('/senza-rete'))
            .map((r) => r.unregister().catch(() => false)));
    } catch {
        // Senza operai non c'è niente da togliere.
    }
    const deposito = await ilDeposito(b);
    if (!deposito) return;
    try {
        const chiavi = await deposito.chiavi();
        if (opzioni.ancheLaCoda) {
            await deposito.togli((await deposito.tuttiIGesti()).map((v) => v.gesto));
            for (const chiave of chiavi) await deposito.cancella(chiave);

            return;
        }
        for (const chiave of chiavi.filter((k) => k.endsWith(':stato'))) await deposito.cancella(chiave);
    } catch {
        // Il deposito non risponde: resta com'è.
    }
}

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/* global CONFIGURAZIONE */

/**
 * L'operaio del browser (service worker) della sala di un'assemblea: tiene nel browser **solo** la pagina della sala e quella del verbale, così si riaprono
 * senza rete con il lavoro ritrovato. Scritto qui, senza librerie; il programma lo serve da `admin.gestionale.assemblee.sala.senza_rete` con la sua
 * configurazione in testa (`CONFIGURAZIONE`, da `app/Services/Assemblee/Sala/SalaSenzaRete.php`).
 *
 * - **All'installazione** tiene i file delle due pagine della versione compilata (la cache ha il nome della versione); **all'attivazione** cancella le cache
 *   delle versioni di prima di questa assemblea, prende le pagine aperte e le tiene di nuovo nella cache nuova (la copia non si perde con l'aggiornamento).
 * - **La pagina della sala (o del verbale)**: prima la rete. Con la rete risponde il server, e la copia si aggiorna; senza rete, o con il server spento, o se
 *   la rete non risponde entro pochi secondi, la copia tenuta. Senza una copia, una pagina che dice che cosa fare (il foglio della sala). **Chi non ha più
 *   l'accesso** non ha la copia: una risposta che rinvia all'accesso (la sessione scaduta, l'uscita) o che lo nega toglie la copia, e passa al browser. All'uscita
 *   dal programma le copie si cancellano tutte (`uscita.ts`).
 * - **I file della versione**: prima la copia, poi la rete.
 * - **Tutto il resto** (le altre pagine, le richieste di Inertia, i gesti, lo stato della sala) passa dalla rete come se l'operaio non ci fosse.
 * - **I messaggi della pagina**: `{tipo: 'tieni', indirizzo}` tiene la pagina quando ci si arriva con una visita di Inertia (senza una navigazione vera);
 *   `{tipo: 'dimentica', indirizzo}` cancella la copia di quella pagina (la sala chiusa: la copia del verbale resta, il verbale si scrive ancora);
 *   `{tipo: 'dimentica'}` cancella tutte le copie (il verbale registrato).
 *
 * Si prova con `operaio.test.ts`, che lo carica com'è e gli dà un browser finto.
 */
function operaioDellaSala(configurazione, ambiente) {
    const base = ambiente.location && ambiente.location.href ? ambiente.location.href : 'http://localhost/';
    const percorso = (indirizzo) => new URL(indirizzo, base).pathname;
    const chiave = (indirizzo) => {
        const u = new URL(indirizzo, base);

        return u.origin + u.pathname + u.search;
    };
    const pagine = configurazione.pagine.map((p) => configurazione.ambito + p);
    const file = new Set(configurazione.file.map(percorso));
    const attesa = (ms) => new Promise((risolvi) => setTimeout(risolvi, ms));

    const eUnaPagina = (richiesta) => richiesta.mode === 'navigate' && pagine.includes(percorso(richiesta.url));
    const eUnFile = (richiesta) => file.has(percorso(richiesta.url));
    /** Una pagina da tenere: arrivata intera dal nostro server, senza deviazioni (la sessione scaduta porta all'accesso). */
    const daTenere = (risposta) =>
        !!risposta && risposta.ok && !risposta.redirected && risposta.type === 'basic' && (risposta.headers.get('content-type') || '').includes('text/html');

    function senzaCopia() {
        return new Response(configurazione.senza_copia, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }

    async function installa() {
        const cache = await ambiente.caches.open(configurazione.cache);
        await cache.addAll(configurazione.file);
        await ambiente.skipWaiting();
    }

    /** Tiene la copia di una pagina della sala, se arriva intera dal nostro server. */
    async function tieni(indirizzo) {
        const risposta = await ambiente.fetch(indirizzo, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (daTenere(risposta)) {
            const cache = await ambiente.caches.open(configurazione.cache);
            await cache.put(chiave(indirizzo), risposta);
        }
    }

    async function attiva() {
        const nomi = await ambiente.caches.keys();
        await Promise.all(nomi.filter((n) => n.startsWith(configurazione.prefisso) && n !== configurazione.cache).map((n) => ambiente.caches.delete(n)));
        await ambiente.clients.claim();
        // Le pagine della sala aperte adesso si tengono di nuovo: la copia della versione di prima è appena stata cancellata.
        const aperte = typeof ambiente.clients.matchAll === 'function' ? await ambiente.clients.matchAll({ type: 'window' }) : [];
        await Promise.all(aperte.filter((c) => pagine.includes(percorso(c.url))).map((c) => tieni(c.url).catch(() => undefined)));
    }

    /** Una risposta che dice che l'utente non ha più l'accesso: il rinvio alla pagina di accesso, o il rifiuto. */
    const senzaAccesso = (risposta) => !!risposta && (risposta.type === 'opaqueredirect' || risposta.redirected || [401, 403, 419].includes(risposta.status));

    async function pagina(evento) {
        const richiesta = evento.request;
        const cache = await ambiente.caches.open(configurazione.cache);
        const copia = (await cache.match(chiave(richiesta.url))) || (await cache.match(chiave(richiesta.url), { ignoreSearch: true }));
        const rete = ambiente.fetch(richiesta).then(async (risposta) => {
            // Tenere la copia è un di più: se la cache del browser rifiuta (lo spazio, la versione di prima appena cancellata), la risposta del server
            // vale lo stesso. Prima un errore qui faceva rispondere «Senza rete» con il server acceso (21/11/2026).
            try {
                if (daTenere(risposta)) {
                    await cache.put(chiave(richiesta.url), risposta.clone());
                } else if (senzaAccesso(risposta)) {
                    // Chi non ha più l'accesso non ha la copia, nemmeno la volta dopo senza rete.
                    await cache.delete(chiave(richiesta.url));
                    await cache.delete(chiave(richiesta.url), { ignoreSearch: true });
                }
            } catch (errore) {
                // La copia resta quella di prima, o nessuna.
            }

            return risposta;
        });
        if (typeof evento.waitUntil === 'function') {
            evento.waitUntil(rete.catch(() => undefined));
        }
        if (!copia) {
            return rete.catch(() => senzaCopia());
        }
        // Il server spento dietro un proxy risponde 502 o 503: vale la copia.
        const dallaRete = rete.then((risposta) => (risposta.status >= 500 ? copia : risposta));

        return Promise.race([dallaRete, attesa(configurazione.attesa_ms).then(() => copia)]).catch(() => copia);
    }

    async function dalFile(richiesta) {
        const cache = await ambiente.caches.open(configurazione.cache);
        const copia = await cache.match(richiesta);
        if (copia) {
            return copia;
        }
        const risposta = await ambiente.fetch(richiesta);
        if (risposta && risposta.ok) {
            await cache.put(richiesta, risposta.clone());
        }

        return risposta;
    }

    async function messaggio(evento) {
        const dati = evento.data || {};
        if (dati.tipo === 'tieni' && typeof dati.indirizzo === 'string' && pagine.includes(percorso(dati.indirizzo))) {
            await tieni(dati.indirizzo);
        }
        // Con l'indirizzo dimentica solo quella pagina (la sala chiusa: il verbale si scrive ancora, e la sua copia resta); senza, tutte le copie.
        if (dati.tipo === 'dimentica' && typeof dati.indirizzo === 'string') {
            const cache = await ambiente.caches.open(configurazione.cache);
            await cache.delete(chiave(dati.indirizzo));
            await cache.delete(chiave(dati.indirizzo), { ignoreSearch: true });
        } else if (dati.tipo === 'dimentica') {
            const nomi = await ambiente.caches.keys();
            await Promise.all(nomi.filter((n) => n.startsWith(configurazione.prefisso)).map((n) => ambiente.caches.delete(n)));
        }
    }

    function ascolta() {
        ambiente.addEventListener('install', (evento) => evento.waitUntil(installa()));
        ambiente.addEventListener('activate', (evento) => evento.waitUntil(attiva()));
        ambiente.addEventListener('fetch', (evento) => {
            if (evento.request.method !== 'GET') {
                return;
            }
            if (eUnaPagina(evento.request)) {
                evento.respondWith(pagina(evento));
            } else if (eUnFile(evento.request)) {
                evento.respondWith(dalFile(evento.request));
            }
        });
        ambiente.addEventListener('message', (evento) => evento.waitUntil(messaggio(evento)));
    }

    return { installa, attiva, pagina, dalFile, messaggio, ascolta, eUnaPagina, eUnFile, tieni };
}

if (typeof CONFIGURAZIONE !== 'undefined' && typeof self !== 'undefined' && typeof self.addEventListener === 'function') {
    operaioDellaSala(CONFIGURAZIONE, self).ascolta();
}

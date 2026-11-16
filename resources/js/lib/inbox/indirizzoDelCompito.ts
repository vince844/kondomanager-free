// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Il collegamento di un compito dell'agenda (`meta.action_url`) senza l'indirizzo del sito con cui è stato scritto: come `IndirizzoDelCompito` sul server, se
 * ne tengono il percorso, la query e l'ancora. I compiti salvati prima con l'indirizzo intero («https://kondomanager-free.test/…», «http://localhost/…»)
 * portano così alla pagina del sito di oggi; quelli delle quote non assegnate salvati con «/gestionale/…» portano a «/admin/gestionale/…». Il percorso
 * comincia con una barra sola: «https://sito/\altro.example» ha il percorso «//altro.example», che il browser aprirebbe come un altro sito.
 */
export function percorsoDelCompito(indirizzo: string | null | undefined): string | null {
    const testo = (indirizzo ?? '').trim();
    if (!testo) return null;
    let url: URL;
    try {
        url = new URL(testo, 'http://sito.invalid');
    } catch {
        return null;
    }
    const radice = url.pathname.replace(/^\/+/, '/');
    const percorso = radice.startsWith('/gestionale/') ? `/admin${radice}` : radice;

    return `${percorso}${url.search}${url.hash}`;
}

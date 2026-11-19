// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le etichette della pubblicazione e della privacy di segnalazioni, comunicazioni e documenti
 * (Coda 306).
 *
 * Lo stato di pubblicazione e la privacy sono due indicazioni distinte, e le pagine le dicono
 * separate: una privata pubblicata e approvata è «Pubblicata» e, a parte, «Privata»; una bozza
 * è «Bozza». Sono due domande diverse:
 *
 * - **la pubblicazione** dice se i condòmini la vedono già: «In attesa di approvazione» finché non è
 *   approvata, «Bozza» se è approvata ma non pubblicata, «Pubblicata» se è tutte e due;
 * - **la privacy** dice a chi è indirizzata, e la decide `is_private` (decisione Q1 del 17/10/2026):
 *   «Privata» è un'indicazione a parte, che si aggiunge allo stato.
 *
 * I documenti non salvano mai `is_private`: per loro c'è solo la pubblicazione, «Bozza» o
 * «Pubblicato».
 */

export type StatoPubblicazione = 'pending' | 'draft' | 'published' | 'scheduled';

export type EntitaConPrivacy = 'segnalazioni' | 'comunicazioni';

/** Lo stato di pubblicazione dai due flag del server (che arrivano come booleani o come 0/1) e l'eventuale data programmata. */
export function statoPubblicazione(isPublished: unknown, isApproved: unknown, dataProgrammata?: unknown): StatoPubblicazione {
    if (!isApproved) {
        return 'pending';
    }

    if (isPublished) {
        return 'published';
    }

    if (typeof dataProgrammata === 'string' && dataProgrammata.trim() !== '') {
        return 'scheduled';
    }

    return 'draft';
}

/** La chiave di traduzione dello stato di pubblicazione. */
export function etichettaStatoPubblicazione(entita: EntitaConPrivacy, isPublished: unknown, isApproved: unknown, dataProgrammata?: unknown): string {
    return `${entita}.publication.${statoPubblicazione(isPublished, isApproved, dataProgrammata)}`;
}

/** La chiave dell'indicazione «Privata», o `null` se l'oggetto non è privato. */
export function etichettaPrivacy(entita: EntitaConPrivacy, isPrivate: unknown): string | null {
    return isPrivate ? `${entita}.privacy.private` : null;
}

/** I colori del pallino dello stato: verde pubblicata, ambra bozza, grigio in attesa, azzurro programmata. */
export function colorePubblicazione(stato: StatoPubblicazione): string {
    return { published: 'bg-emerald-500', draft: 'bg-amber-500', pending: 'bg-slate-400', scheduled: 'bg-sky-500' }[stato];
}

/**
 * Il giorno e l'ora da mostrare per una comunicazione programmata, tagliando la stringa ricevuta.
 * L'ora è già nel fuso dell'installazione e la funzione non fa conti con i fusi.
 */
export function giornoEOraProgrammati(valore: unknown): { date: string; time: string } | null {
    if (typeof valore !== 'string') {
        return null;
    }

    const match = valore.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})$/);
    if (!match) {
        return null;
    }

    const [, anno, mese, giorno, ora] = match;
    return {
        date: `${giorno}/${mese}/${anno}`,
        time: ora,
    };
}

export type EntitaPubblicabile = EntitaConPrivacy | 'documenti';

/**
 * La riga sotto la tendina «Pubblicata» del modulo di modifica, su un oggetto non ancora approvato.
 *
 * La modifica non approva (decisione 127.2: l'approvazione cambia solo dall'interruttore, che avvisa
 * il creatore): su un oggetto in moderazione «Pubblicata» da sola non lo mostra ai condòmini e non
 * fa partire nessun avviso. La riga lo dice; a chi può approvare indica dove (lo scudo nell'elenco,
 * che vede solo chi ha il permesso di approvare quell'entità), a chi non può dice chi lo approva.
 * Restituisce la chiave della riga, o `null` se l'oggetto è approvato.
 */
export function rigaServeApprovazione(entita: EntitaPubblicabile, isApproved: unknown, puoApprovare: boolean): string | null {
    if (isApproved) {
        return null;
    }

    return puoApprovare ? `${entita}.publication.needs_approval` : `${entita}.publication.waiting_approver`;
}

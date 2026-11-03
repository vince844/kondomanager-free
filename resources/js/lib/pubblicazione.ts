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

export type StatoPubblicazione = 'pending' | 'draft' | 'published';

export type EntitaConPrivacy = 'segnalazioni' | 'comunicazioni';

/** Lo stato di pubblicazione dai due flag del server (che arrivano come booleani o come 0/1). */
export function statoPubblicazione(isPublished: unknown, isApproved: unknown): StatoPubblicazione {
    if (!isApproved) {
        return 'pending';
    }

    return isPublished ? 'published' : 'draft';
}

/** La chiave di traduzione dello stato di pubblicazione. */
export function etichettaStatoPubblicazione(entita: EntitaConPrivacy, isPublished: unknown, isApproved: unknown): string {
    return `${entita}.publication.${statoPubblicazione(isPublished, isApproved)}`;
}

/** La chiave dell'indicazione «Privata», o `null` se l'oggetto non è privato. */
export function etichettaPrivacy(entita: EntitaConPrivacy, isPrivate: unknown): string | null {
    return isPrivate ? `${entita}.privacy.private` : null;
}

/** I colori del pallino dello stato: verde pubblicata, ambra bozza, grigio in attesa. */
export function colorePubblicazione(stato: StatoPubblicazione): string {
    return { published: 'bg-emerald-500', draft: 'bg-amber-500', pending: 'bg-slate-400' }[stato];
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

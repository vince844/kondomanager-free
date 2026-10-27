/**
 * Ciò che la scheda dell'amministratore e quella del condòmino dicono sulla privacy di una
 * segnalazione (Code 304 e 306).
 */

import { statoPubblicazione } from '@/lib/pubblicazione';

type Persona = {
    user?: { name?: string | null } | null;
    anagrafica?: { nome?: string | null } | null;
} | null | undefined;

/**
 * Il nome con cui i condòmini vedono l'autore: quello dell'anagrafica, e se manca quello
 * dell'utente. È lo stesso ripiego dei commenti (`CommentoResource`, `Commento.vue`) e della
 * risorsa del portale; `null` se non c'è nessuno dei due.
 */
export function nomeAutore(createdBy: Persona): string | null {
    const nome = createdBy?.anagrafica?.nome || createdBy?.user?.name;

    return nome ? String(nome) : null;
}

/**
 * «Rendi pubblica» si offre solo su una privata, e solo a chi può approvare le segnalazioni
 * (lo stesso permesso che il server chiede: `SegnalazionePolicy::rendiPubblica`).
 */
export function offreRendiPubblica(segnalazione: { is_private?: unknown } | null | undefined, puoApprovare: boolean): boolean {
    return Boolean(segnalazione?.is_private) && puoApprovare;
}

/**
 * Gli argomenti della frase di conferma (decisione 129.2): il condominio i cui condòmini la
 * vedranno e il nome con cui la vedranno. Se un dato manca si dice in modo generico, invece di
 * lasciare un segnaposto vuoto nella frase.
 */
export function argomentiConfermaRendiPubblica(
    segnalazione: { created_by?: Persona; condominio?: { option?: { label?: string | null } | null; full?: { nome?: string | null } | null } | null },
    ripieghi: { condominio: string; autore: string },
): { condominio: string; autore: string } {
    const condominio = segnalazione.condominio?.option?.label || segnalazione.condominio?.full?.nome || ripieghi.condominio;

    return {
        condominio: String(condominio),
        autore: nomeAutore(segnalazione.created_by) ?? ripieghi.autore,
    };
}

/**
 * La frase di conferma di «Rendi pubblica». Il comando toglie la privacy, non approva e non pubblica
 * (decisione 127.2: l'approvazione cambia solo dall'interruttore): «la vedranno tutti i condòmini» va
 * detto così solo se è già pubblicata e approvata; altrimenti la frase dice che la vedranno quando lo
 * sarà. La stessa regola della mail all'autore e del messaggio dopo il comando.
 */
export function chiaveConfermaRendiPubblica(segnalazione: { is_published?: unknown; is_approved?: unknown } | null | undefined): string {
    return statoPubblicazione(segnalazione?.is_published, segnalazione?.is_approved) === 'published'
        ? 'segnalazioni.privacy.make_public_description'
        : 'segnalazioni.privacy.make_public_description_pending';
}

/**
 * La frase in più della conferma di «Rendi pubblica» quando l'autore, dopo il comando, non la vedrà più
 * dal portale (non è più nel palazzo, e il comando toglie la privacy e i collegamenti). Il fatto lo
 * calcola il server con la regola della scheda (`rendi_pubblica.autore_perde_la_scheda`); la pagina lo
 * dice soltanto. `null` se l'autore la vedrà ancora.
 */
export function chiaveAutorePerdeLaScheda(autorePerdeLaScheda: unknown): string | null {
    return autorePerdeLaScheda ? 'segnalazioni.privacy.make_public_author_loses_access' : null;
}

/** Le chiavi del riquadro «Chi la vede» nella scheda del condòmino: la guida segue `is_private`. */
export function guidaPrivacy(isPrivate: unknown): { title: string; description: string } {
    return {
        title: 'segnalazioni.privacy.guide_title',
        description: isPrivate ? 'segnalazioni.privacy.guide_private' : 'segnalazioni.privacy.guide_not_private',
    };
}

/**
 * Il riquadro «Chi la vede» della scheda del condòmino, secondo lo stato. La scheda di una segnalazione
 * non ancora pubblicata e approvata la apre solo il suo autore (Code 263 e 198): lì «la vedono i
 * condòmini» non è vero, e la guida dice che per ora non la vede nessun altro, a parte
 * l'amministratore, e chi la vedrà quando sarà pubblicata e approvata. Pubblicata e approvata, la guida
 * segue `is_private` (`guidaPrivacy`).
 */
export function guidaChiLaVede(
    segnalazione: { is_private?: unknown; is_published?: unknown; is_approved?: unknown } | null | undefined,
): { title: string; description: string } {
    if (statoPubblicazione(segnalazione?.is_published, segnalazione?.is_approved) !== 'published') {
        return {
            title: 'segnalazioni.privacy.guide_title',
            description: segnalazione?.is_private
                ? 'segnalazioni.privacy.guide_private_pending'
                : 'segnalazioni.privacy.guide_not_private_pending',
        };
    }

    return guidaPrivacy(segnalazione?.is_private);
}
